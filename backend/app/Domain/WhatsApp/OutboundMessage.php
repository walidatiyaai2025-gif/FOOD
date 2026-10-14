<?php

namespace App\Domain\WhatsApp;

final readonly class OutboundMessage
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $recipient,
        public string $kind,
        public ?string $body,
        public array $metadata,
        public string $idempotencyKey,
    ) {}
}
