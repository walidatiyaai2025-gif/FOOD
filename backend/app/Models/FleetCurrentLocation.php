<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetCurrentLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'float',
            'speed' => 'float',
            'heading' => 'float',
            'captured_at' => 'datetime',
            'received_at' => 'datetime',
            'is_mocked' => 'boolean',
        ];
    }
}
