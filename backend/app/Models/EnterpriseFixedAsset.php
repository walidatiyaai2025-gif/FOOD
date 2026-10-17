<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFixedAsset extends Model
{
    protected $table = 'enterprise_fixed_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'capitalization_date' => 'date',
            'disposal_date' => 'date',
            'acquisition_amount' => 'decimal:3',
            'salvage_value' => 'decimal:3',
            'declining_rate_percent' => 'decimal:3',
            'disposal_proceeds' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterpriseFixedAssetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFixedAssetCategory::class, 'category_id');
    }

    /** @return HasMany<EnterpriseFixedAssetDepreciationRun, $this> */
    public function depreciationRuns(): HasMany
    {
        return $this->hasMany(EnterpriseFixedAssetDepreciationRun::class, 'asset_id');
    }
}
