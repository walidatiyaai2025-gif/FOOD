<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseAccountsPayableAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allocation_date' => 'date',
            'amount' => 'decimal:3',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseAccountsPayableDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseAccountsPayableDocument::class, 'ap_document_id');
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }
}
