<?php

namespace App\Domain\SmartOrdering;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class InventoryService
{
    /** @param list<array{product_id:int,quantity:float}> $items */
    public function reserve(Order $order, array $items): void
    {
        foreach ($this->aggregate($items) as $productId => $quantity) {
            $rows = DB::table('inventories')
                ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', (int) $order->store_id)
                ->where('warehouses.is_active', true)
                ->where('inventories.product_id', $productId)
                ->orderBy('inventories.id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $available = (float) $rows->sum(
                static fn (object $row): float => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
            );
            abort_if($quantity > $available + 0.0001, 409, 'A selected product does not have enough stock.');

            $remaining = $quantity;
            foreach ($rows as $row) {
                $rowAvailable = max(0.0, (float) $row->quantity - (float) $row->reserved_quantity);
                $reserved = min($remaining, $rowAvailable);
                if ($reserved <= 0) {
                    continue;
                }

                DB::table('inventories')->where('id', $row->id)->increment('reserved_quantity', $reserved);
                DB::table('stock_movements')->insert([
                    'inventory_id' => (int) $row->id,
                    'store_id' => (int) $order->store_id,
                    'user_id' => null,
                    'type' => 'reserve',
                    'quantity' => $reserved,
                    'reference_type' => 'order',
                    'reference_id' => $order->getKey(),
                    'reason' => 'smart_ordering_checkout',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $remaining -= $reserved;
                if ($remaining <= 0.0001) {
                    break;
                }
            }
        }
    }

    public function release(Order $order, ?int $userId = null): void
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

            DB::table('inventories')->where('id', $inventoryId)->update([
                'reserved_quantity' => max(0.0, (float) $inventory->reserved_quantity - $quantity),
                'updated_at' => now(),
            ]);
            $this->movement($order, $inventoryId, $userId, 'release', $quantity, 'smart_ordering_cancelled');
        }
    }

    public function consume(Order $order, ?int $userId = null): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);
            abort_unless($inventory !== null, 409, 'Reserved inventory no longer exists.');
            abort_if(
                $quantity > (float) $inventory->quantity + 0.0001
                || $quantity > (float) $inventory->reserved_quantity + 0.0001,
                409,
                'Reserved inventory is inconsistent with the order.',
            );

            DB::table('inventories')->where('id', $inventoryId)->update([
                'quantity' => (float) $inventory->quantity - $quantity,
                'reserved_quantity' => (float) $inventory->reserved_quantity - $quantity,
                'updated_at' => now(),
            ]);
            $this->movement($order, $inventoryId, $userId, 'sale', -$quantity, 'smart_ordering_completed');
        }
    }

    /** @param list<array{product_id:int,quantity:float}> $items
     * @return array<int,float>
     */
    private function aggregate(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $totals[$productId] = round(($totals[$productId] ?? 0.0) + (float) $item['quantity'], 3);
        }

        return $totals;
    }

    /** @return array<int,float> */
    private function activeReservations(Order $order): array
    {
        $balances = [];
        $rows = DB::table('stock_movements')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->whereIn('type', ['reserve', 'release', 'sale'])
            ->orderBy('id')
            ->get(['inventory_id', 'type', 'quantity']);

        foreach ($rows as $row) {
            $id = (int) $row->inventory_id;
            $balances[$id] ??= 0.0;
            $quantity = abs((float) $row->quantity);
            $balances[$id] += $row->type === 'reserve' ? $quantity : -$quantity;
        }

        return collect($balances)
            ->map(static fn (float $quantity): float => round(max(0.0, $quantity), 3))
            ->filter(static fn (float $quantity): bool => $quantity > 0)
            ->all();
    }

    private function movement(
        Order $order,
        int $inventoryId,
        ?int $userId,
        string $type,
        float $quantity,
        string $reason,
    ): void {
        DB::table('stock_movements')->insert([
            'inventory_id' => $inventoryId,
            'store_id' => (int) $order->store_id,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => 'order',
            'reference_id' => $order->getKey(),
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
