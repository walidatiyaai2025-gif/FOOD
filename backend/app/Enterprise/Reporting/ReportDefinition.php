<?php

namespace App\Enterprise\Reporting;

use Illuminate\Validation\ValidationException;

final readonly class ReportDefinition
{
    /**
     * @param list<string> $dimensions
     * @param list<string> $measures
     * @param list<string> $allowedFilters
     */
    public function __construct(
        public string $code,
        public string $moduleCode,
        public string $viewPermission,
        public string $exportPermission,
        public string $readCapability,
        public string $exportCapability,
        public array $dimensions,
        public array $measures,
        public array $allowedFilters,
        public string $readModel,
        public ?string $drillDownRoute,
        public int $maxRangeDays,
        public int $maxPageSize,
        public int $exportChunkSize,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition, string $key): self
    {
        $code = trim((string) ($definition['code'] ?? $key));
        $moduleCode = trim((string) ($definition['module'] ?? ''));
        $readModel = trim((string) ($definition['read_model'] ?? ''));

        if ($code === '' || $moduleCode === '' || $readModel === '') {
            throw ValidationException::withMessages([
                'report' => ['Report code, module and read_model are required.'],
            ]);
        }

        $maxRangeDays = (int) ($definition['max_range_days'] ?? 31);
        $maxPageSize = (int) ($definition['max_page_size'] ?? 100);
        $exportChunkSize = (int) ($definition['export_chunk_size'] ?? 500);

        if ($maxRangeDays < 1 || $maxRangeDays > 366
            || $maxPageSize < 1 || $maxPageSize > 500
            || $exportChunkSize < 1 || $exportChunkSize > 5000) {
            throw ValidationException::withMessages([
                'report' => ['Report bounds are outside the supported contract.'],
            ]);
        }

        return new self(
            code: $code,
            moduleCode: $moduleCode,
            viewPermission: trim((string) ($definition['view_permission'] ?? 'reports.view')),
            exportPermission: trim((string) ($definition['export_permission'] ?? 'reports.export')),
            readCapability: trim((string) ($definition['read_capability'] ?? 'reports.read')),
            exportCapability: trim((string) ($definition['export_capability'] ?? 'reports.export')),
            dimensions: self::stringList($definition['dimensions'] ?? []),
            measures: self::stringList($definition['measures'] ?? []),
            allowedFilters: self::stringList($definition['allowed_filters'] ?? []),
            readModel: $readModel,
            drillDownRoute: self::nullableString($definition['drill_down_route'] ?? null),
            maxRangeDays: $maxRangeDays,
            maxPageSize: $maxPageSize,
            exportChunkSize: $exportChunkSize,
        );
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_array($value) === false) {
            throw ValidationException::withMessages([
                'report' => ['Report list fields must be arrays.'],
            ]);
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) === false || trim($item) === '') {
                throw ValidationException::withMessages([
                    'report' => ['Report list fields may only contain non-empty strings.'],
                ]);
            }

            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === false) {
            throw ValidationException::withMessages([
                'report' => ['drill_down_route must be a string or null.'],
            ]);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
