<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EnterprisePurchaseRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }

    /** @return HasOne<EnterprisePurchaseOrder, $this> */
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(EnterprisePurchaseOrder::class, 'purchase_request_id');
    }

    /** @return HasMany<EnterprisePurchaseRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterprisePurchaseRequestLine::class, 'purchase_request_id');
    }
}
