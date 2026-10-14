<?php

namespace App\Domain\WhatsApp\Data;

final readonly class ChatbotActionRequest
{
    /** @param array<string, scalar|null> $entities */
    public function __construct(
        public string $intent,
        public float $confidence,
        public string $requestedAction,
        public bool $requiresConfirmation,
        public array $entities = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'requested_action' => $this->requestedAction,
            'requires_confirmation' => $this->requiresConfirmation,
            'entities' => $this->entities,
        ];
    }
}
