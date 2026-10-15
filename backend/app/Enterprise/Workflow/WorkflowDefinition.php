<?php

namespace App\Enterprise\Workflow;

use InvalidArgumentException;

final readonly class WorkflowDefinition
{
    /**
     * @param list<WorkflowStepDefinition> $steps
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $steps,
    ) {
        if ($this->key === '' || $this->version < 1 || $this->steps === []) {
            throw new InvalidArgumentException('Workflow key, version and at least one step are required.');
        }

        $codes = array_map(
            static fn (WorkflowStepDefinition $step): string => $step->code,
            $this->steps,
        );

        if (count($codes) !== count(array_unique($codes))) {
            throw new InvalidArgumentException('Workflow step codes must be unique.');
        }
    }
}
