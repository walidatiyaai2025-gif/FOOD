<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseRoleDataScope extends Model
{
    protected $table = 'enterprise_role_data_scopes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
