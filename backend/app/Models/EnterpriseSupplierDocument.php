<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseSupplierDocument extends Model
{
    protected $table = 'enterprise_supplier_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'date'];
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }
}
