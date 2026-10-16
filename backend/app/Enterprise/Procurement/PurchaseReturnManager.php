<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterpriseGoodsReceipt;
use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\EnterprisePurchaseReturn;
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

final class PurchaseReturnManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_returns.view';

    private const MANAGE_PERMISSION = 'procurement.purchase_returns.manage';

    private const POST_PERMISSION = 'procurement.purchase_returns.post';

    private const STATUSES = ['draft', 'posted', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
        private readonly ModuleRegistry $modules,
        private readonly PurchaseReturnInventoryHandoff $inventory,
    ) {}

    /**
     * @param  array{q?:string,status?:string,goods_receipt_id?:int}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseReturn>
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
                $validated['goods_receipt_id'] ?? null,
                fn (Builder $query, mixed $receiptId): Builder => $query->where('goods_receipt_id', (int) $receiptId),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where('return_number', 'like', $term.'%'),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $returnId,
    ): EnterprisePurchaseReturn {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->findVisible($scope, $returnId)->load([
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
    ): EnterprisePurchaseReturn {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $data = $this->validatedReturn($attributes);

        return DB::transaction(function () use ($actor, $scope, $receiptId, $data): EnterprisePurchaseReturn {
            $receipt = $this->visibleReceiptQuery($scope)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status !== 'posted') {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => [
                        sprintf(
                            'Goods receipt %s must be posted before goods can be returned.',
                            $receipt->receipt_number,
                        ),
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
                    'lines' => ['Every purchase return line must reference a line on the selected posted goods receipt.'],
                ]);
            }

            $postedTotals = $this->postedReturnTotals($receipt->id);

            foreach ($data['lines'] as $line) {
                /** @var EnterpriseGoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get((int) $line['goods_receipt_line_id']);

                if ($receiptLine->inventory_id === null || $receiptLine->stock_movement_id === null) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'Goods receipt line %d has no completed inventory handoff.',
                                $receiptLine->line_no,
                            ),
                        ],
                    ]);
                }

                $alreadyReturned = $postedTotals->get($receiptLine->id, 0);
                $nextReturned = $this->quantityUnits($alreadyReturned)
                    + $this->quantityUnits($line['quantity']);

                if ($nextReturned > $this->quantityUnits($receiptLine->received_quantity)) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'Return quantity for GRN line %d exceeds the remaining received quantity.',
                                $receiptLine->line_no,
                            ),
                        ],
                    ]);
                }
            }

            $publicId = (string) Str::uuid();
            $return = EnterprisePurchaseReturn::query()->create([
                'public_id' => $publicId,
                'return_number' => 'PRTN-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 11)),
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
                'status' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($data['lines'] as $index => $line) {
                /** @var EnterpriseGoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get((int) $line['goods_receipt_line_id']);
                $return->lines()->create([
                    'goods_receipt_line_id' => $receiptLine->id,
                    'purchase_order_line_id' => $receiptLine->purchase_order_line_id,
                    'line_no' => $index + 1,
                    'item_code' => $receiptLine->item_code,
                    'description' => $receiptLine->description,
                    'received_quantity' => $receiptLine->received_quantity,
                    'returned_quantity' => $line['quantity'],
                    'unit' => $receiptLine->unit,
                    'unit_price' => $receiptLine->unit_price,
                    'warehouse_id' => $receiptLine->warehouse_id,
                ]);
            }

            $this->audit->record(
                'enterprise.purchase_return.created',
                $actor,
                $return,
                null,
                [
                    'return_number' => $return->return_number,
                    'goods_receipt_id' => $receipt->id,
                    'source_receipt_number' => $receipt->receipt_number,
                    'source_order_number' => $receipt->source_order_number,
                    'supplier_id' => $return->supplier_id,
                    'status' => $return->status,
                    'organization_id' => $return->organization_id,
                    'company_id' => $return->company_id,
                    'store_id' => $return->store_id,
                    'line_count' => count($data['lines']),
                ],
            );

            return $return->load('lines');
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $returnId,
    ): EnterprisePurchaseReturn {
        $scope = $this->authorizedScope($actor, $context, self::POST_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $returnId): EnterprisePurchaseReturn {
            $return = $this->lockVisible($scope, $returnId);
            if ($return->status === 'posted') {
                return $return->refresh()->load('lines');
            }

            $this->requireStatus($return, ['draft'], 'posted');

            $receipt = $this->visibleReceiptQuery($scope)
                ->whereKey($return->goods_receipt_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status !== 'posted') {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => [
                        sprintf(
                            'Goods receipt %s is no longer eligible for purchase returns.',
                            $receipt->receipt_number,
                        ),
                    ],
                ]);
            }

            $lines = $return->lines()
                ->orderBy('warehouse_id')
                ->orderBy('line_no')
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => ['A purchase return must contain at least one line.'],
                ]);
            }

            $receiptLines = $receipt->lines()->get()->keyBy('id');
            $postedTotals = $this->postedReturnTotals($receipt->id);

            foreach ($lines as $line) {
                /** @var EnterpriseGoodsReceiptLine|null $receiptLine */
                $receiptLine = $receiptLines->get((int) $line->goods_receipt_line_id);
                if ($receiptLine === null) {
                    throw ValidationException::withMessages([
                        'lines' => ['A purchase return line no longer belongs to its source goods receipt.'],
                    ]);
                }

                $alreadyReturned = $postedTotals->get($receiptLine->id, 0);
                $nextReturned = $this->quantityUnits($alreadyReturned)
                    + $this->quantityUnits($line->returned_quantity);

                if ($nextReturned > $this->quantityUnits($receiptLine->received_quantity)) {
                    throw ValidationException::withMessages([
                        'lines' => [
                            sprintf(
                                'Posting would over-return GRN line %d. Received %s; already returned %s; this return %s.',
                                $receiptLine->line_no,
                                $receiptLine->received_quantity,
                                $alreadyReturned,
                                $line->returned_quantity,
                            ),
                        ],
                    ]);
                }
            }

            foreach ($lines as $line) {
                /** @var EnterpriseGoodsReceiptLine $receiptLine */
                $receiptLine = $receiptLines->get((int) $line->goods_receipt_line_id);
                $this->inventory->reverse($actor, $scope->storeId, $return, $line, $receiptLine);
            }

            $before = $return->toArray();
            $return->forceFill([
                'status' => 'posted',
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_return.posted',
                $actor,
                $return,
                $before,
                [
                    ...$return->fresh()->toArray(),
                    'line_count' => $lines->count(),
                ],
            );

            return $return->refresh()->load('lines');
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $returnId,
    ): EnterprisePurchaseReturn {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $returnId): EnterprisePurchaseReturn {
            $return = $this->lockVisible($scope, $returnId);
            if ($return->status === 'cancelled') {
                return $return->refresh();
            }

            if ($return->status === 'posted') {
                throw ValidationException::withMessages([
                    'status' => ['A posted purchase return is immutable and cannot be cancelled silently.'],
                ]);
            }

            $this->requireStatus($return, ['draft'], 'cancelled');
            $before = $return->toArray();
            $return->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_return.cancelled',
                $actor,
                $return,
                $before,
                $return->fresh()->toArray(),
            );

            return $return->refresh();
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

    /** @return Builder<EnterprisePurchaseReturn> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseReturn::query()
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
        int $returnId,
    ): EnterprisePurchaseReturn {
        return $this->visibleQuery($scope)->whereKey($returnId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $returnId,
    ): EnterprisePurchaseReturn {
        return $this->visibleQuery($scope)
            ->whereKey($returnId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterprisePurchaseReturn $return,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $return->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Purchase return %s cannot be %s from status %s.',
                    $return->return_number,
                    $action,
                    $return->status,
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     lines:list<array{
     *         goods_receipt_line_id:int,
     *         quantity:mixed
     *     }>
     * }
     */
    private function validatedReturn(array $attributes): array
    {
        return Validator::make($attributes, [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ])->validate();
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

    private function quantityUnits(mixed $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }
}
