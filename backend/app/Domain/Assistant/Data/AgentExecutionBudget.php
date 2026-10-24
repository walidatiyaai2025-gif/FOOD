<?php

namespace App\Domain\Assistant\Data;

final readonly class AgentExecutionBudget
{
    public function __construct(
        public int $maxSteps = 2,
        public int $maxToolCalls = 4,
        public int $maxProviderCalls = 2,
        public int $maxTotalResultBytes = 65536,
        public int $maxPerToolResultBytes = 16384,
        public int $maxPlanningTokens = 384,
        public int $maxSynthesisTokens = 640,
        public int $maxElapsedMs = 30000,
    ) {
        if ($maxSteps < 1 || $maxSteps > 4) {
            throw new \InvalidArgumentException('Agent maxSteps must be between 1 and 4.');
        }
        if ($maxToolCalls < 1 || $maxToolCalls > 8) {
            throw new \InvalidArgumentException('Agent maxToolCalls must be between 1 and 8.');
        }
        if ($maxProviderCalls < 1 || $maxProviderCalls > 3) {
            throw new \InvalidArgumentException('Agent maxProviderCalls must be between 1 and 3.');
        }
        if ($maxTotalResultBytes < 8192 || $maxPerToolResultBytes < 1024) {
            throw new \InvalidArgumentException('Agent result byte budgets are too small.');
        }
        if ($maxElapsedMs < 3000 || $maxElapsedMs > 30000) {
            throw new \InvalidArgumentException('Agent maxElapsedMs must be between 3000 and 30000.');
        }
    }

    public static function fromConfig(): self
    {
        $maxTotal = min(262144, max(8192, (int) config('assistant.agent.max_result_bytes', 65536)));

        return new self(
            maxSteps: min(4, max(1, (int) config('assistant.agent.max_steps', 2))),
            maxToolCalls: min(8, max(1, (int) config('assistant.agent.max_tool_calls', 4))),
            maxProviderCalls: 2,
            maxTotalResultBytes: $maxTotal,
            maxPerToolResultBytes: max(2048, min(32768, intdiv($maxTotal, 4))),
            maxPlanningTokens: min(1024, max(128, (int) config('assistant.agent.planning_tokens', 384))),
            maxSynthesisTokens: min(1536, max(128, (int) config('assistant.agent.synthesis_tokens', 640))),
            maxElapsedMs: min(30000, max(3000, (int) config('assistant.agent.max_seconds', 30) * 1000)),
        );
    }
}
