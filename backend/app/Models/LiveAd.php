<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveAd extends Model
{
    protected $table = 'live_ads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_dismissible' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'duration_minutes' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
