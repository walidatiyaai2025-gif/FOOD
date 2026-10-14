<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global singleton, deliberately independent of store-scoped settings. */
final class WhatsAppModuleState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'test_numbers' => 'array', 'emergency_stop' => 'boolean', 'revision' => 'integer'];
    }
}
