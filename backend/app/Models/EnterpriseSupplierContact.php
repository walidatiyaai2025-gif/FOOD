<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseSupplierContact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }
}
