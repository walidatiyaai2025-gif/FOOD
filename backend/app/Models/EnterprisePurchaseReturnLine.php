<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterprisePurchaseReturnLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseReturn::class, 'purchase_return_id');
    }

    /** @return BelongsTo<EnterpriseGoodsReceiptLine, $this> */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(EnterpriseGoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    /** @return BelongsTo<EnterprisePurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
