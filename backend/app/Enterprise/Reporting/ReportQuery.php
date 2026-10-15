<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class ReportQuery
{
    /** @param array<string, scalar|null> $filters */
    private function __construct(
        public ReportDefinition $report,
        public array $filters,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public int $page,
        public int $perPage,
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

            $normalized[$key] = $value;
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

        return new self($report, $normalized, $from, $to, $page, $perPage);
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
    public function exportHandoff(EntitlementScope $scope): array
    {
        return [
            ...$this->readModelHandoff(),
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'chunk_size' => $this->report->exportChunkSize,
            'queue' => 'enterprise-report-exports',
        ];
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
            return CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Report dates must use YYYY-MM-DD.']]);
        }
    }
}
