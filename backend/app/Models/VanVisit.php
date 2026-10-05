<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VanVisit extends Model
{
    protected $guarded = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function noOrderReason(): BelongsTo
    {
        return $this->belongsTo(VanNoOrderReason::class, 'no_order_reason_id');
    }

    protected function casts(): array
    {
        return [
            'planned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
