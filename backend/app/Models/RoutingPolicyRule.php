<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingPolicyRule extends Model
{
    protected $guarded = [];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(RoutingPolicy::class, 'routing_policy_id');
    }

    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'enabled' => 'boolean'];
    }
}
