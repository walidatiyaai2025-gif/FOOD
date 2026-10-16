<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFinanceOpeningBalanceBatch extends Model
{
    protected $table = 'enterprise_finance_opening_balance_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'total_debit' => 'decimal:3',
            'total_credit' => 'decimal:3',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseFiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFiscalPeriod::class, 'fiscal_period_id');
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }

    /** @return HasMany<EnterpriseFinanceOpeningBalanceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterpriseFinanceOpeningBalanceLine::class, 'batch_id')
            ->orderBy('line_number');
    }
}
