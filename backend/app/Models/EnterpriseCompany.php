<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseCompany extends Model
{
    protected $table = 'enterprise_companies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<EnterpriseOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(EnterpriseOrganization::class, 'organization_id');
    }

    /** @return HasMany<EnterpriseLegalEntity, $this> */
    public function legalEntities(): HasMany
    {
        return $this->hasMany(EnterpriseLegalEntity::class, 'company_id');
    }
}
