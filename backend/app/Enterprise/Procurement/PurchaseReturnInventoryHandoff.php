<?php

namespace App\Enterprise\Procurement;

use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\EnterprisePurchaseReturn;
use App\Models\EnterprisePurchaseReturnLine;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnInventoryHandoff
{
    public function reverse(
        User $actor,
        int $storeId,
        EnterprisePurchaseReturn $return,
        EnterprisePurchaseReturnLine $line,
        EnterpriseGoodsReceiptLine $sourceLine,
    ): void {
        if ($line->stock_movement_id !== null || $line->inventory_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Purchase return inventory reversal is already recorded for this line.'],
            ]);
        }

        if ($sourceLine->inventory_id === null || $sourceLine->stock_movement_id === null) {
            throw ValidationException::withMessages([
                'goods_receipt_line_id' => [
                    sprintf(
                        'Goods receipt line %d has no completed inventory handoff.',
                        $sourceLine->line_no,
                    ),
                ],
            ]);
        }

        if ((int) $sourceLine->warehouse_id !== (int) $line->warehouse_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['Purchase return warehouse must match the posted goods receipt line.'],
            ]);
        }

        $inventory = Inventory::query()
            ->whereKey((int) $sourceLine->inventory_id)
            ->whereHas('warehouse', static fn ($query) => $query->where('store_id', $storeId))
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'inventory_id' => ['The received inventory is not available in the authorized store.'],
            ]);
        }

        $quantity = round((float) $line->returned_quantity, 3);
        $current = round((float) $inventory->quantity, 3);
        $reserved = round((float) $inventory->reserved_quantity, 3);
        $next = round($current - $quantity, 3);

        if ($next < $reserved || $next < 0) {
            throw ValidationException::withMessages([
                'returned_quantity' => [
                    sprintf(
                        'Returning %s would reduce stock below reserved quantity %s.',
                        $line->returned_quantity,
                        $inventory->reserved_quantity,
                    ),
                ],
            ]);
        }

        $inventory->forceFill(['quantity' => $next])->save();

        $movement = StockMovement::query()->create([
            'inventory_id' => $inventory->getKey(),
            'store_id' => $storeId,
            'user_id' => $actor->getKey(),
            'type' => 'purchase_return',
            'quantity' => -$quantity,
            'reference_type' => 'enterprise_purchase_return_line',
            'reference_id' => $line->getKey(),
            'reason' => sprintf(
                'Purchase return %s / GRN %s',
                $return->return_number,
                $return->source_receipt_number,
            ),
        ]);

        $line->forceFill([
            'inventory_id' => $inventory->getKey(),
            'stock_movement_id' => $movement->getKey(),
        ])->save();
    }
}
