<?php

namespace App\Domain\WhatsApp;

final readonly class ProviderSendResult
{
    public function __construct(
        public string $providerMessageId,
        public string $status = 'sent',
    ) {}
}
