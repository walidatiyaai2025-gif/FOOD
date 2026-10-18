<?php

namespace App\Domain\Assistant\Data;

final readonly class AgentTurnRequest
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $state
     */
    public function __construct(
        public int $actorUserId,
        public string $userMessage,
        public string $locale,
        public AiPrincipalContext $principal,
        public string $correlationId,
        public array $context = [],
        public array $state = [],
        public ?string $conversationPublicId = null,
        public AgentExecutionBudget $budget = new AgentExecutionBudget,
    ) {}
}
