<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Events\VersionedDomainEvent;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Outbox\TransactionalOutbox;
use App\Models\EnterpriseGoodsReceipt;
use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\EnterprisePurchaseInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseInvoiceHandoffManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_invoices.view';

    private const MANAGE_PERMISSION = 'procurement.purchase_invoices.manage';

    private const HANDOFF_PERMISSION = 'procurement.purchase_invoices.handoff';

    private const STATUSES = ['draft', 'handed_off', 'cancelled'];

    private const EVENT_TYPE = 'ProcurementPurchaseInvoiceHandedOff';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly ModuleRegistry $modules,
        private readonly TransactionalOutbox $outbox,
        private readonly ProcurementCompletionManager $completion,
    ) {}

    /**
     * @param  array{q?:string,status?:string,supplier_id?:int,goods_receipt_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseInvoice>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'goods_receipt_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return $this->visibleQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['supplier_id'] ?? null,
                fn (Builder $query, mixed $supplierId): Builder => $query->where('supplier_id', (int) $supplierId),
            )
            ->when(
                $validated['goods_receipt_id'] ?? null,
                fn (Builder $query, mixed $receiptId): Builder => $query->where('goods_receipt_id', (int) $receiptId),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where(function (Builder $nested) use ($term): void {
                    $nested
                        ->where('invoice_number', 'like', $term.'%')
                        ->orWhere('supplier_invoice_number', 'like', $term.'%');
                }),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
    ): EnterprisePurchaseInvoice {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->findVisible($scope, $invoiceId)->load([
            'goodsReceipt:id,receipt_number,status',
            'supplier:id,code,name_en,name_ar,status',
            'lines' => fn ($query) => $query->orderBy('line_no'),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function createFromPostedReceipt(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
        array $attributes,
    ): EnterprisePurchaseInvoice {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $data = $this->validatedInvoice($attributes);

        return DB::transaction(function () use ($actor, $scope, $receiptId, $data): EnterprisePurchaseInvoice {
            $receipt = $this->visibleReceiptQuery($scope)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status !== 'posted') {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => [
                        sprintf(
                            'Goods receipt %s must be posted before it can source a purchase invoice.',
                            $receipt->receipt_number,
                        ),
                    ],
                ]);
            }

            $duplicate = $this->visibleQuery($scope)
                ->where('supplier_id', $receipt->supplier_id)
                ->where('supplier_invoice_number', $data['supplier_invoice_number'])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'supplier_invoice_number' => [
                        'This supplier invoice number is already registered in the authorized company.',
                    ],
                ]);
            }

            $requestedLineIds = collect($data['lines'])
                ->pluck('goods_receipt_line_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $receiptLines = $receipt->lines()
                ->whereIn('id', $requestedLineIds)
                ->get()
                ->keyBy('id');

            if ($receiptLines->count() !== count($data['lines'])) {
                throw ValidationException::withMessages([
                    'lines' => ['Every invoice line must reference a line on the selected posted goods receipt.'],
                ]);
            }

            $returnedTotals = $this->postedReturnTotals($receipt->id);
            $invoicedTotals = $this->handedOffInvoiceTotals($receipt->id);
            $totalAmount = 0.0;

            foreach ($data['lines'] as $line) {
                /** @var EnterpriseGoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get((int) $line['goods_receipt_line_id']);
                if ($receiptLine->unit_price === null) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'GRN line %d has no unit price and cannot source an invoice handoff.',
                                $receiptLine->line_no,
                            ),
                        ],
                    ]);
                }

                $this->assertInvoiceQuantityAvailable(
                    $receiptLine,
                    $line['quantity'],
                    $returnedTotals,
                    $invoicedTotals,
                );

                $invoiceUnitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null
                    ? (float) $line['unit_price']
                    : (float) $receiptLine->unit_price;
                $totalAmount += round($invoiceUnitPrice * (float) $line['quantity'], 3);
            }

            $publicId = (string) Str::uuid();
            $invoice = EnterprisePurchaseInvoice::query()->create([
                'public_id' => $publicId,
                'invoice_number' => 'PINV-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 11)),
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'goods_receipt_id' => $receipt->id,
                'source_receipt_number' => $receipt->receipt_number,
                'source_order_number' => $receipt->source_order_number,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'supplier_id' => $receipt->supplier_id,
                'supplier_code' => $receipt->supplier_code,
                'supplier_name_en' => $receipt->supplier_name_en,
                'supplier_name_ar' => $receipt->supplier_name_ar,
                'currency_code' => $receipt->currency_code,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'total_amount' => round($totalAmount, 3),
                'status' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($data['lines'] as $index => $line) {
                /** @var EnterpriseGoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get((int) $line['goods_receipt_line_id']);
                $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null
                    ? (float) $line['unit_price']
                    : (float) $receiptLine->unit_price;
                $quantity = (float) $line['quantity'];

                $invoice->lines()->create([
                    'goods_receipt_line_id' => $receiptLine->id,
                    'purchase_order_line_id' => $receiptLine->purchase_order_line_id,
                    'line_no' => $index + 1,
                    'item_code' => $receiptLine->item_code,
                    'description' => $receiptLine->description,
                    'received_quantity' => $receiptLine->received_quantity,
                    'invoice_quantity' => $line['quantity'],
                    'unit' => $receiptLine->unit,
                    'unit_price' => number_format($unitPrice, 3, '.', ''),
                    'line_total' => round($unitPrice * $quantity, 3),
                ]);
            }

            $this->audit->record(
                'enterprise.purchase_invoice.created',
                $actor,
                $invoice,
                null,
                [
                    'invoice_number' => $invoice->invoice_number,
                    'supplier_invoice_number' => $invoice->supplier_invoice_number,
                    'goods_receipt_id' => $receipt->id,
                    'source_receipt_number' => $receipt->receipt_number,
                    'source_order_number' => $receipt->source_order_number,
                    'supplier_id' => $invoice->supplier_id,
                    'currency_code' => $invoice->currency_code,
                    'total_amount' => $invoice->total_amount,
                    'status' => $invoice->status,
                    'organization_id' => $invoice->organization_id,
                    'company_id' => $invoice->company_id,
                    'store_id' => $invoice->store_id,
                    'line_count' => count($data['lines']),
                ],
            );

            $this->completion->syncMatchForInvoice($invoice->id);

            return $invoice->load('lines');
        }, 3);
    }

    public function handoff(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
    ): EnterprisePurchaseInvoice {
        $scope = $this->authorizedScope($actor, $context, self::HANDOFF_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $invoiceId): EnterprisePurchaseInvoice {
            $invoice = $this->lockVisible($scope, $invoiceId);
            if ($invoice->status === 'handed_off') {
                return $invoice->refresh()->load('lines');
            }

            $this->requireStatus($invoice, ['draft'], 'handed off');
            $this->completion->assertMatchAllowsHandoff($invoice->id);

            $receipt = $this->visibleReceiptQuery($scope)
                ->whereKey($invoice->goods_receipt_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status !== 'posted') {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => [
                        sprintf(
                            'Goods receipt %s is no longer eligible for invoice handoff.',
                            $receipt->receipt_number,
                        ),
                    ],
                ]);
            }

            $lines = $invoice->lines()->orderBy('line_no')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['A purchase invoice must contain at least one line.'],
                ]);
            }

            $receiptLines = $receipt->lines()->get()->keyBy('id');
            $returnedTotals = $this->postedReturnTotals($receipt->id);
            $invoicedTotals = $this->handedOffInvoiceTotals($receipt->id);

            foreach ($lines as $line) {
                /** @var EnterpriseGoodsReceiptLine|null $receiptLine */
                $receiptLine = $receiptLines->get((int) $line->goods_receipt_line_id);
                if ($receiptLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => ['An invoice line no longer belongs to its source goods receipt.'],
                    ]);
                }

                $this->assertInvoiceQuantityAvailable(
                    $receiptLine,
                    $line->invoice_quantity,
                    $returnedTotals,
                    $invoicedTotals,
                );
            }

            $event = VersionedDomainEvent::create(
                type: self::EVENT_TYPE,
                version: 1,
                scope: [
                    'organization_id' => $scope->organizationId,
                    'company_id' => $scope->companyId,
                    'store_id' => $scope->storeId,
                ],
                payload: [
                    'purchase_invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'supplier_invoice_number' => $invoice->supplier_invoice_number,
                    'supplier_id' => $invoice->supplier_id,
                    'goods_receipt_id' => $invoice->goods_receipt_id,
                    'source_receipt_number' => $invoice->source_receipt_number,
                    'source_order_number' => $invoice->source_order_number,
                    'currency_code' => $invoice->currency_code,
                    'invoice_date' => (string) $invoice->getRawOriginal('invoice_date'),
                    'due_date' => $invoice->getRawOriginal('due_date') === null
                        ? null
                        : (string) $invoice->getRawOriginal('due_date'),
                    'total_amount' => (string) $invoice->total_amount,
                    'lines' => $lines->map(static fn ($line): array => [
                        'purchase_invoice_line_id' => $line->id,
                        'goods_receipt_line_id' => $line->goods_receipt_line_id,
                        'purchase_order_line_id' => $line->purchase_order_line_id,
                        'item_code' => $line->item_code,
                        'quantity' => (string) $line->invoice_quantity,
                        'unit' => $line->unit,
                        'unit_price' => (string) $line->unit_price,
                        'line_total' => (string) $line->line_total,
                    ])->all(),
                ],
            );

            $this->outbox->enqueue($event);

            $before = $invoice->toArray();
            $invoice->forceFill([
                'status' => 'handed_off',
                'outbox_event_id' => $event->id,
                'handed_off_by' => $actor->id,
                'handed_off_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_invoice.handed_off',
                $actor,
                $invoice,
                $before,
                [
                    ...$invoice->fresh()->toArray(),
                    'event_type' => self::EVENT_TYPE,
                    'event_id' => $event->id,
                    'line_count' => $lines->count(),
                ],
            );

            return $invoice->refresh()->load('lines');
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $invoiceId,
    ): EnterprisePurchaseInvoice {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $invoiceId): EnterprisePurchaseInvoice {
            $invoice = $this->lockVisible($scope, $invoiceId);
            if ($invoice->status === 'cancelled') {
                return $invoice->refresh();
            }

            if ($invoice->status === 'handed_off') {
                throw ValidationException::withMessages([
                    'status' => ['A handed-off purchase invoice is immutable and cannot be cancelled silently.'],
                ]);
            }

            $this->requireStatus($invoice, ['draft'], 'cancelled');
            $before = $invoice->toArray();
            $invoice->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_invoice.cancelled',
                $actor,
                $invoice,
                $before,
                $invoice->fresh()->toArray(),
            );

            return $invoice->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        $this->assertProcurementEnabled();
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    private function assertProcurementEnabled(): void
    {
        abort_unless(
            $this->modules->isEnabled('procurement'),
            404,
            'Procurement module is disabled.',
        );
    }

    /** @return Builder<EnterprisePurchaseInvoice> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseInvoice::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterpriseGoodsReceipt> */
    private function visibleReceiptQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseGoodsReceipt::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function findVisible(
        EnterpriseDataScope $scope,
        int $invoiceId,
    ): EnterprisePurchaseInvoice {
        return $this->visibleQuery($scope)->whereKey($invoiceId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $invoiceId,
    ): EnterprisePurchaseInvoice {
        return $this->visibleQuery($scope)
            ->whereKey($invoiceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterprisePurchaseInvoice $invoice,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $invoice->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Purchase invoice %s cannot be %s from status %s.',
                    $invoice->invoice_number,
                    $action,
                    $invoice->status,
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     supplier_invoice_number:string,
     *     invoice_date:string,
     *     due_date?:string|null,
     *     lines:list<array{goods_receipt_line_id:int,quantity:mixed,unit_price?:mixed}>
     * }
     */
    private function validatedInvoice(array $attributes): array
    {
        return Validator::make($attributes, [
            'supplier_invoice_number' => ['required', 'string', 'max:96'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ])->validate();
    }

    /**
     * @param  Collection<int|string, mixed>  $returnedTotals
     * @param  Collection<int|string, mixed>  $invoicedTotals
     */
    private function assertInvoiceQuantityAvailable(
        EnterpriseGoodsReceiptLine $receiptLine,
        mixed $quantity,
        Collection $returnedTotals,
        Collection $invoicedTotals,
    ): void {
        $received = $this->quantityUnits($receiptLine->received_quantity);
        $returned = $this->quantityUnits($returnedTotals->get($receiptLine->id, 0));
        $invoiced = $this->quantityUnits($invoicedTotals->get($receiptLine->id, 0));
        $requested = $this->quantityUnits($quantity);
        $available = max(0, $received - $returned - $invoiced);

        if ($requested > $available) {
            throw ValidationException::withMessages([
                'lines' => [
                    sprintf(
                        'Invoice quantity for GRN line %d exceeds net invoiceable quantity %s.',
                        $receiptLine->line_no,
                        number_format($available / 1000, 3, '.', ''),
                    ),
                ],
            ]);
        }
    }

    /** @return Collection<int|string, mixed> */
    private function postedReturnTotals(int $receiptId): Collection
    {
        return DB::table('enterprise_purchase_return_lines as return_lines')
            ->join(
                'enterprise_purchase_returns as purchase_returns',
                'purchase_returns.id',
                '=',
                'return_lines.purchase_return_id',
            )
            ->where('purchase_returns.goods_receipt_id', $receiptId)
            ->where('purchase_returns.status', 'posted')
            ->groupBy('return_lines.goods_receipt_line_id')
            ->selectRaw(
                'return_lines.goods_receipt_line_id as goods_receipt_line_id, '
                .'SUM(return_lines.returned_quantity) as returned_total',
            )
            ->pluck('returned_total', 'goods_receipt_line_id');
    }

    /** @return Collection<int|string, mixed> */
    private function handedOffInvoiceTotals(int $receiptId): Collection
    {
        return DB::table('enterprise_purchase_invoice_lines as invoice_lines')
            ->join(
                'enterprise_purchase_invoices as purchase_invoices',
                'purchase_invoices.id',
                '=',
                'invoice_lines.purchase_invoice_id',
            )
            ->where('purchase_invoices.goods_receipt_id', $receiptId)
            ->where('purchase_invoices.status', 'handed_off')
            ->groupBy('invoice_lines.goods_receipt_line_id')
            ->selectRaw(
                'invoice_lines.goods_receipt_line_id as goods_receipt_line_id, '
                .'SUM(invoice_lines.invoice_quantity) as invoice_total',
            )
            ->pluck('invoice_total', 'goods_receipt_line_id');
    }

    private function quantityUnits(mixed $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }
}
