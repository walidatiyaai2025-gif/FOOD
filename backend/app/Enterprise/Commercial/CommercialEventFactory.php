<?php

namespace App\Enterprise\Commercial;

use InvalidArgumentException;

final class CommercialEventFactory
{
    public function __construct(
        private readonly CommercialTruthRegistry $truth,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function make(
        string $fact,
        string $channel,
        int $storeId,
        string $aggregateType,
        int $aggregateId,
        array $payload,
    ): CommercialEventEnvelope {
        if ($storeId < 1 || $aggregateId < 1) {
            throw new InvalidArgumentException('Commercial event store and aggregate identifiers must be positive.');
        }

        $aggregateType = trim($aggregateType);
        if ($aggregateType === '') {
            throw new InvalidArgumentException('Commercial event aggregate type is required.');
        }

        $channel = strtolower(trim($channel));
        $authority = $this->truth->authority($fact, $channel);

        return new CommercialEventEnvelope(
            name: $authority['event'],
            version: CommercialTruthRegistry::CONTRACT_VERSION,
            storeId: $storeId,
            channel: $channel,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payload: $payload,
        );
    }
}
