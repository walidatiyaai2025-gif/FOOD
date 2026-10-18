<?php

namespace App\Domain\Analytics\Semantic;

final readonly class SemanticMetricObservation
{
    /**
     * @param array<string, mixed> $scope
     */
    public function __construct(
        public SemanticMetricDefinition $definition,
        public int|float|null $value,
        public ?string $currency,
        public string $from,
        public string $to,
        public string $timezone,
        public string $computedAt,
        public array $scope,
        public string $coverage = 'complete',
        public string $freshness = 'current',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(string $locale = 'en'): array
    {
        return [
            ...$this->definition->toArray($locale),
            'value' => $this->value,
            'currency' => $this->currency,
            'window' => [
                'from' => $this->from,
                'to' => $this->to,
                'timezone' => $this->timezone,
            ],
            'computed_at' => $this->computedAt,
            'scope' => $this->scope,
            'coverage' => $this->coverage,
            'freshness' => $this->freshness,
            'provenance' => [
                'source' => $this->definition->source,
                'formula' => $this->definition->formula,
            ],
        ];
    }
}
