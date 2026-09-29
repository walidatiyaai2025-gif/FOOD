<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCoupon extends Model
{
    protected $table = 'marketing_coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:3',
            'minimum_order_amount' => 'decimal:3',
            'maximum_discount_amount' => 'decimal:3',
            'first_order_only' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'used_count' => 'integer',
            'usage_limit_total' => 'integer',
            'usage_limit_per_user' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(MarketingCouponRedemption::class, 'coupon_id');
    }
}
