<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseProcurementContract extends Model
{
    protected $table = 'enterprise_procurement_contracts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'ceiling_amount' => 'decimal:3',
            'ceiling_quantity' => 'decimal:3',
            'activated_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }
}
