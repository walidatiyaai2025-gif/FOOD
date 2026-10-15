<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;

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
