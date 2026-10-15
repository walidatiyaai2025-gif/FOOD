<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingMenuCategory extends Model
{
    protected $guarded = [];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingMenu::class, 'menu_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmartOrderingMenuItem::class, 'category_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sold_out' => 'boolean',
            'availability_schedule' => 'array',
        ];
    }
}
