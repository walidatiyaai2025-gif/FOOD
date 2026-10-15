<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseFinanceLedgerEntry extends Model
{
    protected $table = 'enterprise_finance_ledger_entries';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Posted ledger entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Posted ledger entries are immutable.');
        });
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }

    /** @return BelongsTo<EnterpriseFinanceJournalLine, $this> */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournalLine::class, 'journal_line_id');
    }

    /** @return BelongsTo<EnterpriseFinanceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceAccount::class, 'account_id');
    }
}
