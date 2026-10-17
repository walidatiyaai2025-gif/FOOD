<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use App\Services\CustomerServiceCaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CustomerServiceOperationsReadModel
{
    private const REPORTS = [
        'customer_service.case_sla' => 'case_sla',
        'customer_service.feedback' => 'feedback',
        'customer_service.customer_growth' => 'customer_growth',
        'customer_service.recovery' => 'recovery',
        'customer_service.workload' => 'workload',
    ];

    public function __construct(
        private readonly CustomerServiceCaseManager $customerService,
    ) {}

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   from:string,
     *   to:string,
     *   page:int,
     *   per_page:int,
     *   has_more:bool
     * }
     */
    public function read(ReportQuery $query, EntitlementScope $scope): array
    {
        $report = self::REPORTS[$query->report->code] ?? null;
        if ($report === null || $query->report->readModel !== 'customer_service_operations') {
            throw ValidationException::withMessages([
                'report' => ['Customer-service read model received an unsupported report definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Customer-service reporting requires an explicit store scope.'],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today();
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);

        $source = $this->customerService->reportForStore(
            $report,
            $scope->id,
            ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            5000,
            0,
        );

        $rows = collect($source['rows']);

        foreach ($query->filters as $field => $value) {
            $rows = $rows->filter(
                static fn (array $row): bool => array_key_exists($field, $row)
                    && (string) $row[$field] === (string) $value,
            );
        }

        foreach (array_reverse($query->sort) as $sort) {
            $field = $sort['field'];
            $descending = $sort['direction'] === 'desc';
            $rows = $descending
                ? $rows->sortByDesc(static fn (array $row) => $row[$field] ?? null, SORT_NATURAL)
                : $rows->sortBy(static fn (array $row) => $row[$field] ?? null, SORT_NATURAL);
        }

        $offset = ($query->page - 1) * $query->perPage;
        $pageRows = $rows->slice($offset, $query->perPage + 1)->values();
        $hasMore = $pageRows->count() > $query->perPage;

        return [
            'rows' => $pageRows
                ->take($query->perPage)
                ->map(fn (array $row): array => $this->normalizeRow($row, $query))
                ->values()
                ->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeRow(array $row, ReportQuery $query): array
    {
        $result = [];

        foreach ($query->dimensions as $dimension) {
            if (! array_key_exists($dimension, $row)) {
                throw ValidationException::withMessages([
                    'read_model' => ["Customer-service report did not return dimension [{$dimension}]."],
                ]);
            }
            $result[$dimension] = $row[$dimension];
        }

        foreach ($query->measures as $measure) {
            if (! array_key_exists($measure, $row)) {
                throw ValidationException::withMessages([
                    'read_model' => ["Customer-service report did not return measure [{$measure}]."],
                ]);
            }
            $result[$measure] = $row[$measure];
        }

        return $result;
    }
}
