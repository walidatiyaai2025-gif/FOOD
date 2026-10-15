<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseFinanceJournalLine extends Model
{
    protected $table = 'enterprise_finance_journal_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }

    /** @return BelongsTo<EnterpriseFinanceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceAccount::class, 'account_id');
    }
}
