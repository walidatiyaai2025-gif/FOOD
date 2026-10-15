<?php

namespace App\Domain\SmartOrdering\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOrderingModifierOption extends Model
{
    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingModifierGroup::class, 'group_id');
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:3',
            'is_default' => 'boolean',
            'is_visible' => 'boolean',
            'sold_out' => 'boolean',
        ];
    }
}
