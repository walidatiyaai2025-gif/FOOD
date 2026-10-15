<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseStoreScope extends Model
{
    protected $table = 'enterprise_store_scopes';

    protected $guarded = [];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<EnterpriseBusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseBusinessUnit::class, 'business_unit_id');
    }
}
