<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VanAssignment extends Model
{
    protected $guarded = [];

    public function van(): BelongsTo
    {
        return $this->belongsTo(Van::class);
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }
}
