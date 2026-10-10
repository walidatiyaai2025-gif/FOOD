<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVanExecutionState extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OrderVanAssignment::class, 'order_van_assignment_id');
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_transition_at' => 'datetime',
        ];
    }
}
