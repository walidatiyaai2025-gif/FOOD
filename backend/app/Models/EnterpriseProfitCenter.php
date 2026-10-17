<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseProfitCenter extends Model
{
    protected $table = 'enterprise_profit_centers';

    protected $guarded = [];

    /** @return BelongsTo<EnterpriseProfitCenter, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<EnterpriseProfitCenter, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
