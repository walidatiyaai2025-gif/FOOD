<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlashReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'selling_quantity' => 'decimal:3',
            'reserved_base_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'inventory_allocations' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
