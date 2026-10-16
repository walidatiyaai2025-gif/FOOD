<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseTreasuryIntegrationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'consumer_hints' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseOutboxMessage, $this> */
    public function outboxMessage(): BelongsTo
    {
        return $this->belongsTo(EnterpriseOutboxMessage::class, 'event_id', 'event_id');
    }
}
