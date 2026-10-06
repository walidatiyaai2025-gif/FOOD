<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Remittance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
