<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RestaurantSaasPlan extends Model
{
    protected $table = 'restaurant_saas_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entitlements' => 'array',
            'limits' => 'array',
            'price_minor' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<RestaurantSaasSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(RestaurantSaasSubscription::class, 'plan_id');
    }
}
