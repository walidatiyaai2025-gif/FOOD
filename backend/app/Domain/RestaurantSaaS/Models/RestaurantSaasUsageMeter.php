<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $meter_key
 * @property int $quantity
 * @property string $period_key
 * @property string $idempotency_key
 * @property string|null $source_reference
 * @property Carbon $occurred_at
 */
final class RestaurantSaasUsageMeter extends Model
{
    protected $table = 'restaurant_saas_usage_meters';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
