<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCouponRedemption extends Model
{
    protected $table = 'marketing_coupon_redemptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:3',
            'discount_amount' => 'decimal:3',
            'redeemed_at' => 'datetime',
        ];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(MarketingCoupon::class, 'coupon_id');
    }
}
