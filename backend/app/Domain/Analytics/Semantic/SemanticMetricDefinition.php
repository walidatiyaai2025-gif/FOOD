<?php

namespace App\Domain\Analytics\Semantic;

final readonly class SemanticMetricDefinition
{
    public function __construct(
        public string $key,
        public string $version,
        public string $datasetClass,
        public string $unit,
        public string $labelEn,
        public string $labelAr,
        public string $descriptionEn,
        public string $descriptionAr,
        public string $formula,
        public string $source,
    ) {}

    /** @return array<string, string> */
    public function toArray(string $locale = 'en'): array
    {
        $arabic = strtolower($locale) === 'ar';

        return [
            'key' => $this->key,
            'version' => $this->version,
            'dataset_class' => $this->datasetClass,
            'unit' => $this->unit,
            'label' => $arabic ? $this->labelAr : $this->labelEn,
            'description' => $arabic ? $this->descriptionAr : $this->descriptionEn,
            'formula' => $this->formula,
            'source' => $this->source,
        ];
    }
}
