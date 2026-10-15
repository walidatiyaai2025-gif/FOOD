<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<string, list<string>|null> $entitlements
 * @property array<string, mixed>|null $limits
 * @property string $billing_interval
 * @property int $price_minor
 * @property string $currency
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property bool $is_active
 */
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
