<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterprisePurchaseOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseRequest::class, 'purchase_request_id');
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }

    /** @return HasMany<EnterprisePurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterprisePurchaseOrderLine::class, 'purchase_order_id');
    }
}
