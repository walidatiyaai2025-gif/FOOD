<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseFixedAssetDepreciationRun extends Model
{
    protected $table = 'enterprise_fixed_asset_depreciation_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'book_value_before' => 'decimal:3',
            'book_value_after' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterpriseFixedAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFixedAsset::class, 'asset_id');
    }
}
