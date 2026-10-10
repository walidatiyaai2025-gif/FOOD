<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $before_state
 * @property array<string, mixed> $after_state
 * @property array<string, mixed>|null $rollback_state
 * @property Carbon|null $rolled_back_at
 */
class B2BVanCutoverSnapshot extends Model
{
    protected $table = 'b2b_van_cutover_snapshots';

    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(B2BVanCutoverRun::class, 'b2b_van_cutover_run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'rollback_state' => 'array',
            'rolled_back_at' => 'datetime',
        ];
    }
}
