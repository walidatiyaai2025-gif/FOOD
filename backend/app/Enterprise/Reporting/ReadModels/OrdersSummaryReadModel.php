<?php

namespace App\Enterprise\Reporting\ReadModels;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrdersSummaryReadModel
{
    /**
     * @return array{
     *   rows: list<array{
     *     date: string,
     *     status: string,
     *     channel: string,
     *     currency: string,
     *     order_count: int,
     *     gross_total: string,
     *     average_order_value: string
     *   }>,
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
            ->selectRaw(
                'DATE(created_at) as report_date, status, channel, currency, '
                .'COUNT(*) as order_count, COALESCE(SUM(grand_total), 0) as gross_total',
            )
            ->where('store_id', $scope->id)
            ->where('created_at', '>=', $from->startOfDay())
            ->where('created_at', '<', $to->addDay()->startOfDay())
            ->when(
                array_key_exists('status', $query->filters),
                fn ($builder) => $builder->where('status', (string) $query->filters['status']),
            )
            ->when(
                array_key_exists('channel', $query->filters),
                fn ($builder) => $builder->where('channel', (string) $query->filters['channel']),
            )
            ->when(
                array_key_exists('currency', $query->filters),
                fn ($builder) => $builder->where('currency', strtoupper((string) $query->filters['currency'])),
            )
            ->groupByRaw('DATE(created_at), status, channel, currency')
            ->orderByDesc('report_date')
            ->orderBy('status')
            ->orderBy('channel')
            ->orderBy('currency')
            ->offset(($query->page - 1) * $query->perPage)
            ->limit($query->perPage + 1);

        $rows = $builder->get();
        $hasMore = $rows->count() > $query->perPage;

        return [
            'rows' => $rows
                ->take($query->perPage)
                ->map(static function (\\stdClass $row): array {
                    $count = (int) $row->order_count;
                    $gross = (float) $row->gross_total;

                    return [
                        'date' => (string) $row->report_date,
                        'status' => (string) $row->status,
                        'channel' => (string) $row->channel,
                        'currency' => (string) $row->currency,
                        'order_count' => $count,
                        'gross_total' => self::money($gross),
                        'average_order_value' => self::money($count > 0 ? $gross / $count : 0),
                    ];
                })
                ->values()
                ->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'page' => $query->page,
            'per_page' => $query->perPage,
            'has_more' => $hasMore,
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
