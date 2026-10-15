<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmartOrderingQrVisit extends Model
{
    protected $table = 'smart_ordering_qr_visits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'landing_at' => 'datetime',
            'storefront_viewed_at' => 'datetime',
            'cart_started_at' => 'datetime',
            'checkout_started_at' => 'datetime',
            'order_created_at' => 'datetime',
            'revenue' => 'decimal:3',
        ];
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingQrCode::class, 'qr_code_id');
    }

    public function qrToken(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingQrToken::class, 'qr_token_id');
    }
}
