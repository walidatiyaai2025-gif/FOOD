<?php

namespace App\Domain\WhatsApp;

use DateTimeImmutable;

final readonly class ProviderWebhookEvent
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $kind,
        public ?string $providerMessageId,
        public ?string $from,
        public ?string $to,
        public ?string $contentType,
        public ?string $body,
        public array $metadata,
        public ?string $deliveryStatus,
        public DateTimeImmutable $occurredAt,
    ) {}
}
