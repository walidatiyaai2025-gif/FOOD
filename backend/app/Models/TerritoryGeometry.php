<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryGeometry extends Model
{
    protected $guarded = [];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(ServiceTerritory::class, 'service_territory_id');
    }

    protected function casts(): array
    {
        return [
            'geojson' => 'array',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }
}
