<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantProviderCapabilities
{
    /**
     * @param  array<int, string>  $fallbackModels
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $fallbackModels,
        public bool $chat,
        public bool $structuredJson,
        public bool $toolCalls,
        public int $contextWindowTokens,
        public int $maxOutputTokens,
        public int $timeoutSeconds,
        public bool $reachable,
        public bool $agentCapable,
    ) {}
}
