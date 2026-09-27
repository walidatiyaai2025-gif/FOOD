<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderInventoryReservationService
{
    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     */
    public function reserve(Order $order, User $user, array $items, string $reason = 'dashboard_order'): void
    {
        foreach ($items as $item) {
            $inventoryRows = DB::table('inventories')
                ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', (int) $order->store_id)
                ->where('warehouses.is_active', true)
                ->where('inventories.product_id', $item['product_id'])
                ->orderBy('inventories.id')
                ->lockForUpdate()
                ->get();

            // Products without inventory rows remain non-stock-managed, matching checkout behavior.
            if ($inventoryRows->isEmpty()) {
                continue;
            }

            $available = (float) $inventoryRows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            );

            if ($item['quantity'] > $available) {
                throw new HttpException(409, 'A selected product does not have enough stock.');
            }

            $remaining = $item['quantity'];
            foreach ($inventoryRows as $inventoryRow) {
                $rowAvailable = max(
                    0.0,
                    (float) $inventoryRow->quantity - (float) $inventoryRow->reserved_quantity,
                );
                $reserve = min($remaining, $rowAvailable);

                if ($reserve > 0) {
                    DB::table('inventories')
                        ->where('id', $inventoryRow->id)
                        ->increment('reserved_quantity', $reserve);

                    StockMovement::query()->create([
                        'inventory_id' => (int) $inventoryRow->id,
                        'store_id' => (int) $order->store_id,
                        'user_id' => $user->getKey(),
                        'type' => 'reserve',
                        'quantity' => $reserve,
                        'reference_type' => 'order',
                        'reference_id' => $order->getKey(),
                        'reason' => $reason,
                    ]);

                    $remaining -= $reserve;
                }

                if ($remaining <= 0) {
                    break;
                }
            }
        }
    }

    public function release(Order $order, User $user, string $reason = 'order_cancelled'): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);

            if ($inventory === null) {
                continue;
            }

            DB::table('inventories')
                ->where('id', $inventoryId)
                ->update([
                    'reserved_quantity' => max(0.0, (float) $inventory->reserved_quantity - $quantity),
                    'updated_at' => now(),
                ]);

            StockMovement::query()->create([
                'inventory_id' => $inventoryId,
                'store_id' => (int) $order->store_id,
                'user_id' => $user->getKey(),
                'type' => 'release',
                'quantity' => $quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => $reason,
            ]);
        }
    }

    public function consume(Order $order, User $user): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);

            if ($inventory === null) {
                throw new HttpException(409, 'Reserved inventory no longer exists.');
            }

            $onHand = (float) $inventory->quantity;
            $reserved = (float) $inventory->reserved_quantity;

            if ($quantity > $onHand || $quantity > $reserved) {
                throw new HttpException(409, 'Reserved inventory is inconsistent with the order.');
            }

            DB::table('inventories')
                ->where('id', $inventoryId)
                ->update([
                    'quantity' => $onHand - $quantity,
                    'reserved_quantity' => $reserved - $quantity,
                    'updated_at' => now(),
                ]);

            StockMovement::query()->create([
                'inventory_id' => $inventoryId,
                'store_id' => (int) $order->store_id,
                'user_id' => $user->getKey(),
                'type' => 'sale',
                'quantity' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => 'order_delivered',
            ]);
        }
    }

    /** @return array<int, float> inventory_id => active reserved quantity */
    private function activeReservations(Order $order): array
    {
        $balances = [];

        $movements = StockMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->whereIn('type', ['reserve', 'release', 'sale'])
            ->orderBy('id')
            ->get(['inventory_id', 'type', 'quantity']);

        foreach ($movements as $movement) {
            $inventoryId = (int) $movement->inventory_id;
            $balances[$inventoryId] ??= 0.0;
            $quantity = abs((float) $movement->quantity);

            if ($movement->type === 'reserve') {
                $balances[$inventoryId] += $quantity;
            } else {
                $balances[$inventoryId] -= $quantity;
            }
        }

        return collect($balances)
            ->map(static fn (float $quantity): float => round(max(0.0, $quantity), 3))
            ->filter(static fn (float $quantity): bool => $quantity > 0)
            ->all();
    }
}
