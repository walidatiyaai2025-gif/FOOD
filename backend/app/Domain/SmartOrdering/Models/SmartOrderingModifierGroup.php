<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingModifierGroup extends Model
{
    protected $guarded = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingMenuItem::class, 'item_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(SmartOrderingModifierOption::class, 'group_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_visible' => 'boolean'];
    }
}
