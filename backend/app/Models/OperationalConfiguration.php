<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class OperationalConfiguration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'schema' => 'array',
            'dependencies' => 'array',
            'revision' => 'integer',
            'is_emergency' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function isEffectiveAt(Carbon $at): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        if ($this->effective_from !== null && $this->effective_from->isAfter($at)) {
            return false;
        }

        return $this->effective_until === null || $this->effective_until->isAfter($at);
    }
}
