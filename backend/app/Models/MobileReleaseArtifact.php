<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MobileReleaseArtifact extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expected_bytes' => 'integer',
        'downloaded_bytes' => 'integer',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
    ];

    public function isReady(): bool
    {
        return $this->status === 'ready'
            && is_string($this->local_path)
            && $this->local_path !== '';
    }
}
