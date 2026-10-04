<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ProductAvailabilityService
{
    public const AVAILABLE = 'AVAILABLE';

    public const OUT_OF_STOCK = 'OUT_OF_STOCK';

    /** @return array{available_quantity:float|null,is_available:bool,availability_state:string} */
    public function forStoreProduct(int $storeId, int $productId): array
    {
        $rows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->get(['inventories.quantity', 'inventories.reserved_quantity']);

        $available = $rows->isEmpty()
            ? null
            : round((float) $rows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            ), 3);
        $isAvailable = $available === null || $available > 0;

        return [
            'available_quantity' => $available,
            'is_available' => $isAvailable,
            'availability_state' => $isAvailable ? self::AVAILABLE : self::OUT_OF_STOCK,
        ];
    }
}
