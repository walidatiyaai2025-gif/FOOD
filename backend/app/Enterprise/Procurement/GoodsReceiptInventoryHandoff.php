<?php

namespace App\Enterprise\Procurement;

use App\Models\EnterpriseGoodsReceipt;
use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

final class GoodsReceiptInventoryHandoff
{
    public function receive(
        User $actor,
        int $storeId,
        EnterpriseGoodsReceipt $receipt,
        EnterpriseGoodsReceiptLine $line,
    ): void {
        if ($line->stock_movement_id !== null || $line->inventory_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Goods receipt inventory handoff is already recorded for this line.'],
            ]);
        }

        $itemCode = trim((string) $line->item_code);
        if ($itemCode === '') {
            throw ValidationException::withMessages([
                'item_code' => [
                    sprintf(
                        'Goods receipt line %d has no item code and cannot be handed to inventory.',
                        $line->line_no,
                    ),
                ],
            ]);
        }

        $product = Product::query()
            ->forStore($storeId)
            ->where('sku', $itemCode)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'item_code' => [
                    sprintf(
                        'Item code %s is not an active product in the receiving store.',
                        $itemCode,
                    ),
                ],
            ]);
        }

        $warehouse = Warehouse::query()
            ->whereKey((int) $line->warehouse_id)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The receiving warehouse is not active in the authorized store.'],
            ]);
        }

        $inventory = Inventory::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            $inventory = Inventory::query()->create([
                'warehouse_id' => $warehouse->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]);
        }

        $quantity = (float) $line->received_quantity;
        $inventory->forceFill([
            'quantity' => round((float) $inventory->quantity + $quantity, 3),
        ])->save();

        $movement = StockMovement::query()->create([
            'inventory_id' => $inventory->getKey(),
            'store_id' => $storeId,
            'user_id' => $actor->getKey(),
            'type' => 'purchase_receipt',
            'quantity' => $quantity,
            'reference_type' => 'enterprise_goods_receipt_line',
            'reference_id' => $line->getKey(),
            'reason' => sprintf(
                'GRN %s / PO %s',
                $receipt->receipt_number,
                $receipt->source_order_number,
            ),
        ]);

        $line->forceFill([
            'inventory_id' => $inventory->getKey(),
            'stock_movement_id' => $movement->getKey(),
        ])->save();
    }
}
