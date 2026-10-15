<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseScope
{
    public function __construct(
        private readonly OperationalTenantScope $tenantScope,
    ) {}

    public function assertWarehouse(
        User $user,
        int $warehouseId,
        string $permission = 'inventory.manage',
    ): Warehouse {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->firstOrFail();

        $storeId = (int) $warehouse->store_id;
        abort_if($storeId < 1, 404);

        $this->tenantScope->assertStore($user, $storeId, $permission);

        if ($user->hasRole('SUPER_ADMIN')) {
            return $warehouse;
        }

        $hasExplicitScope = DB::table('enterprise_warehouse_user_scopes as scopes')
            ->join('warehouses as scoped_warehouses', 'scoped_warehouses.id', '=', 'scopes.warehouse_id')
            ->where('scopes.user_id', $user->getKey())
            ->where('scoped_warehouses.store_id', $storeId)
            ->where('scopes.is_active', true)
            ->exists();

        if (! $hasExplicitScope) {
            return $warehouse;
        }

        $allowed = DB::table('enterprise_warehouse_user_scopes')
            ->where('user_id', $user->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->when($permission !== 'inventory.view', fn ($query) => $query->where('can_manage', true))
            ->exists();

        abort_unless($allowed, 404);

        return $warehouse;
    }

    /** @return array{0:Warehouse,1:Warehouse} */
    public function assertTransferPair(
        User $user,
        int $sourceWarehouseId,
        int $targetWarehouseId,
        string $permission = 'inventory.manage',
    ): array {
        if ($sourceWarehouseId === $targetWarehouseId) {
            throw ValidationException::withMessages([
                'target_warehouse_id' => ['Source and target warehouses must be different.'],
            ]);
        }

        $source = $this->assertWarehouse($user, $sourceWarehouseId, $permission);
        $target = $this->assertWarehouse($user, $targetWarehouseId, $permission);

        if ((int) $source->store_id !== (int) $target->store_id) {
            throw ValidationException::withMessages([
                'target_warehouse_id' => ['Warehouse transfers must stay within one store.'],
            ]);
        }

        return [$source, $target];
    }

    /** @return list<int> */
    public function authorizedWarehouseIds(
        User $user,
        int $storeId,
        string $permission = 'inventory.view',
    ): array {
        $this->tenantScope->assertStore($user, $storeId, $permission);

        $warehouseIds = DB::table('warehouses')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id);

        if ($user->hasRole('SUPER_ADMIN')) {
            return $warehouseIds->all();
        }

        $configured = DB::table('enterprise_warehouse_user_scopes as scopes')
            ->join('warehouses as scoped_warehouses', 'scoped_warehouses.id', '=', 'scopes.warehouse_id')
            ->where('scopes.user_id', $user->getKey())
            ->where('scoped_warehouses.store_id', $storeId)
            ->where('scopes.is_active', true)
            ->exists();

        if (! $configured) {
            return $warehouseIds->all();
        }

        return DB::table('enterprise_warehouse_user_scopes as scopes')
            ->join('warehouses as scoped_warehouses', 'scoped_warehouses.id', '=', 'scopes.warehouse_id')
            ->where('scopes.user_id', $user->getKey())
            ->where('scoped_warehouses.store_id', $storeId)
            ->where('scopes.is_active', true)
            ->where('scoped_warehouses.is_active', true)
            ->when($permission !== 'inventory.view', fn ($query) => $query->where('scopes.can_manage', true))
            ->orderBy('scopes.warehouse_id')
            ->pluck('scopes.warehouse_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
