<?php

namespace App\Enterprise\Workflow;

use InvalidArgumentException;

final readonly class WorkflowStepDefinition
{
    public function __construct(
        public string $code,
        public string $permission,
        public bool $allowRequesterDecision = false,
    ) {
        if ($this->code === '' || $this->permission === '') {
            throw new InvalidArgumentException('Workflow step code and permission are required.');
        }
    }
}
