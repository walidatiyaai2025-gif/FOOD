<?php

namespace App\Domain\Assistant\Data;

final readonly class AgentExecutionBudget
{
    public function __construct(
        public int $maxSteps = 4,
        public int $maxProviderCalls = 2,
        public int $maxTotalResultBytes = 65536,
        public int $maxPerToolResultBytes = 16384,
        public int $maxPlanningTokens = 768,
        public int $maxSynthesisTokens = 768,
        public int $maxElapsedMs = 15000,
    ) {
        if ($maxSteps < 1 || $maxSteps > 8) {
            throw new \InvalidArgumentException('Agent maxSteps must be between 1 and 8.');
        }
        if ($maxProviderCalls < 1 || $maxProviderCalls > 3) {
            throw new \InvalidArgumentException('Agent maxProviderCalls must be between 1 and 3.');
        }
        if ($maxTotalResultBytes < 4096 || $maxPerToolResultBytes < 1024) {
            throw new \InvalidArgumentException('Agent result byte budgets are too small.');
        }
        if ($maxElapsedMs < 1000 || $maxElapsedMs > 60000) {
            throw new \InvalidArgumentException('Agent maxElapsedMs must be between 1000 and 60000.');
        }
    }
}
