<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalConfigurationAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_value' => 'array',
            'after_value' => 'array',
        ];
    }
}
