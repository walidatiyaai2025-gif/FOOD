<?php

namespace App\Domain\WhatsApp;

final readonly class ConnectionValidationResult
{
    /** @param list<string> $capabilities */
    public function __construct(
        public bool $valid,
        public ConnectionHealthStatus $health,
        public ?string $accountIdentity,
        public ?string $phoneIdentity,
        public bool $providerAvailable,
        public array $capabilities,
        public string $diagnosticCode,
        public string $message,
        public \DateTimeImmutable $checkedAt,
    ) {}
}
