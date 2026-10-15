<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseLegalEntity extends Model
{
    protected $table = 'enterprise_legal_entities';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<EnterpriseCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCompany::class, 'company_id');
    }

    /** @return HasMany<EnterpriseBusinessUnit, $this> */
    public function businessUnits(): HasMany
    {
        return $this->hasMany(EnterpriseBusinessUnit::class, 'legal_entity_id');
    }
}
