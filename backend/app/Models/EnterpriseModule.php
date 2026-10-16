<?php

namespace App\Models;

use App\Enterprise\Modules\ModuleState;
use Illuminate\Database\Eloquent\Model;

class EnterpriseModule extends Model
{
    protected $table = 'enterprise_modules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manifest_version' => 'integer',
            'manifest' => 'array',
            'state' => ModuleState::class,
            'enabled_at' => 'datetime',
            'state_changed_at' => 'datetime',
        ];
    }
}
