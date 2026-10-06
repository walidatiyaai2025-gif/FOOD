<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashOffer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience_customer_ids' => 'array',
            'audience_customer_group_ids' => 'array',
            'audience_regions' => 'array',
            'audience_routes' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_allocation_base' => 'decimal:3',
            'per_customer_limit_base' => 'decimal:3',
            'counts_toward_normal_quota' => 'boolean',
            'stackable' => 'boolean',
            'kill_switch' => 'boolean',
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('starts_at'));
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('ends_at'));
    }

    /** @return HasMany<FlashOfferProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(FlashOfferProduct::class);
    }
}
