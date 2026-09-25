<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAppSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['maintenance_mode' => 'boolean'];
    }
}
