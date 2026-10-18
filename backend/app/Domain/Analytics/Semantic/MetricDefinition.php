<?php

namespace App\Domain\Analytics\Semantic;

final readonly class MetricDefinition
{
    /**
     * @param  list<string>  $flows
     * @param  list<string>  $dimensions
     * @param  array{en:string,ar:string}  $labels
     * @param  array{en:string,ar:string}  $explanations
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $flows,
        public string $unit,
        public string $formula,
        public string $source,
        public array $dimensions,
        public array $labels,
        public array $explanations,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(string $locale = 'en'): array
    {
        $locale = strtolower($locale) === 'ar' ? 'ar' : 'en';

        return [
            'id' => $this->id,
            'version' => $this->version,
            'flows' => $this->flows,
            'unit' => $this->unit,
            'formula' => $this->formula,
            'source' => $this->source,
            'dimensions' => $this->dimensions,
            'label' => $this->labels[$locale],
            'explanation' => $this->explanations[$locale],
        ];
    }
}
