<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashOffer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_allocation_base' => 'decimal:3',
            'per_customer_limit_base' => 'decimal:3',
            'counts_toward_normal_quota' => 'boolean',
            'stackable' => 'boolean',
            'kill_switch' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(FlashOfferProduct::class);
    }
}
