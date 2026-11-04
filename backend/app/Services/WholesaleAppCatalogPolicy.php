<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WholesaleAppCatalogPolicy
{
    public function __construct(private readonly WholesalePrincipal $principal) {}

    public function showInStockOnly(int $storeId): bool
    {
        $raw = DB::table('storefront_settings')->where('store_id', $storeId)->value('branding');
        $branding = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return filter_var($branding['show_in_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function restrictToAvailableStock(Builder $products, int $storeId): Builder
    {
        $warehouseId = $storeId === $this->principal->storeId()
            ? $this->principal->warehouseId()
            : null;

        return $products->whereExists(static function (Builder $query) use ($storeId, $warehouseId): void {
            $query->selectRaw('1')
                ->from('inventories as app_available_inventory')
                ->join('warehouses as app_available_warehouse', 'app_available_warehouse.id', '=', 'app_available_inventory.warehouse_id')
                ->whereColumn('app_available_inventory.product_id', 'products.id')
                ->where('app_available_warehouse.store_id', $storeId)
                ->where('app_available_warehouse.is_active', true)
                ->whereColumn('app_available_inventory.quantity', '>', 'app_available_inventory.reserved_quantity');

            if ($warehouseId !== null) {
                $query->where('app_available_warehouse.id', $warehouseId);
            }
        });
    }
}
