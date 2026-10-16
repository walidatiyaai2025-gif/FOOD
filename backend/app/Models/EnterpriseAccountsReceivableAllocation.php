<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseAccountsReceivableAllocation extends Model
{
    protected $table = 'enterprise_ar_allocations';

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

    /** @return BelongsTo<EnterpriseAccountsReceivableDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EnterpriseAccountsReceivableDocument::class, 'ar_document_id');
    }

    /** @return BelongsTo<EnterpriseFinanceJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceJournal::class, 'journal_id');
    }
}
