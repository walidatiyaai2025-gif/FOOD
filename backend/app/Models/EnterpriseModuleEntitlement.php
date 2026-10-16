<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseModuleEntitlement extends Model
{
    protected $table = 'enterprise_module_entitlements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
        ];
    }
}
