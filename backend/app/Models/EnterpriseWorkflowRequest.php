<?php

namespace App\Models;

use App\Enterprise\Workflow\WorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseWorkflowRequest extends Model
{
    protected $table = 'enterprise_workflow_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition_version' => 'integer',
            'scope_id' => 'integer',
            'context' => 'array',
            'status' => WorkflowStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return HasMany<EnterpriseWorkflowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(EnterpriseWorkflowStep::class, 'workflow_request_id');
    }
}
