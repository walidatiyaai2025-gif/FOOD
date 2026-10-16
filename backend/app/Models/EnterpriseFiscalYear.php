<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFiscalYear extends Model
{
    protected $table = 'enterprise_fiscal_years';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

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

    /** @return HasMany<EnterpriseFiscalPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(EnterpriseFiscalPeriod::class, 'fiscal_year_id');
    }
}
