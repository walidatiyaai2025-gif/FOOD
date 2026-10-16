<?php

namespace App\Enterprise\Wms;

use App\Models\EnterpriseReorderPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReorderPolicyService
{
    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{minimum_quantity?:mixed,reorder_quantity?:mixed,maximum_quantity?:mixed,is_active?:mixed}  $attributes
     */
    public function upsert(
        User $actor,
        int $warehouseId,
        int $productId,
        array $attributes,
    ): EnterpriseReorderPolicy {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $data = Validator::make($attributes, [
            'minimum_quantity' => ['required', 'numeric', 'min:0'],
            'reorder_quantity' => ['required', 'numeric', 'min:0.001'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $minimum = round((float) $data['minimum_quantity'], 3);
        $reorder = round((float) $data['reorder_quantity'], 3);
        $maximum = array_key_exists('maximum_quantity', $data) && $data['maximum_quantity'] !== null
            ? round((float) $data['maximum_quantity'], 3)
            : null;

        if ($maximum !== null && $maximum < $minimum) {
            throw ValidationException::withMessages([
                'maximum_quantity' => ['Maximum quantity must be greater than or equal to minimum quantity.'],
            ]);
        }

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
            'minimum_quantity' => $minimum,
            'reorder_quantity' => $reorder,
            'maximum_quantity' => $maximum,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by' => $actor->getKey(),
        ];

        return DB::transaction(function () use ($actor, $warehouseId, $productId, $values): EnterpriseReorderPolicy {
            $policy = EnterpriseReorderPolicy::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($policy instanceof EnterpriseReorderPolicy) {
                $before = $policy->toArray();
                $policy->fill($values);

                if (! $policy->isDirty()) {
                    return $policy;
                }

                $policy->save();
                $this->audit->record(
                    'enterprise.wms.reorder_policy.updated',
                    $actor,
                    $policy,
                    $before,
                    $policy->fresh()->toArray(),
                );

                return $policy->refresh();
            }

            $policy = EnterpriseReorderPolicy::query()->create([
                ...$values,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                'enterprise.wms.reorder_policy.created',
                $actor,
                $policy,
                null,
                $policy->toArray(),
            );

            return $policy->refresh();
        }, attempts: 3);
    }

    public function disable(
        User $actor,
        int $policyId,
    ): EnterpriseReorderPolicy {
        return DB::transaction(function () use ($actor, $policyId): EnterpriseReorderPolicy {
            $policy = EnterpriseReorderPolicy::query()
                ->lockForUpdate()
                ->findOrFail($policyId);

            $this->warehouseScope->assertWarehouse(
                $actor,
                (int) $policy->warehouse_id,
                'inventory.manage',
            );

            if (! $policy->is_active) {
                return $policy;
            }

            $before = $policy->toArray();
            $policy->forceFill([
                'is_active' => false,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                'enterprise.wms.reorder_policy.disabled',
                $actor,
                $policy,
                $before,
                $policy->fresh()->toArray(),
            );

            return $policy->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, EnterpriseReorderPolicy> */
    public function policiesForWarehouse(
        User $actor,
        int $warehouseId,
        int $limit = 25,
    ): Collection {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');

        return EnterpriseReorderPolicy::query()
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('is_active')
            ->orderBy('product_id')
            ->orderBy('id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /**
     * @return Collection<int, EnterpriseReorderPolicy>
     */
    public function lowStock(
        User $actor,
        int $storeId,
        ?int $warehouseId = null,
        int $limit = 25,
    ): Collection {
        if ($warehouseId !== null) {
            $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
            abort_unless((int) $warehouse->store_id === $storeId, 404);
            $warehouseIds = [$warehouseId];
        } else {
            $warehouseIds = $this->warehouseScope->authorizedWarehouseIds(
                $actor,
                $storeId,
                'inventory.view',
            );
        }

        if ($warehouseIds === []) {
            return new Collection;
        }

        return EnterpriseReorderPolicy::query()
            ->from('enterprise_reorder_policies as reorder_policy')
            ->join('inventories as inventory', function ($join): void {
                $join
                    ->on('inventory.warehouse_id', '=', 'reorder_policy.warehouse_id')
                    ->on('inventory.product_id', '=', 'reorder_policy.product_id');
            })
            ->where('reorder_policy.store_id', $storeId)
            ->whereIn('reorder_policy.warehouse_id', $warehouseIds)
            ->where('reorder_policy.is_active', true)
            ->whereRaw(
                '(inventory.quantity - inventory.reserved_quantity) <= reorder_policy.minimum_quantity',
            )
            ->select('reorder_policy.*')
            ->selectRaw(
                '(inventory.quantity - inventory.reserved_quantity) as available_quantity',
            )
            ->orderByRaw('(inventory.quantity - inventory.reserved_quantity) asc')
            ->orderBy('reorder_policy.id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min(100, $limit));
    }
}
