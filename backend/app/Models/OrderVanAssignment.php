<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVanAssignment extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(ServiceTerritory::class, 'service_territory_id');
    }

    protected function casts(): array
    {
        return [
            'routing_context' => 'array',
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
