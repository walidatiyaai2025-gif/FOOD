<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterprisePurchaseRequestLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'estimated_unit_cost' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseRequest::class, 'purchase_request_id');
    }
}
