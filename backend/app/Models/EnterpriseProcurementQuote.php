<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseProcurementQuote extends Model
{
    protected $table = 'enterprise_procurement_quotes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:3',
            'valid_until' => 'date',
            'submitted_at' => 'datetime',
            'awarded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseProcurementRfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(EnterpriseProcurementRfq::class, 'rfq_id');
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }
}
