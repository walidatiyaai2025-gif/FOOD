<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseTreasuryChequeTransition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<EnterpriseTreasuryCheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(EnterpriseTreasuryCheque::class, 'cheque_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<EnterpriseWorkflowRequest, $this> */
    public function workflowRequest(): BelongsTo
    {
        return $this->belongsTo(EnterpriseWorkflowRequest::class, 'workflow_request_id');
    }
}
