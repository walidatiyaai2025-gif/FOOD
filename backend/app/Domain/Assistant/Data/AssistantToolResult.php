<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $cards
     * @param array<int, AssistantAction> $actions
     * @param array<int, array<string, mixed>> $references
     */
    public function __construct(
        public array $data = [],
        public array $cards = [],
        public array $actions = [],
        public array $references = [],
    )
    {}
}


