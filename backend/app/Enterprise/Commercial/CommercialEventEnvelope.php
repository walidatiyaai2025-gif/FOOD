<?php

namespace App\Enterprise\Commercial;

final readonly class CommercialEventEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        public int $version,
        public int $storeId,
        public string $channel,
        public string $aggregateType,
        public int $aggregateId,
        public array $payload,
    ) {}
}
