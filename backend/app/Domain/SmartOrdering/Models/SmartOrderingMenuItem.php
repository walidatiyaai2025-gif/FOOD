<?php

namespace App\Domain\SmartOrdering\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingMenuItem extends Model
{
    protected $guarded = [];

    public function category(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingMenuCategory::class, 'category_id');
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function modifierGroups(): HasMany
    {
        return $this->hasMany(SmartOrderingModifierGroup::class, 'item_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'price_override' => 'decimal:3',
            'featured' => 'boolean',
            'best_seller' => 'boolean',
            'is_new' => 'boolean',
            'is_visible' => 'boolean',
            'sold_out' => 'boolean',
            'availability_schedule' => 'array',
            'allergens' => 'array',
            'vegetarian' => 'boolean',
            'allow_customer_notes' => 'boolean',
            'removable_ingredients' => 'array',
        ];
    }
}
