<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerServiceCase extends Model
{
    protected $table = 'customer_service_cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attachment_metadata' => 'array',
            'sla_target_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'feedback_consent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PlatformCustomer::class, 'platform_customer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }
}
