<?php

namespace App\Enterprise\Wms;

use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class VanInventoryReadService
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly OperationalTenantScope $tenantScope,
        private readonly ModuleRegistry $modules,
        private readonly ModuleOperationalReadiness $readiness,
    ) {}

    /**
     * Read the stock of the current primary Van assignment, never the Van's
     * historical home warehouse or a caller-provided warehouse identifier.
     *
     * @return array{van_id:int,warehouse_id:int,store_id:int,rows:Collection,next_cursor:?int}
     */
    public function page(User $actor, int $vanId, ?int $afterInventoryId = null): array
    {
        abort_unless($actor->is_active && $actor->hasPermission('drivers.b2b.view'), 403);
        // Direct service consumers must not bypass the ERP route middleware's
        // exact manifest/version health attestation.
        $ready = false;
        try {
            $ready = $this->readiness->status(
                'inventory_wms',
                $this->modules->manifest('inventory_wms')->version,
                $this->modules->state('inventory_wms'),
            ) === 'ready';
        } catch (\Throwable) {
            $ready = false;
        }
        abort_unless($ready, 403);
        abort_if($afterInventoryId !== null && $afterInventoryId < 0, 422);

        $assignment = VanAssignment::query()
            ->where('van_id', $vanId)
            ->where('assignment_type', 'primary')
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', now());
            })
            ->whereNotNull('warehouse_id')
            ->orderByDesc('effective_from')
            ->firstOrFail();

        $warehouse = $this->warehouseScope->assertWarehouse(
            $actor,
            (int) $assignment->warehouse_id,
            'inventory.view',
        );
        $storeId = (int) $warehouse->store_id;
        abort_unless($this->tenantScope->storeChannel($storeId) === 'b2b', 404);

        $stock = DB::table('inventories as inventory')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->join('catalogs as catalog', 'catalog.id', '=', 'product.catalog_id')
            ->where('inventory.warehouse_id', $warehouse->getKey())
            ->where('catalog.store_id', $storeId)
            ->where('catalog.channel', 'b2b')
            ->when($afterInventoryId !== null, fn ($query) => $query->where('inventory.id', '>', $afterInventoryId))
            ->orderBy('inventory.id')
            ->limit(self::PAGE_SIZE + 1)
            ->get([
                'inventory.id as inventory_id',
                'product.id as product_id',
                'product.sku',
                'product.name as product_name',
                'inventory.quantity',
                'inventory.reserved_quantity',
            ]);

        $hasMore = $stock->count() > self::PAGE_SIZE;
        $rows = $stock->take(self::PAGE_SIZE)->values()->map(static function ($row): array {
            $quantity = (float) $row->quantity;
            $reserved = (float) $row->reserved_quantity;

            return [
                'inventory_id' => (int) $row->inventory_id,
                'product_id' => (int) $row->product_id,
                'sku' => $row->sku,
                'product_name' => $row->product_name,
                'quantity' => $quantity,
                'reserved_quantity' => $reserved,
                'available_quantity' => round($quantity - $reserved, 3),
            ];
        });

        return [
            'van_id' => $vanId,
            'warehouse_id' => (int) $warehouse->getKey(),
            'store_id' => $storeId,
            'rows' => $rows,
            'next_cursor' => $hasMore ? (int) $rows->last()['inventory_id'] : null,
        ];
    }
}
