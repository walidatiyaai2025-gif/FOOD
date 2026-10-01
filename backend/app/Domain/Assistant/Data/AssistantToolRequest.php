<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantToolRequest
{
    /**
     * @param  array<string, mixed>  $entities
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $actorUserId,
        public string $locale,
        public ?string $channel = null,
        public ?int $storeId = null,
        public array $entities = [],
        public array $context = [],
    ) {
        // Promoted properties define the complete immutable payload.
    }
}
