<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /** @param Builder<Product> $query */
    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->whereHas('catalog', static fn (Builder $catalog): Builder => $catalog
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('is_migration_quarantine', false));
    }
}
