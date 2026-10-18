<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantProviderHealth
{
    public function __construct(
        public string $provider,
        public string $model,
        public bool $enabled,
        public bool $reachable,
        public bool $healthy,
        public int $latencyMs,
        public ?int $statusCode = null,
        public ?string $failureReason = null,
    ) {}
}
