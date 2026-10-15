<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EnterpriseOutboxMessage extends Model
{
    protected $table = 'enterprise_outbox_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'attempt_count' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
