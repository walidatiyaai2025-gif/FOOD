<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantProviderRequest
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $allowedTools
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function __construct(
        public string $systemInstructions,
        public string $taskInstructions,
        public string $userContent,
        public array $context = [],
        public array $allowedTools = [],
        public ?array $responseSchema = null,
        public float $temperature = 0.1,
        public int $maxTokens = 512,
        public ?int $timeoutSeconds = null,
        public ?string $correlationId = null,
        public string $useCase = 'agent_planning',
        public ?int $actorUserId = null,
        public ?string $tenantKey = null,
    ) {}
}
