<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterprisePurchaseOrderLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<EnterprisePurchaseRequestLine, $this> */
    public function sourcePurchaseRequestLine(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseRequestLine::class, 'source_purchase_request_line_id');
    }
}
