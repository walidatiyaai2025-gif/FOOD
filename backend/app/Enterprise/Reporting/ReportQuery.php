<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ReportQuery
{
    public const MAX_SORT_FIELDS = 5;

    /**
     * @param  array<string, scalar|null>  $filters
     * @param  list<string>  $dimensions
     * @param  list<string>  $measures
     * @param  list<string>  $groupBy
     * @param  list<array{field: string, direction: string}>  $sort
     */
    private function __construct(
        public ReportDefinition $report,
        public array $filters,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public int $page,
        public int $perPage,
        public array $dimensions,
        public array $measures,
        public array $groupBy,
        public array $sort,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(ReportDefinition $report, array $input): self
    {
        $filters = $input['filters'] ?? [];
        if (is_array($filters) === false) {
            throw ValidationException::withMessages(['filters' => ['Filters must be an object.']]);
        }

        $normalized = [];
        foreach ($filters as $key => $value) {
            if (is_string($key) === false || in_array($key, $report->allowedFilters, true) === false) {
                throw ValidationException::withMessages(['filters' => ['Unsupported report filter.']]);
            }

            if (is_scalar($value) === false && $value !== null) {
                throw ValidationException::withMessages(['filters' => ['Report filters must be scalar values.']]);
            }

            $normalized[$key] = is_string($value) ? trim($value) : $value;
        }

        $from = self::date($input['from'] ?? null, 'from');
        $to = self::date($input['to'] ?? null, 'to');

        if (($from === null) !== ($to === null)) {
            throw ValidationException::withMessages(['date_range' => ['Both from and to are required together.']]);
        }

        if ($from !== null && $to !== null) {
            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages(['date_range' => ['from must not be after to.']]);
            }

            if ($from->diffInDays($to) + 1 > $report->maxRangeDays) {
                throw ValidationException::withMessages(['date_range' => ['Requested report range exceeds the configured bound.']]);
            }
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? min(25, $report->maxPageSize));
        if ($perPage < 1 || $perPage > $report->maxPageSize) {
            throw ValidationException::withMessages(['per_page' => ['Report page size exceeds the configured bound.']]);
        }

        $dimensions = self::selection(
            array_key_exists('dimensions', $input) ? $input['dimensions'] : $report->dimensions,
            $report->dimensions,
            'dimensions',
        );
        $measures = self::selection(
            array_key_exists('measures', $input) ? $input['measures'] : $report->measures,
            $report->measures,
            'measures',
        );

        if ($dimensions === [] && $measures === []) {
            throw ValidationException::withMessages([
                'report_fields' => ['At least one report dimension or measure is required.'],
            ]);
        }

        $groupBy = self::selection(
            array_key_exists('group_by', $input) ? $input['group_by'] : $dimensions,
            $report->dimensions,
            'group_by',
        );

        if (array_values(array_diff($dimensions, $groupBy)) !== []
            || array_values(array_diff($groupBy, $dimensions)) !== []) {
            throw ValidationException::withMessages([
                'group_by' => ['Grouped dimensions must exactly match the selected dimensions.'],
            ]);
        }

        $sort = self::sort(
            $input['sort'] ?? [],
            array_values(array_unique([...$dimensions, ...$measures])),
        );

        return new self(
            $report,
            $normalized,
            $from,
            $to,
            $page,
            $perPage,
            $dimensions,
            $measures,
            $groupBy,
            $sort,
        );
    }

    /** @return array<string, mixed> */
    public function readModelHandoff(): array
    {
        return [
            'report' => $this->report->code,
            'read_model' => $this->report->readModel,
            'filters' => $this->filters,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'drill_down_route' => $this->report->drillDownRoute,
        ];
    }

    /** @return array<string, mixed> */
    public function executionPlan(): array
    {
        return [
            ...$this->readModelHandoff(),
            'dimensions' => $this->dimensions,
            'measures' => $this->measures,
            'group_by' => $this->groupBy,
            'sort' => $this->sort,
        ];
    }

    /** @return array<string, mixed> */
    public function exportHandoff(EntitlementScope $scope): array
    {
        return [
            ...$this->executionPlan(),
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'chunk_size' => $this->report->exportChunkSize,
            'queue' => 'enterprise-report-exports',
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function selection(mixed $value, array $allowed, string $field): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw ValidationException::withMessages([
                $field => ["{$field} must be a list."],
            ]);
        }

        $selected = [];
        foreach ($value as $item) {
            if (is_string($item) === false || trim($item) === '') {
                throw ValidationException::withMessages([
                    $field => ["{$field} may only contain non-empty strings."],
                ]);
            }

            $item = trim($item);
            if (in_array($item, $allowed, true) === false) {
                throw ValidationException::withMessages([
                    $field => ["Unsupported report {$field} field [{$item}]."],
                ]);
            }

            $selected[] = $item;
        }

        return array_values(array_unique($selected));
    }

    /**
     * @param  list<string>  $allowedFields
     * @return list<array{field: string, direction: string}>
     */
    private static function sort(mixed $value, array $allowedFields): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw ValidationException::withMessages(['sort' => ['sort must be a list.']]);
        }

        if (count($value) > self::MAX_SORT_FIELDS) {
            throw ValidationException::withMessages([
                'sort' => ['Too many report sort fields were requested.'],
            ]);
        }

        $sort = [];
        $seen = [];

        foreach ($value as $item) {
            if (is_array($item) === false) {
                throw ValidationException::withMessages([
                    'sort' => ['Each report sort must be an object.'],
                ]);
            }

            $field = trim((string) ($item['field'] ?? ''));
            $direction = strtolower(trim((string) ($item['direction'] ?? 'asc')));

            if ($field === '' || in_array($field, $allowedFields, true) === false) {
                throw ValidationException::withMessages([
                    'sort' => ["Unsupported report sort field [{$field}]."],
                ]);
            }

            if (in_array($direction, ['asc', 'desc'], true) === false) {
                throw ValidationException::withMessages([
                    'sort' => ['Report sort direction must be asc or desc.'],
                ]);
            }

            if (isset($seen[$field])) {
                throw ValidationException::withMessages([
                    'sort' => ["Duplicate report sort field [{$field}]."],
                ]);
            }

            $seen[$field] = true;
            $sort[] = ['field' => $field, 'direction' => $direction];
        }

        return $sort;
    }

    private static function date(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw ValidationException::withMessages([$field => ['Report dates must be strings.']]);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Report dates must use YYYY-MM-DD.']]);
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => ['Report dates must use YYYY-MM-DD.']]);
        }

        return $date;
    }
}
