<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOrderingBranch extends Model
{
    protected $table = 'smart_ordering_branches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepting_orders' => 'boolean',
            'supports_dine_in' => 'boolean',
            'supports_pickup' => 'boolean',
            'supports_delivery' => 'boolean',
            'allow_scheduled' => 'boolean',
            'holiday_closed_dates' => 'array',
            'service_charge' => 'float',
        ];
    }
}
