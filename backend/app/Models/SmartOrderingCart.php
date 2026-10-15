<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmartOrderingCart extends Model
{
    protected $table = 'smart_ordering_carts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
