<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use App\Services\CatalogProductCompletionManager;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class CatalogOperationsReadModel
{
    private const REPORTS = [
        'catalog.availability' => 'availability',
        'catalog.category_brand_performance' => 'category_brand_performance',
        'catalog.margin_exceptions' => 'margin_exceptions',
        'catalog.lifecycle' => 'lifecycle',
        'catalog.data_quality' => 'data_quality',
    ];

    public function __construct(
        private readonly CatalogProductCompletionManager $catalog,
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
        if ($report === null || $query->report->readModel !== 'catalog_operations') {
            throw ValidationException::withMessages([
                'report' => [__('catalog_completion.validation.unsupported_report')],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => [__('catalog_completion.validation.store_scope_required')],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today();
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);
        $offset = ($query->page - 1) * $query->perPage;

        $source = $this->catalog->reportForStore(
            $report,
            $scope->id,
            ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            $query->perPage + 1,
            $offset,
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
            $rows = $sort['direction'] === 'desc'
                ? $rows->sortByDesc(static fn (array $row) => $row[$field] ?? null, SORT_NATURAL)
                : $rows->sortBy(static fn (array $row) => $row[$field] ?? null, SORT_NATURAL);
        }

        $rows = $rows->values();
        $hasMore = $rows->count() > $query->perPage;

        return [
            'rows' => $rows->take($query->perPage)->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }
}
