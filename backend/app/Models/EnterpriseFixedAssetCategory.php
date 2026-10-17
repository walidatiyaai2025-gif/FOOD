<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFixedAssetCategory extends Model
{
    protected $table = 'enterprise_fixed_asset_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_declining_rate_percent' => 'decimal:3'];
    }

    /** @return HasMany<EnterpriseFixedAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(EnterpriseFixedAsset::class, 'category_id');
    }
}
