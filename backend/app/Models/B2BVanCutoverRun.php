<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $rolled_back_at
 */
class B2BVanCutoverRun extends Model
{
    protected $table = 'b2b_van_cutover_runs';

    protected $guarded = [];

    public function snapshots(): HasMany
    {
        return $this->hasMany(B2BVanCutoverSnapshot::class, 'b2b_van_cutover_run_id');
    }

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }
}
