<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseProcurementRfq extends Model
{
    protected $table = 'enterprise_procurement_rfqs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseRequest, $this> */
    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseRequest::class, 'purchase_request_id');
    }

    /** @return BelongsTo<EnterpriseProcurementQuote, $this> */
    public function awardedQuote(): BelongsTo
    {
        return $this->belongsTo(EnterpriseProcurementQuote::class, 'awarded_quote_id');
    }

    /** @return HasMany<EnterpriseProcurementQuote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(EnterpriseProcurementQuote::class, 'rfq_id');
    }
}
