<?php

namespace App\Domain\SmartOrdering;

use Carbon\CarbonImmutable;

final readonly class RestaurantPaymentWebhookResult
{
    /** @param array<string,mixed> $safeMetadata */
    public function __construct(
        public string $eventId,
        public string $providerReference,
        public string $operation,
        public string $paymentStatus,
        public ?string $amount = null,
        public ?string $providerStatus = null,
        public array $safeMetadata = [],
        public ?string $settlementGrossAmount = null,
        public ?string $settlementFeeAmount = null,
        public ?string $settlementNetAmount = null,
        public ?CarbonImmutable $settledAt = null,
    ) {}
}
