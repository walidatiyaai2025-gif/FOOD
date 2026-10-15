<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EnterpriseFinanceLedgerEntry extends Model
{
    protected $table = 'enterprise_finance_ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'debit' => 'decimal:3',
            'credit' => 'decimal:3',
            'posted_at' => 'datetime',
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

    /** @return BelongsTo<EnterpriseFiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFiscalPeriod::class, 'fiscal_period_id');
    }
}
