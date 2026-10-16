<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseOrganization extends Model
{
    protected $table = 'enterprise_organizations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<EnterpriseCompany, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(EnterpriseCompany::class, 'organization_id');
    }
}
