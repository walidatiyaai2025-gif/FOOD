<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;

final class RestaurantSaasBillingRecord extends Model
{
    protected $table = 'restaurant_saas_billing_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
