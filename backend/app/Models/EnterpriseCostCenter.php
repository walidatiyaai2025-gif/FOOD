<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseCostCenter extends Model
{
    protected $table = 'enterprise_cost_centers';

    protected $guarded = [];

    /** @return BelongsTo<EnterpriseOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(EnterpriseOrganization::class, 'organization_id');
    }

    /** @return BelongsTo<EnterpriseCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCompany::class, 'company_id');
    }

    /** @return BelongsTo<EnterpriseLegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(EnterpriseLegalEntity::class, 'legal_entity_id');
    }

    /** @return BelongsTo<EnterpriseCostCenter, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<EnterpriseCostCenter, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
