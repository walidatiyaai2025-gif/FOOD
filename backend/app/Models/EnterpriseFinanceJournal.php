<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFinanceJournal extends Model
{
    protected $table = 'enterprise_finance_journals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseFiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFiscalPeriod::class, 'fiscal_period_id');
    }

    /** @return HasMany<EnterpriseFinanceJournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterpriseFinanceJournalLine::class, 'journal_id')->orderBy('line_no');
    }

    /** @return HasMany<EnterpriseFinanceLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(EnterpriseFinanceLedgerEntry::class, 'journal_id')->orderBy('id');
    }
}
