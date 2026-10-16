<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseApprovalLimit extends Model
{
    protected $table = 'enterprise_approval_limits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'max_amount' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
