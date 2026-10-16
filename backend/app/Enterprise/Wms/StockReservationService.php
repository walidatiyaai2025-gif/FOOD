<?php

namespace App\Enterprise\Wms;

use App\Models\Inventory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockReservationService
{
    private const MOVEMENT_TYPES = ['reserve', 'release', 'sale'];

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    public function ensureReservation(
        User $actor,
        int $warehouseId,
        int $productId,
        float $quantity,
        string $referenceType,
        int $referenceId,
        string $reason = 'Enterprise WMS stock reservation',
    ): float {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $targetQuantity = $this->normalizeQuantity($quantity);
        $referenceType = $this->normalizeReferenceType($referenceType);

        if ($referenceId < 1) {
            throw ValidationException::withMessages([
                'reference_id' => ['Reservation reference id must be a positive integer.'],
            ]);
        }

        $storeId = (int) $warehouse->store_id;

        return DB::transaction(function () use (
            $actor,
            $warehouseId,
            $productId,
            $targetQuantity,
            $referenceType,
            $referenceId,
            $reason,
            $storeId,
        ): float {
            $inventory = Inventory::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $inventory instanceof Inventory) {
                throw ValidationException::withMessages([
                    'product_id' => ['The product must be stocked in the selected warehouse.'],
                ]);
            }

            $currentQuantity = $this->activeQuantityForReference(
                (int) $inventory->getKey(),
                $storeId,
                $referenceType,
                $referenceId,
            );

            $onHand = round((float) $inventory->quantity, 3);
            $reserved = round((float) $inventory->reserved_quantity, 3);

            if ($currentQuantity > $reserved + 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => ['Reservation provenance exceeds the authoritative reserved quantity.'],
                ]);
            }

            $otherReserved = round(max(0.0, $reserved - $currentQuantity), 3);
            if ($otherReserved + $targetQuantity > $onHand + 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient available inventory for this reservation.'],
                ]);
            }

            $delta = round($targetQuantity - $currentQuantity, 3);
            if (abs($delta) < 0.0005) {
                return $currentQuantity;
            }

            $nextReserved = round($otherReserved + $targetQuantity, 3);
            $before = [
                'reserved_quantity' => $reserved,
                'reservation_quantity' => $currentQuantity,
            ];

            $inventory->forceFill([
                'reserved_quantity' => $nextReserved,
            ])->save();

            DB::table('stock_movements')->insert([
                'inventory_id' => $inventory->getKey(),
                'store_id' => $storeId,
                'user_id' => $actor->getKey(),
                'type' => $delta > 0 ? 'reserve' : 'release',
                'quantity' => abs($delta),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => trim($reason) === '' ? 'Enterprise WMS stock reservation' : trim($reason),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                'enterprise.wms.stock_reservation.changed',
                $actor,
                $inventory,
                $before,
                [
                    'reserved_quantity' => $nextReserved,
                    'reservation_quantity' => $targetQuantity,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ],
            );

            return $targetQuantity;
        }, attempts: 3);
    }

    public function releaseReservation(
        User $actor,
        int $warehouseId,
        int $productId,
        string $referenceType,
        int $referenceId,
        string $reason = 'Enterprise WMS stock reservation release',
    ): float {
        return $this->ensureReservation(
            $actor,
            $warehouseId,
            $productId,
            0.0,
            $referenceType,
            $referenceId,
            $reason,
        );
    }

    public function activeReservationQuantity(
        User $actor,
        int $warehouseId,
        int $productId,
        string $referenceType,
        int $referenceId,
    ): float {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
        $referenceType = $this->normalizeReferenceType($referenceType);

        if ($referenceId < 1) {
            throw ValidationException::withMessages([
                'reference_id' => ['Reservation reference id must be a positive integer.'],
            ]);
        }

        $inventoryId = Inventory::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('id');

        if ($inventoryId === null) {
            throw ValidationException::withMessages([
                'product_id' => ['The product must be stocked in the selected warehouse.'],
            ]);
        }

        return $this->activeQuantityForReference(
            (int) $inventoryId,
            (int) $warehouse->store_id,
            $referenceType,
            $referenceId,
        );
    }

    private function activeQuantityForReference(
        int $inventoryId,
        int $storeId,
        string $referenceType,
        int $referenceId,
    ): float {
        $active = DB::table('stock_movements')
            ->where('store_id', $storeId)
            ->where('inventory_id', $inventoryId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('type', self::MOVEMENT_TYPES)
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'reserve' THEN ABS(quantity) ELSE -ABS(quantity) END), 0) as active_quantity",
            )
            ->value('active_quantity');

        return round(max(0.0, (float) $active), 3);
    }

    private function normalizeQuantity(float $quantity): float
    {
        $quantity = round($quantity, 3);

        if ($quantity < 0 || $quantity > 99999999999.999) {
            throw ValidationException::withMessages([
                'quantity' => ['Reservation quantity must be between 0 and 99999999999.999.'],
            ]);
        }

        return $quantity;
    }

    private function normalizeReferenceType(string $referenceType): string
    {
        $referenceType = trim($referenceType);

        if (preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/', $referenceType) !== 1) {
            throw ValidationException::withMessages([
                'reference_type' => ['Reservation reference type must contain only letters, numbers, dot, underscore, colon or dash.'],
            ]);
        }

        return $referenceType;
    }
}
