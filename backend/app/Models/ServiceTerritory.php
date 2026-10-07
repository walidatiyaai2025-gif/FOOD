<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTerritory extends Model
{
    protected $guarded = [];

    public function country(): BelongsTo
    {
        return $this->belongsTo(GeographyNode::class, 'country_node_id');
    }

    public function geometries(): HasMany
    {
        return $this->hasMany(TerritoryGeometry::class);
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'service_calendar' => 'array',
            'tags' => 'array',
            'capabilities' => 'array',
        ];
    }
}
