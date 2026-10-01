<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantBrainResult
{
    /**
     * @param array<int, array<string, mixed>> $cards
     * @param array<int, AssistantAction> $actions
     * @param array<int, string> $suggestedPrompts
     * @param array<string, mixed> $state
     */
    public function __construct(
        public string $message,
        public ?string $intent,
        public float $confidence,
        public array $cards = [],
        public array $actions = [],
        public array $suggestedPrompts = [],
        public array $state = [],
    ) {
        // Constructor promotion defines the complete immutable payload.
    }
}



