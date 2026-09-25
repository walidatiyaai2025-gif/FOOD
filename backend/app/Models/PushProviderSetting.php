<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushProviderSetting extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'credentials_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials_encrypted' => 'encrypted:array',
        ];
    }
}
