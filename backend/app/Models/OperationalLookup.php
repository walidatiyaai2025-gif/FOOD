<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalLookup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
