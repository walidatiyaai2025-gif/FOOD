<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantProviderResult
{
    /**
     * @param  array<string, mixed>|null  $structuredPayload
     * @param  array<int, array{name:string,arguments:array<string,mixed>,id:?string}>  $toolCalls
     * @param  array<string, int|null>  $usage
     */
    public function __construct(
        public bool $ok,
        public string $provider,
        public string $model,
        public ?string $text = null,
        public ?array $structuredPayload = null,
        public array $toolCalls = [],
        public ?string $finishReason = null,
        public ?string $failureReason = null,
        public array $usage = [],
        public int $latencyMs = 0,
        public bool $fallback = false,
    ) {}
}
