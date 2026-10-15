<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmartOrderingBranch extends Model
{
    protected $guarded = [];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingSite::class, 'site_id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
