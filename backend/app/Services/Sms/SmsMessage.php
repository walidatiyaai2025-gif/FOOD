<?php

namespace App\Services\Sms;

use Illuminate\Support\Str;

final readonly class SmsMessage
{
    public function __construct(
        public string $recipient,
        public string $message,
        public string $purpose = 'system',
        public string $locale = 'ar',
        public ?string $sender = null,
        public ?int $operatorId = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?int $createdBy = null,
        public bool $isTest = false,
    ) {}

    public function requestId(): string
    {
        return $this->requestId ?? (string) Str::uuid();
    }
}
