<?php

namespace App\Domain\SmartOrdering;

use Carbon\CarbonImmutable;

final readonly class RestaurantPaymentProviderResult
{
    /** @param array<string,mixed> $safeMetadata */
    public function __construct(
        public bool $succeeded,
        public ?string $providerReference = null,
        public ?string $providerStatus = null,
        public array $safeMetadata = [],
        public ?string $settlementGrossAmount = null,
        public ?string $settlementFeeAmount = null,
        public ?string $settlementNetAmount = null,
        public ?CarbonImmutable $settledAt = null,
    ) {}
}
