<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDispatchState extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(ServiceTerritory::class, 'service_territory_id');
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
