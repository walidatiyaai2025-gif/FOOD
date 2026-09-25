<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushDeliveryLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
        ];
    }
}
