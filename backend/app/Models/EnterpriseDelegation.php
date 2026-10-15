<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseDelegation extends Model
{
    protected $table = 'enterprise_delegations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function isEffective(): bool
    {
        $now = now();

        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($now)
            && $this->ends_at->greaterThan($now);
    }
}
