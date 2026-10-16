<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseTreasuryReconciliation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'statement_receipts_total' => 'decimal:3',
            'statement_payments_total' => 'decimal:3',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(EnterpriseBankAccount::class, 'bank_account_id');
    }

    /** @return HasMany<EnterpriseTreasuryReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterpriseTreasuryReconciliationLine::class, 'reconciliation_id');
    }
}
