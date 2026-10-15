<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmartOrderingMenu extends Model
{
    protected $guarded = [];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingRevision::class, 'revision_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingSite::class, 'site_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SmartOrderingMenuCategory::class, 'menu_id')->orderBy('sort_order');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_visible' => 'boolean'];
    }
}
