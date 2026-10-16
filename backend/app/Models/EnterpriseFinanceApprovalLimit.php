<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFinanceApprovalLimit extends Model
{
    protected $table = 'enterprise_finance_approval_limits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_amount' => 'decimal:3',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<EnterpriseFinanceApprovalDelegation, $this> */
    public function delegations(): HasMany
    {
        return $this->hasMany(EnterpriseFinanceApprovalDelegation::class, 'approval_limit_id');
    }
}
