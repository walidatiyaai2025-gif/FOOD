<?php

namespace App\Domain\Assistant\Data;

final readonly class AgentTurnResult
{
    public const COMPLETE = 'COMPLETE';

    public const NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';

    public const DEGRADED = 'DEGRADED';

    public const DENIED = 'DENIED';

    public const SOURCE_AI = 'ai';

    public const SOURCE_DATABASE = 'database';

    /**
     * @param  list<array<string,mixed>>  $toolExecutions
     * @param  list<array<string,mixed>>  $evidence
     * @param  array<string,mixed>  $scopeSummary
     * @param  array<string,mixed>  $providerMetadata
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

    public function responseSource(): string
    {
        if ($this->toolResult !== null || $this->toolExecutions !== [] || $this->evidence !== []) {
            return self::SOURCE_DATABASE;
        }

        return ($this->providerMetadata['ok'] ?? false) === true
            ? self::SOURCE_AI
            : self::SOURCE_DATABASE;
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return [
            'status' => $this->status,
            'response_source' => $this->responseSource(),
            'tool_executions' => $this->toolExecutions,
            'evidence' => $this->evidence,
            'scope' => $this->scopeSummary,
            'provider' => $this->providerMetadata,
            'model_sql_allowed' => false,
            'chain_of_thought_stored' => false,
        ];
    }
}
