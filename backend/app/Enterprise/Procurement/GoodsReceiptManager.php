<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseGoodsReceipt;
use App\Models\EnterprisePurchaseOrder;
use App\Models\EnterprisePurchaseOrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GoodsReceiptManager
{
    private const VIEW_PERMISSION = 'procurement.goods_receipts.view';

    private const MANAGE_PERMISSION = 'procurement.goods_receipts.manage';

    private const POST_PERMISSION = 'procurement.goods_receipts.post';

    private const STATUSES = ['draft', 'posted', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly ModuleRegistry $modules,
        private readonly GoodsReceiptInventoryHandoff $inventory,
    ) {}

    /**
     * @param  array{q?:string,status?:string,purchase_order_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterpriseGoodsReceipt>
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
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = str_replace(['%', '_'], '', trim((string) ($validated['q'] ?? '')));

        return $this->visibleQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['purchase_order_id'] ?? null,
                fn (Builder $query, int $orderId): Builder => $query->where('purchase_order_id', $orderId),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where('receipt_number', 'like', $term.'%'),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
    ): EnterpriseGoodsReceipt {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->findVisible($scope, $receiptId)->load([
            'purchaseOrder:id,order_number,status',
            'supplier:id,code,name_en,name_ar,status',
            'lines' => fn ($query) => $query->orderBy('line_no'),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function createFromIssuedOrder(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
        array $attributes,
    ): EnterpriseGoodsReceipt {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $data = $this->validatedReceipt($attributes);

        return DB::transaction(function () use ($actor, $scope, $orderId, $data): EnterpriseGoodsReceipt {
            $order = $this->visibleOrderQuery($scope)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'issued') {
                throw ValidationException::withMessages([
                    'purchase_order_id' => [
                        sprintf(
                            'Purchase order %s must be issued before goods can be received.',
                            $order->order_number,
                        ),
                    ],
                ]);
            }

            $requestedLineIds = collect($data['lines'])
                ->pluck('purchase_order_line_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $orderLines = $order->lines()
                ->whereIn('id', $requestedLineIds)
                ->get()
                ->keyBy('id');

            if ($orderLines->count() !== count($data['lines'])) {
                throw ValidationException::withMessages([
                    'lines' => ['Every goods receipt line must reference a line on the selected purchase order.'],
                ]);
            }

            $warehouseIds = collect($data['lines'])
                ->pluck('warehouse_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $visibleWarehouseCount = Warehouse::query()
                ->whereIn('id', $warehouseIds->all())
                ->where('store_id', $scope->storeId)
                ->where('is_active', true)
                ->count();

            if ($visibleWarehouseCount !== $warehouseIds->count()) {
                throw ValidationException::withMessages([
                    'lines' => ['Every receiving warehouse must be active in the authorized store.'],
                ]);
            }

            foreach ($data['lines'] as $line) {
                /** @var EnterprisePurchaseOrderLine $orderLine */
                $orderLine = $orderLines->get((int) $line['purchase_order_line_id']);
                if ($this->quantityUnits($line['quantity']) > $this->quantityUnits($orderLine->quantity)) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'Receipt quantity for PO line %d cannot exceed its ordered quantity.',
                                $orderLine->line_no,
                            ),
                        ],
                    ]);
                }
            }

            $publicId = (string) Str::uuid();
            $receipt = EnterpriseGoodsReceipt::query()->create([
                'public_id' => $publicId,
                'receipt_number' => 'GRN-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                'purchase_order_id' => $order->id,
                'source_order_number' => $order->order_number,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'supplier_id' => $order->supplier_id,
                'supplier_code' => $order->supplier_code,
                'supplier_name_en' => $order->supplier_name_en,
                'supplier_name_ar' => $order->supplier_name_ar,
                'currency_code' => $order->currency_code,
                'status' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($data['lines'] as $index => $line) {
                /** @var EnterprisePurchaseOrderLine $orderLine */
                $orderLine = $orderLines->get((int) $line['purchase_order_line_id']);
                $receipt->lines()->create([
                    'purchase_order_line_id' => $orderLine->id,
                    'line_no' => $index + 1,
                    'item_code' => $orderLine->item_code,
                    'description' => $orderLine->description,
                    'ordered_quantity' => $orderLine->quantity,
                    'received_quantity' => $line['quantity'],
                    'unit' => $orderLine->unit,
                    'unit_price' => $orderLine->unit_price,
                    'warehouse_id' => (int) $line['warehouse_id'],
                ]);
            }

            $this->audit->record(
                'enterprise.goods_receipt.created',
                $actor,
                $receipt,
                null,
                [
                    'receipt_number' => $receipt->receipt_number,
                    'purchase_order_id' => $order->id,
                    'source_order_number' => $order->order_number,
                    'supplier_id' => $receipt->supplier_id,
                    'status' => $receipt->status,
                    'organization_id' => $receipt->organization_id,
                    'company_id' => $receipt->company_id,
                    'store_id' => $receipt->store_id,
                    'line_count' => count($data['lines']),
                ],
            );

            return $receipt->load('lines');
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
    ): EnterpriseGoodsReceipt {
        $scope = $this->authorizedScope($actor, $context, self::POST_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $receiptId): EnterpriseGoodsReceipt {
            $receipt = $this->lockVisible($scope, $receiptId);
            if ($receipt->status === 'posted') {
                return $receipt->refresh()->load('lines');
            }

            $this->requireStatus($receipt, ['draft'], 'posted');

            $order = $this->visibleOrderQuery($scope)
                ->whereKey($receipt->purchase_order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'issued') {
                throw ValidationException::withMessages([
                    'purchase_order_id' => [
                        sprintf(
                            'Purchase order %s is no longer open for goods receipt posting.',
                            $order->order_number,
                        ),
                    ],
                ]);
            }

            $lines = $receipt->lines()
                ->orderBy('warehouse_id')
                ->orderBy('line_no')
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['A goods receipt must contain at least one line.'],
                ]);
            }

            $orderLines = $order->lines()->get()->keyBy('id');
            $postedTotals = DB::table('enterprise_goods_receipt_lines as receipt_lines')
                ->join(
                    'enterprise_goods_receipts as receipts',
                    'receipts.id',
                    '=',
                    'receipt_lines.goods_receipt_id',
                )
                ->where('receipts.purchase_order_id', $order->id)
                ->where('receipts.status', 'posted')
                ->groupBy('receipt_lines.purchase_order_line_id')
                ->selectRaw(
                    'receipt_lines.purchase_order_line_id, SUM(receipt_lines.received_quantity) as received_total',
                )
                ->pluck('received_total', 'receipt_lines.purchase_order_line_id');

            foreach ($lines as $line) {
                /** @var EnterprisePurchaseOrderLine|null $orderLine */
                $orderLine = $orderLines->get((int) $line->purchase_order_line_id);
                if ($orderLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => ['A goods receipt line no longer belongs to its purchase order.'],
                    ]);
                }

                $alreadyReceived = $postedTotals->get($orderLine->id, 0);
                $nextReceived = $this->quantityUnits($alreadyReceived)
                    + $this->quantityUnits($line->received_quantity);

                if ($nextReceived > $this->quantityUnits($orderLine->quantity)) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'Posting would over-receive PO line %d. Ordered %s; already received %s; this receipt %s.',
                                $orderLine->line_no,
                                $orderLine->quantity,
                                $alreadyReceived,
                                $line->received_quantity,
                            ),
                        ],
                    ]);
                }
            }

            foreach ($lines as $line) {
                $this->inventory->receive($actor, $scope->storeId, $receipt, $line);
            }

            $before = $receipt->toArray();
            $receipt->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.goods_receipt.posted',
                $actor,
                $receipt,
                $before,
                [
                    ...$receipt->fresh()->toArray(),
                    'line_count' => $lines->count(),
                ],
            );

            return $receipt->refresh()->load('lines');
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
    ): EnterpriseGoodsReceipt {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $receiptId): EnterpriseGoodsReceipt {
            $receipt = $this->lockVisible($scope, $receiptId);
            if ($receipt->status === 'cancelled') {
                return $receipt->refresh();
            }

            if ($receipt->status === 'posted') {
                throw ValidationException::withMessages([
                    'status' => [
                        'A posted goods receipt cannot be cancelled silently; use an explicit inventory return or correction workflow.',
                    ],
                ]);
            }

            $this->requireStatus($receipt, ['draft'], 'cancelled');
            $before = $receipt->toArray();
            $receipt->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.goods_receipt.cancelled',
                $actor,
                $receipt,
                $before,
                $receipt->fresh()->toArray(),
            );

            return $receipt->refresh();
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

    /** @return Builder<EnterpriseGoodsReceipt> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseGoodsReceipt::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterprisePurchaseOrder> */
    private function visibleOrderQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseOrder::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function findVisible(
        EnterpriseDataScope $scope,
        int $receiptId,
    ): EnterpriseGoodsReceipt {
        return $this->visibleQuery($scope)->whereKey($receiptId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $receiptId,
    ): EnterpriseGoodsReceipt {
        return $this->visibleQuery($scope)
            ->whereKey($receiptId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterpriseGoodsReceipt $receipt,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $receipt->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Goods receipt %s cannot be %s from status %s.',
                    $receipt->receipt_number,
                    $action,
                    $receipt->status,
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     lines:list<array{
     *         purchase_order_line_id:int,
     *         warehouse_id:int,
     *         quantity:mixed
     *     }>
     * }
     */
    private function validatedReceipt(array $attributes): array
    {
        return Validator::make($attributes, [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.warehouse_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ])->validate();
    }

    private function quantityUnits(mixed $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }
}
