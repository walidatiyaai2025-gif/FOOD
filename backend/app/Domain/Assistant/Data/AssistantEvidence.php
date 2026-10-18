<?php

namespace App\Domain\Assistant\Data;

final readonly class AssistantEvidence
{
    /**
     * @param  list<int>  $storeIds
     * @param  array<string,mixed>|null  $window
     * @param  array<string,mixed>|null  $freshness
     */
    public function __construct(
        public string $evidenceId,
        public string $callId,
        public string $sourceType,
        public string $sourceName,
        public string $sourceVersion,
        public string $dataClass,
        public ?string $channel,
        public array $storeIds,
        public ?array $window = null,
        public ?array $freshness = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'evidence_id' => $this->evidenceId,
            'call_id' => $this->callId,
            'source_type' => $this->sourceType,
            'source_name' => $this->sourceName,
            'source_version' => $this->sourceVersion,
            'data_class' => $this->dataClass,
            'channel' => $this->channel,
            'store_ids' => $this->storeIds,
            'window' => $this->window,
            'freshness' => $this->freshness,
        ];
    }
}
