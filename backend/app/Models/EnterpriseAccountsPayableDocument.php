<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseAccountsPayableDocument extends Model
{
    protected $table = 'enterprise_ap_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:3',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }

    /** @return HasMany<EnterpriseAccountsPayableAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(EnterpriseAccountsPayableAllocation::class, 'ap_document_id');
    }
}
