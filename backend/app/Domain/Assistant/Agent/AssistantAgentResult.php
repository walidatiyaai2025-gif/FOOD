<?php

namespace App\Domain\Assistant\Agent;

use App\Domain\Assistant\Data\AssistantToolResult;

final readonly class AssistantAgentResult
{
    /**
     * @param list<string> $toolKeys
     * @param list<array<string,mixed>> $evidence
     */
    public function __construct(
        public string $message,
        public AssistantToolResult $toolResult,
        public array $toolKeys,
        public array $evidence,
        public bool $partial,
        public ?string $uncertainty,
        public int $steps,
        public int $toolCalls,
    ) {}
}
