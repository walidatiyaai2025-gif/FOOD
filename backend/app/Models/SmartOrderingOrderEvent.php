<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOrderingOrderEvent extends Model
{
    protected $table = 'smart_ordering_order_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
