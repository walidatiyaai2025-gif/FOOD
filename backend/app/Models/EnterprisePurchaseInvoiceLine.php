<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterprisePurchaseInvoiceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:3',
            'invoice_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'line_total' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseInvoice, $this> */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseInvoice::class, 'purchase_invoice_id');
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
}
