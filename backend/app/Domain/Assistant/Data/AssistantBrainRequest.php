<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantBrainRequest
{
    public function __construct(
        public string $message,
        public string $locale,
        public array $context = [],
        public array $state = [],
        public ?int $actorUserId = null,
        public ?string $correlationId = null,
        public ?string $tenantKey = null,
    ) {}
}
