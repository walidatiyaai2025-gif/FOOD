<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseSupplier extends Model
{
    protected $table = 'enterprise_suppliers';

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

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return HasMany<EnterpriseSupplierDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EnterpriseSupplierDocument::class, 'supplier_id');
    }

    /** @return HasMany<EnterpriseSupplierContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(EnterpriseSupplierContact::class, 'supplier_id');
    }

    /** @return HasMany<EnterpriseSupplierPortalToken, $this> */
    public function portalTokens(): HasMany
    {
        return $this->hasMany(EnterpriseSupplierPortalToken::class, 'supplier_id');
    }
}
