<?php

namespace App\Enterprise\Workflow;

enum WorkflowStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }
}
