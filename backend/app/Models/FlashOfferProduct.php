<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashOfferProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:3',
            'flash_price' => 'decimal:3',
            'allocation_base' => 'decimal:3',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FlashOffer::class, 'flash_offer_id');
    }
}
