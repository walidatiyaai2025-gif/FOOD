<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpdateHistory extends Model
{
    protected $table = 'update_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'maintenance_mode_remained' => 'boolean',
            'health_verified_at' => 'datetime',
            'execution_evidence' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
