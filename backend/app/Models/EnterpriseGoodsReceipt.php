<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseGoodsReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }

    /** @return HasMany<EnterpriseGoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterpriseGoodsReceiptLine::class, 'goods_receipt_id');
    }
}
