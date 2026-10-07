<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldOperationConfigurationRevision extends Model
{
    protected $guarded = [];

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(FieldOperationConfiguration::class, 'configuration_id');
    }

    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_revision_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
