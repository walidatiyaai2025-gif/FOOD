<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseTreasuryReconciliationLine extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_PAYMENT = 'payment';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'value_date' => 'date',
        ];
    }

    /** @return BelongsTo<EnterpriseTreasuryReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(EnterpriseTreasuryReconciliation::class, 'reconciliation_id');
    }
}
