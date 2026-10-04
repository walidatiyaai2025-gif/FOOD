<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ProductAvailabilityService
{
    public const AVAILABLE = 'AVAILABLE';
    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    /** @return array{available_quantity:float,is_available:bool,availability_state:string} */
    public function forStoreProduct(int $storeId, int $productId): array
    {
        $available = (float) DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->selectRaw('COALESCE(SUM(CASE WHEN inventories.quantity - inventories.reserved_quantity > 0 THEN inventories.quantity - inventories.reserved_quantity ELSE 0 END), 0) as available_quantity')
            ->value('available_quantity');

        $available = round(max(0.0, $available), 3);
        $isAvailable = $available > 0;

        return [
            'available_quantity' => $available,
            'is_available' => $isAvailable,
            'availability_state' => $isAvailable ? self::AVAILABLE : self::OUT_OF_STOCK,
        ];
    }
}
