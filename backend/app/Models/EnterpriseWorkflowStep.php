<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseWorkflowStep extends Model
{
    protected $table = 'enterprise_workflow_steps';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'allow_requester_decision' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseWorkflowRequest, $this> */
    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWorkflowRequest::class, 'workflow_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
