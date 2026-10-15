<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOrderingOrderContext extends Model
{
    protected $table = 'smart_ordering_order_contexts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'delivery_address' => 'array',
            'cart_snapshot' => 'array',
            'delivery_latitude' => 'float',
            'delivery_longitude' => 'float',
            'subtotal' => 'float',
            'discount_total' => 'float',
            'delivery_total' => 'float',
            'service_charge_total' => 'float',
            'tax_total' => 'float',
            'grand_total' => 'float',
        ];
    }
}
