<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseBusinessUnit extends Model
{
    protected $table = 'enterprise_business_units';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<EnterpriseLegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(EnterpriseLegalEntity::class, 'legal_entity_id');
    }

    /** @return HasMany<EnterpriseStoreScope, $this> */
    public function storeScopes(): HasMany
    {
        return $this->hasMany(EnterpriseStoreScope::class, 'business_unit_id');
    }
}
