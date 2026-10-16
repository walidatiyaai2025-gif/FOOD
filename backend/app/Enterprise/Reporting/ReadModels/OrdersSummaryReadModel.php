<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrdersSummaryReadModel
{
    /** @var array<string, string> */
    private const DIMENSION_SELECTS = [
        'date' => 'DATE(created_at) as report_date',
        'status' => 'status',
        'channel' => 'channel',
        'currency' => 'currency',
    ];

    /** @var array<string, string> */
    private const DIMENSION_GROUPS = [
        'date' => 'DATE(created_at)',
        'status' => 'status',
        'channel' => 'channel',
        'currency' => 'currency',
    ];

    /** @var array<string, string> */
    private const MEASURE_SELECTS = [
        'orders' => 'COUNT(*) as order_count',
        'gross_total' => 'COALESCE(SUM(grand_total), 0) as gross_total',
        'average_order_value' => 'CASE WHEN COUNT(*) = 0 THEN 0 ELSE COALESCE(SUM(grand_total), 0) / COUNT(*) END as average_order_value',
    ];

    /** @var array<string, string> */
    private const SORT_ALIASES = [
        'date' => 'report_date',
        'status' => 'status',
        'channel' => 'channel',
        'currency' => 'currency',
        'orders' => 'order_count',
        'gross_total' => 'gross_total',
        'average_order_value' => 'average_order_value',
    ];

    /**
     * @return array{
     *   rows: list<array<string, int|string>>,
     *   from: string,
     *   to: string,
     *   page: int,
     *   per_page: int,
     *   has_more: bool
     * }
     */
    public function read(ReportQuery $query, EntitlementScope $scope): array
    {
        if ($query->report->code !== 'orders.summary'
            || $query->report->readModel !== 'orders_summary_daily') {
            throw ValidationException::withMessages([
                'report' => ['Orders summary read model requires the orders.summary report definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Orders summary requires an explicit store scope.'],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today();
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);

        $builder = DB::table('orders')
            ->where('store_id', $scope->id)
            ->where('created_at', '>=', $from->startOfDay())
            ->where('created_at', '<', $to->addDay()->startOfDay())
            ->when(
                array_key_exists('status', $query->filters),
                fn (Builder $builder) => $builder->where('status', (string) $query->filters['status']),
            )
            ->when(
                array_key_exists('channel', $query->filters),
                fn (Builder $builder) => $builder->where('channel', (string) $query->filters['channel']),
            )
            ->when(
                array_key_exists('currency', $query->filters),
                fn (Builder $builder) => $builder->where('currency', strtoupper((string) $query->filters['currency'])),
            );

        foreach ($query->dimensions as $dimension) {
            $builder->selectRaw(self::DIMENSION_SELECTS[$dimension]);
        }

        foreach ($query->measures as $measure) {
            $builder->selectRaw(self::MEASURE_SELECTS[$measure]);
        }

        foreach ($query->groupBy as $dimension) {
            $builder->groupByRaw(self::DIMENSION_GROUPS[$dimension]);
        }

        $this->applySort($builder, $query);

        $rows = $builder
            ->offset(($query->page - 1) * $query->perPage)
            ->limit($query->perPage + 1)
            ->get();
        $hasMore = $rows->count() > $query->perPage;

        return [
            'rows' => $rows
                ->take($query->perPage)
                ->map(fn (\stdClass $row): array => $this->mapRow($row, $query))
                ->values()
                ->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    private function applySort(Builder $builder, ReportQuery $query): void
    {
        $sorted = [];

        foreach ($query->sort as $sort) {
            $builder->orderBy(self::SORT_ALIASES[$sort['field']], $sort['direction']);
            $sorted[$sort['field']] = true;
        }

        foreach ($query->dimensions as $dimension) {
            if (isset($sorted[$dimension])) {
                continue;
            }

            $builder->orderBy(
                self::SORT_ALIASES[$dimension],
                $dimension === 'date' ? 'desc' : 'asc',
            );
        }
    }

    /** @return array<string, int|string> */
    private function mapRow(\stdClass $row, ReportQuery $query): array
    {
        $result = [];

        foreach ($query->dimensions as $dimension) {
            $result[$dimension] = match ($dimension) {
                'date' => (string) $row->report_date,
                'status' => (string) $row->status,
                'channel' => (string) $row->channel,
                'currency' => (string) $row->currency,
                default => throw ValidationException::withMessages([
                    'dimensions' => ["Unsupported orders summary dimension [{$dimension}]."],
                ]),
            };
        }

        foreach ($query->measures as $measure) {
            $key = $measure === 'orders' ? 'order_count' : $measure;
            $result[$key] = match ($measure) {
                'orders' => (int) $row->order_count,
                'gross_total' => self::money((float) $row->gross_total),
                'average_order_value' => self::money((float) $row->average_order_value),
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported orders summary measure [{$measure}]."],
                ]),
            };
        }

        return $result;
    }

    private static function money(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
