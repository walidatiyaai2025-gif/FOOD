<?php

namespace App\Domain\Assistant\Data;

final readonly class AgentTurnResult
{
    public const COMPLETE = 'COMPLETE';
    public const NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';
    public const DEGRADED = 'DEGRADED';
    public const DENIED = 'DENIED';

    /**
     * @param list<array<string,mixed>> $toolExecutions
     * @param list<array<string,mixed>> $evidence
     * @param array<string,mixed> $scopeSummary
     * @param array<string,mixed> $providerMetadata
     */
    public function __construct(
        public string $message,
        public string $status,
        public AssistantBrainResult $brainResult,
        public ?AssistantToolResult $toolResult,
        public ?AssistantToolRequest $toolRequest,
        public array $toolExecutions = [],
        public array $evidence = [],
        public array $scopeSummary = [],
        public array $providerMetadata = [],
    ) {}

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return [
            'status' => $this->status,
            'tool_executions' => $this->toolExecutions,
            'evidence' => $this->evidence,
            'scope' => $this->scopeSummary,
            'provider' => $this->providerMetadata,
        ];
    }
}
