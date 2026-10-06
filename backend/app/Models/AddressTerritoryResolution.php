<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressTerritoryResolution extends Model
{
    protected $guarded = [];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(ServiceTerritory::class, 'service_territory_id');
    }

    protected function casts(): array
    {
        return [
            'candidate_territory_ids' => 'array',
            'trace' => 'array',
            'resolved_at' => 'datetime',
            'override_expires_at' => 'datetime',
        ];
    }
}
