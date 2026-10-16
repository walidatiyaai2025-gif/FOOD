<?php

namespace App\Enterprise\Wms;

use App\Models\EnterpriseInventoryValuationPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryValuationService
{
    private const COST_METHODS = ['standard'];

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function upsertPolicy(
        User $actor,
        int $warehouseId,
        int $productId,
        array $attributes,
    ): EnterpriseInventoryValuationPolicy {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $data = Validator::make($attributes, [
            'cost_method' => ['sometimes', Rule::in(self::COST_METHODS)],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.999'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $inventoryExists = DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->exists();

        if (! $inventoryExists) {
            throw ValidationException::withMessages([
                'product_id' => ['The product must be stocked in the selected warehouse.'],
            ]);
        }

        $values = [
            'store_id' => (int) $warehouse->store_id,
            'cost_method' => (string) ($data['cost_method'] ?? 'standard'),
            'unit_cost' => round((float) $data['unit_cost'], 3),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by' => $actor->getKey(),
        ];

        return DB::transaction(function () use ($actor, $warehouseId, $productId, $values): EnterpriseInventoryValuationPolicy {
            $policy = EnterpriseInventoryValuationPolicy::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($policy instanceof EnterpriseInventoryValuationPolicy) {
                $before = $policy->toArray();
                $policy->fill($values);
                if (! $policy->isDirty()) {
                    return $policy;
                }

                $policy->save();
                $this->audit->record(
                    'enterprise.wms.valuation_policy.updated',
                    $actor,
                    $policy,
                    $before,
                    $policy->fresh()->toArray(),
                );

                return $policy->refresh();
            }

            $policy = EnterpriseInventoryValuationPolicy::query()->create([
                ...$values,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                'enterprise.wms.valuation_policy.created',
                $actor,
                $policy,
                null,
                $policy->toArray(),
            );

            return $policy->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, \stdClass> */
    public function valuation(
        User $actor,
        int $storeId,
        ?int $warehouseId = null,
        int $limit = 25,
    ): Collection {
        $warehouseIds = $this->authorizedWarehouses($actor, $storeId, $warehouseId);

        if ($warehouseIds === []) {
            return collect();
        }

        return DB::table('enterprise_inventory_valuation_policies as policy')
            ->join('inventories as inventory', function ($join): void {
                $join
                    ->on('inventory.warehouse_id', '=', 'policy.warehouse_id')
                    ->on('inventory.product_id', '=', 'policy.product_id');
            })
            ->where('policy.store_id', $storeId)
            ->whereIn('policy.warehouse_id', $warehouseIds)
            ->where('policy.is_active', true)
            ->select([
                'policy.id',
                'policy.warehouse_id',
                'policy.product_id',
                'policy.cost_method',
                'policy.unit_cost',
                'inventory.id as inventory_id',
                'inventory.quantity',
                'inventory.reserved_quantity',
            ])
            ->selectRaw('(inventory.quantity - inventory.reserved_quantity) as available_quantity')
            ->selectRaw('(inventory.quantity * policy.unit_cost) as inventory_value')
            ->orderBy('policy.warehouse_id')
            ->orderBy('policy.product_id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    public function aging(
        User $actor,
        int $storeId,
        ?int $warehouseId = null,
        int $limit = 25,
    ): Collection {
        $warehouseIds = $this->authorizedWarehouses($actor, $storeId, $warehouseId);

        if ($warehouseIds === []) {
            return collect();
        }

        $inbound = DB::table('stock_movements')
            ->selectRaw('inventory_id, MAX(created_at) as last_inbound_at')
            ->where('store_id', $storeId)
            ->where('quantity', '>', 0)
            ->whereIn('type', [
                'purchase_receipt',
                'warehouse_transfer_in',
                'cycle_count_adjustment',
                'adjustment',
            ])
            ->groupBy('inventory_id');

        return DB::table('enterprise_inventory_valuation_policies as policy')
            ->join('inventories as inventory', function ($join): void {
                $join
                    ->on('inventory.warehouse_id', '=', 'policy.warehouse_id')
                    ->on('inventory.product_id', '=', 'policy.product_id');
            })
            ->leftJoinSub($inbound, 'inbound', 'inbound.inventory_id', '=', 'inventory.id')
            ->where('policy.store_id', $storeId)
            ->whereIn('policy.warehouse_id', $warehouseIds)
            ->where('policy.is_active', true)
            ->where('inventory.quantity', '>', 0)
            ->select([
                'policy.warehouse_id',
                'policy.product_id',
                'inventory.id as inventory_id',
                'inventory.quantity',
                'policy.unit_cost',
            ])
            ->selectRaw('COALESCE(inbound.last_inbound_at, inventory.created_at) as last_inbound_at')
            ->selectRaw(
                "CASE
                    WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) >= ? THEN '0_30'
                    WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) >= ? THEN '31_60'
                    WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) >= ? THEN '61_90'
                    ELSE '91_plus'
                END as aging_bucket",
                [
                    now()->subDays(30),
                    now()->subDays(60),
                    now()->subDays(90),
                ],
            )
            ->selectRaw('(inventory.quantity * policy.unit_cost) as inventory_value')
            ->orderByRaw('COALESCE(inbound.last_inbound_at, inventory.created_at) asc')
            ->orderBy('inventory.id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    public function slowOrDeadStock(
        User $actor,
        int $storeId,
        int $minimumAgeDays = 90,
        ?int $warehouseId = null,
        int $limit = 25,
    ): Collection {
        if ($minimumAgeDays < 1 || $minimumAgeDays > 3650) {
            throw ValidationException::withMessages([
                'minimum_age_days' => ['Minimum age days must be between 1 and 3650.'],
            ]);
        }

        return $this->aging($actor, $storeId, $warehouseId, 100)
            ->filter(
                static fn (\stdClass $row): bool => now()->diffInDays($row->last_inbound_at, true) >= $minimumAgeDays,
            )
            ->take($this->boundedLimit($limit))
            ->values();
    }

    /** @return list<int> */
    private function authorizedWarehouses(
        User $actor,
        int $storeId,
        ?int $warehouseId,
    ): array {
        if ($warehouseId !== null) {
            $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
            abort_unless((int) $warehouse->store_id === $storeId, 404);

            return [$warehouseId];
        }

        return $this->warehouseScope->authorizedWarehouseIds($actor, $storeId, 'inventory.view');
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min(100, $limit));
    }
}
