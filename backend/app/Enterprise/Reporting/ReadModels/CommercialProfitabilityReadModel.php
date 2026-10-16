<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CommercialProfitabilityReadModel
{
    /** @var array<string, string> */
    private const DIMENSION_SELECTS = [
        'date' => 'DATE(order_created_at) as report_date',
        'product_id' => 'product_id',
        'customer_id' => 'customer_id',
        'store_id' => 'store_id',
        'channel' => 'channel',
        'currency' => 'currency',
        'status' => 'order_status as status',
    ];

    /** @var array<string, string> */
    private const DIMENSION_GROUPS = [
        'date' => 'DATE(order_created_at)',
        'product_id' => 'product_id',
        'customer_id' => 'customer_id',
        'store_id' => 'store_id',
        'channel' => 'channel',
        'currency' => 'currency',
        'status' => 'order_status',
    ];

    /** @var array<string, string> */
    private const SORT_ALIASES = [
        'date' => 'report_date',
        'product_id' => 'product_id',
        'customer_id' => 'customer_id',
        'store_id' => 'store_id',
        'channel' => 'channel',
        'currency' => 'currency',
        'status' => 'status',
        'orders' => 'order_count',
        'gross_revenue' => 'gross_revenue',
        'discount_total' => 'discount_total',
        'net_revenue' => 'net_revenue',
        'cost' => 'cost',
        'margin' => 'margin',
        'unvalued_lines' => 'unvalued_lines',
    ];

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
        if ($query->report->code !== 'commercial.profitability'
            || $query->report->readModel !== 'commercial_profitability') {
            throw ValidationException::withMessages([
                'report' => ['Commercial profitability read model requires the commercial.profitability definition.'],
            ]);
        }

        if ($scope->type !== EntitlementScope::STORE || $scope->id === null) {
            throw ValidationException::withMessages([
                'scope' => ['Commercial profitability requires an explicit store scope.'],
            ]);
        }

        if (! array_key_exists('currency', $query->filters)
            && ! in_array('currency', $query->dimensions, true)) {
            throw ValidationException::withMessages([
                'currency' => ['Profitability totals must either filter one currency or group by currency.'],
            ]);
        }

        $source = $this->source();
        if (Schema::hasTable($source) === false) {
            throw ValidationException::withMessages([
                'source' => ["Profitability read-model source [{$source}] is unavailable."],
            ]);
        }

        $to = $query->to ?? CarbonImmutable::today();
        $from = $query->from ?? $to->subDays($query->report->maxRangeDays - 1);

        $builder = DB::table($source)
            ->where('store_id', $scope->id)
            ->where('order_created_at', '>=', $from->startOfDay())
            ->where('order_created_at', '<', $to->addDay()->startOfDay());

        $this->applyFilters($builder, $query);

        foreach ($query->dimensions as $dimension) {
            $builder->selectRaw(self::DIMENSION_SELECTS[$dimension]);
        }

        foreach ($query->measures as $measure) {
            $builder->selectRaw($this->measureSelect($measure));
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
                ->map(fn (\stdClass $row): array => $this->mapRow(
                    $row,
                    $query,
                    $scope->id,
                    $source,
                    $from,
                    $to,
                ))
                ->values()
                ->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    private function applyFilters(Builder $builder, ReportQuery $query): void
    {
        if (array_key_exists('product_id', $query->filters)) {
            $builder->where(
                'product_id',
                $this->positiveId($query->filters['product_id'], 'product_id'),
            );
        }

        if (array_key_exists('customer_id', $query->filters)) {
            $builder->where(
                'customer_id',
                $this->positiveId($query->filters['customer_id'], 'customer_id'),
            );
        }

        if (array_key_exists('channel', $query->filters)) {
            $channel = strtolower(trim((string) $query->filters['channel']));
            if (! in_array($channel, ['b2b', 'b2c'], true)) {
                throw ValidationException::withMessages([
                    'channel' => ['Profitability channel must be b2b or b2c.'],
                ]);
            }
            $builder->where('channel', $channel);
        }

        if (array_key_exists('currency', $query->filters)) {
            $currency = strtoupper(trim((string) $query->filters['currency']));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw ValidationException::withMessages([
                    'currency' => ['Currency must be a three-letter code.'],
                ]);
            }
            $builder->where('currency', $currency);
        }

        if (array_key_exists('status', $query->filters)) {
            $status = trim((string) $query->filters['status']);
            if ($status === ''
                || mb_strlen($status) > 64
                || preg_match('/^[A-Za-z0-9_-]+$/', $status) !== 1) {
                throw ValidationException::withMessages([
                    'status' => ['Profitability order status is invalid.'],
                ]);
            }
            $builder->where('order_status', $status);
        }
    }

    private function measureSelect(string $measure): string
    {
        return match ($measure) {
            'orders' => 'COUNT(DISTINCT order_id) as order_count',
            'gross_revenue' => 'COALESCE(SUM(gross_revenue), 0) as gross_revenue',
            'discount_total' => 'COALESCE(SUM(discount_amount), 0) as discount_total',
            'net_revenue' => 'COALESCE(SUM(net_revenue), 0) as net_revenue',
            'cost' => "CASE
                WHEN SUM(CASE WHEN cost_state = 'missing' THEN 1 ELSE 0 END) > 0 THEN NULL
                ELSE COALESCE(SUM(cost_total), 0)
            END as cost",
            'margin' => "CASE
                WHEN SUM(CASE WHEN cost_state = 'missing' THEN 1 ELSE 0 END) > 0 THEN NULL
                ELSE COALESCE(SUM(margin), 0)
            END as margin",
            'unvalued_lines' => "SUM(CASE WHEN cost_state = 'missing' THEN 1 ELSE 0 END) as unvalued_lines",
            default => throw ValidationException::withMessages([
                'measures' => ["Unsupported profitability measure [{$measure}]."],
            ]),
        };
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

    /** @return array<string,mixed> */
    private function mapRow(
        \stdClass $row,
        ReportQuery $query,
        int $storeId,
        string $source,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $result = [];

        foreach ($query->dimensions as $dimension) {
            $value = $dimension === 'date'
                ? $row->report_date
                : $row->{$dimension};

            $result[$dimension] = in_array(
                $dimension,
                ['product_id', 'customer_id', 'store_id'],
                true,
            )
                ? (int) $value
                : (string) $value;
        }

        foreach ($query->measures as $measure) {
            $result[$measure] = match ($measure) {
                'orders' => (int) $row->order_count,
                'gross_revenue', 'discount_total', 'net_revenue' => self::money((float) $row->{$measure}),
                'cost', 'margin' => $row->{$measure} === null
                    ? null
                    : self::money((float) $row->{$measure}),
                'unvalued_lines' => (int) $row->unvalued_lines,
                default => throw ValidationException::withMessages([
                    'measures' => ["Unsupported profitability measure [{$measure}]."],
                ]),
            };
        }

        $drillDown = [
            'source' => $source,
            'store_id' => $storeId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        foreach (['date', 'product_id', 'customer_id', 'channel', 'currency', 'status'] as $dimension) {
            if (array_key_exists($dimension, $result)) {
                $drillDown[$dimension] = $result[$dimension];
            }
        }

        $result['drill_down'] = $drillDown;

        return $result;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = (int) $value;
        if ($id < 1) {
            throw ValidationException::withMessages([
                $field => ['Identifier must be a positive integer.'],
            ]);
        }

        return $id;
    }

    private function source(): string
    {
        $source = config('enterprise_reporting.sources.profitability_facts');
        if (! is_string($source) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) !== 1) {
            throw ValidationException::withMessages([
                'source' => ['Invalid configured profitability read-model source.'],
            ]);
        }

        return $source;
    }

    private static function money(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
