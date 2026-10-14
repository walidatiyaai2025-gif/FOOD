<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppCall extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'remote_sdp' => 'encrypted',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_event_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }
}
