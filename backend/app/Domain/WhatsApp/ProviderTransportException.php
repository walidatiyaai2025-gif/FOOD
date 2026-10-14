<?php

namespace App\Domain\WhatsApp;

use RuntimeException;

final class ProviderTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly bool $ambiguous = false,
    ) {
        parent::__construct($message);
    }
}
