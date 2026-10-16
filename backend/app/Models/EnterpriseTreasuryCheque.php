<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseTreasuryCheque extends Model
{
    public const DIRECTION_ISSUED = 'issued';

    public const DIRECTION_RECEIVED = 'received';

    public const REFERENCE_PAYMENT = 'payment';

    public const REFERENCE_RECEIPT = 'receipt';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PRESENTED = 'presented';

    public const STATUS_DEPOSITED = 'deposited';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'issue_date' => 'date',
            'due_date' => 'date',
            'last_transition_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(EnterpriseBankAccount::class, 'bank_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function lastTransitionActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_transition_by');
    }

    /** @return HasMany<EnterpriseTreasuryChequeTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(EnterpriseTreasuryChequeTransition::class, 'cheque_id');
    }
}
