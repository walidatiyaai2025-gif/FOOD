<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SalesOrderOperationsDashboardService
{
    public const DELAY_HOURS = 24;

    public const EXCEPTIONS = [
        'delayed',
        'awaiting_dispatch',
        'payment',
        'invoice',
        'cancelled_refunded',
    ];

    private const NON_REVENUE_STATUSES = ['cancelled', 'refunded'];

    private const TERMINAL_STATUSES = ['delivered', 'cancelled', 'refunded', 'failed'];

    /**
     * @param  array{b2b:list<int>,b2c:list<int>}  $scopes
     * @return array<string,mixed>
     */
    public function build(
        array $scopes,
        ?int $storeId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        [$fromUtc, $toUtc, $fromLocal, $toLocal] = $this->bounds($fromDate, $toDate);
        $base = $this->orders($scopes, $storeId, $fromUtc, $toUtc);

        $statusBreakdown = (clone $base)
            ->selectRaw('orders.status, COUNT(*) as orders_count')
            ->groupBy('orders.status')
            ->orderByDesc('orders_count')
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'orders' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        $channelBreakdown = (clone $base)
            ->selectRaw('orders.channel, COUNT(*) as orders_count')
            ->groupBy('orders.channel')
            ->orderByDesc('orders_count')
            ->get()
            ->map(static fn (object $row): array => [
                'channel' => (string) $row->channel,
                'orders' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        $currencyTotals = (clone $base)
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->selectRaw('orders.currency, COUNT(*) as orders_count, COALESCE(SUM(orders.grand_total), 0) as revenue')
            ->groupBy('orders.currency')
            ->orderBy('orders.currency')
            ->get()
            ->map(static fn (object $row): array => [
                'currency' => (string) $row->currency,
                'orders' => (int) $row->orders_count,
                'revenue' => round((float) $row->revenue, 3),
            ])
            ->values()
            ->all();

        $singleCurrency = count($currencyTotals) === 1 ? (string) $currencyTotals[0]['currency'] : null;
        $recognizedRevenue = $singleCurrency === null ? null : (float) $currencyTotals[0]['revenue'];
        $recognizedOrders = array_sum(array_column($currencyTotals, 'orders'));
        $averageOrderValue = $singleCurrency === null || $recognizedOrders === 0
            ? null
            : round($recognizedRevenue / $recognizedOrders, 3);

        $nowUtc = CarbonImmutable::now('UTC');
        $delayCutoff = $nowUtc->subHours(self::DELAY_HOURS);

        $metrics = (clone $base)
            ->selectRaw(
                'COUNT(*) as total_orders, '.
                "SUM(CASE WHEN orders.status NOT IN ('delivered','cancelled','refunded','failed') THEN 1 ELSE 0 END) as open_orders, ".
                "SUM(CASE WHEN orders.status NOT IN ('delivered','cancelled','refunded','failed') AND orders.created_at <= ? THEN 1 ELSE 0 END) as delayed_orders, ".
                "SUM(CASE WHEN orders.status IN ('cancelled','refunded') THEN 1 ELSE 0 END) as cancelled_refunded",
                [$delayCutoff],
            )
            ->first();

        $totalOrders = (int) ($metrics->total_orders ?? 0);
        $openOrders = (int) ($metrics->open_orders ?? 0);
        $delayedOrders = (int) ($metrics->delayed_orders ?? 0);
        $cancelledRefunded = (int) ($metrics->cancelled_refunded ?? 0);

        $awaitingDispatchQuery = DB::table('orders')
            ->join('order_dispatch_states', function ($join): void {
                $join->on('order_dispatch_states.order_id', '=', 'orders.id')
                    ->where('order_dispatch_states.status', 'awaiting_dispatch');
            });
        $this->applyScope($awaitingDispatchQuery, $scopes, $storeId, $fromUtc, $toUtc);
        $awaitingDispatch = $awaitingDispatchQuery->count('orders.id');

        $daily = (clone $base)
            ->selectRaw(
                'DATE(orders.created_at) as day, COUNT(*) as orders_count, '.
                "COALESCE(SUM(CASE WHEN orders.status NOT IN ('cancelled','refunded') THEN orders.grand_total ELSE 0 END), 0) as recognized_revenue"
            )
            ->groupByRaw('DATE(orders.created_at)')
            ->orderBy('day')
            ->get()
            ->map(static fn (object $row): array => [
                'day' => (string) $row->day,
                'orders' => (int) $row->orders_count,
                'revenue' => $singleCurrency === null ? null : round((float) $row->recognized_revenue, 3),
            ])
            ->values()
            ->all();

        $paymentProviders = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id');
        $this->applyScope($paymentProviders, $scopes, $storeId, $fromUtc, $toUtc);
        $paymentProviders = $paymentProviders
            ->selectRaw('payments.provider, payments.status, COUNT(*) as payments_count')
            ->groupBy('payments.provider', 'payments.status')
            ->orderByDesc('payments_count')
            ->limit(12)
            ->get()
            ->map(static fn (object $row): array => [
                'provider' => (string) $row->provider,
                'status' => (string) $row->status,
                'payments' => (int) $row->payments_count,
            ])
            ->values()
            ->all();

        $storeBreakdown = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id');
        $this->applyScope($storeBreakdown, $scopes, $storeId, $fromUtc, $toUtc);
        $storeBreakdown = $storeBreakdown
            ->selectRaw('stores.id, stores.name, COUNT(*) as orders_count')
            ->groupBy('stores.id', 'stores.name')
            ->orderByDesc('orders_count')
            ->limit(12)
            ->get()
            ->map(static fn (object $row): array => [
                'store_id' => (int) $row->id,
                'store' => (string) $row->name,
                'orders' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        $topCustomers = DB::table('orders')
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id');
        $this->applyScope($topCustomers, $scopes, $storeId, $fromUtc, $toUtc);
        $topCustomers = $topCustomers
            ->selectRaw(
                'orders.channel, orders.b2c_customer_id, orders.b2b_customer_id, orders.customer_id, '.
                "COALESCE(b2c_customers.name, b2b_customers.name, legacy_customers.name, '—') as customer, ".
                'COUNT(*) as orders_count'
            )
            ->groupBy(
                'orders.channel',
                'orders.b2c_customer_id',
                'orders.b2b_customer_id',
                'orders.customer_id',
                'b2c_customers.name',
                'b2b_customers.name',
                'legacy_customers.name',
            )
            ->orderByDesc('orders_count')
            ->limit(8)
            ->get()
            ->map(static fn (object $row): array => [
                'channel' => (string) $row->channel,
                'customer_id' => $row->channel === 'b2b'
                    ? ($row->b2b_customer_id === null ? null : (int) $row->b2b_customer_id)
                    : ($row->b2c_customer_id === null ? null : (int) $row->b2c_customer_id),
                'customer' => (string) $row->customer,
                'orders' => (int) $row->orders_count,
            ])
            ->values()
            ->all();

        $cancellationReasons = DB::table('order_status_history')
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id');
        $this->applyScope($cancellationReasons, $scopes, $storeId, $fromUtc, $toUtc);
        $cancellationReasons = $cancellationReasons
            ->whereIn('order_status_history.to_status', self::NON_REVENUE_STATUSES)
            ->selectRaw(
                "COALESCE(NULLIF(TRIM(order_status_history.note), ''), 'unspecified') as reason, COUNT(*) as aggregate"
            )
            ->groupBy('reason')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->get()
            ->map(static fn (object $row): array => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->aggregate,
            ])
            ->values()
            ->all();

        $fulfillmentRows = DB::table('order_status_history')
            ->join('orders', 'orders.id', '=', 'order_status_history.order_id');
        $this->applyScope($fulfillmentRows, $scopes, $storeId, $fromUtc, $toUtc);
        $duration = $this->fulfillmentDurationExpression();
        $safeDuration = "(CASE WHEN {$duration} < 0 THEN 0 ELSE {$duration} END)";
        $fulfillmentStats = $fulfillmentRows
            ->where('order_status_history.to_status', 'delivered')
            ->selectRaw(
                'COUNT(*) as delivered_count, '.
                "AVG({$safeDuration}) as average_minutes, ".
                "SUM(CASE WHEN {$safeDuration} <= 60 THEN 1 ELSE 0 END) as under_60, ".
                "SUM(CASE WHEN {$safeDuration} > 60 AND {$safeDuration} <= 120 THEN 1 ELSE 0 END) as bucket_60_120, ".
                "SUM(CASE WHEN {$safeDuration} > 120 AND {$safeDuration} <= 240 THEN 1 ELSE 0 END) as bucket_120_240, ".
                "SUM(CASE WHEN {$safeDuration} > 240 THEN 1 ELSE 0 END) as over_240"
            )
            ->first();

        $deliveredCount = (int) ($fulfillmentStats->delivered_count ?? 0);
        $fulfillmentAverage = $deliveredCount === 0
            ? null
            : round((float) ($fulfillmentStats->average_minutes ?? 0), 1);
        $fulfillmentBreakdown = [
            ['bucket' => 'under_60', 'count' => (int) ($fulfillmentStats->under_60 ?? 0)],
            ['bucket' => '60_120', 'count' => (int) ($fulfillmentStats->bucket_60_120 ?? 0)],
            ['bucket' => '120_240', 'count' => (int) ($fulfillmentStats->bucket_120_240 ?? 0)],
            ['bucket' => 'over_240', 'count' => (int) ($fulfillmentStats->over_240 ?? 0)],
        ];

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id');
        $this->applyScope($topProducts, $scopes, $storeId, $fromUtc, $toUtc);
        $topProducts = $topProducts
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->selectRaw('products.id, products.sku, products.name, COALESCE(SUM(order_items.quantity), 0) as quantity')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('quantity')
            ->limit(8)
            ->get()
            ->map(static fn (object $row): array => [
                'product_id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'quantity' => round((float) $row->quantity, 3),
            ])
            ->values()
            ->all();

        $sourceBreakdown = null;
        if (Schema::hasColumn('orders', 'source')) {
            $sourceBreakdown = (clone $base)
                ->selectRaw('orders.source, COUNT(*) as orders_count')
                ->groupBy('orders.source')
                ->orderByDesc('orders_count')
                ->get()
                ->map(static fn (object $row): array => [
                    'source' => $row->source === null ? 'legacy' : (string) $row->source,
                    'orders' => (int) $row->orders_count,
                ])
                ->values()
                ->all();
        }

        $withinSla = max(0, $openOrders - $delayedOrders);
        $openWithinSlaPercent = $openOrders === 0 ? null : round(($withinSla / $openOrders) * 100, 1);

        return [
            'period' => [
                'from' => $fromLocal->toDateString(),
                'to' => $toLocal->toDateString(),
                'timezone' => 'Asia/Kuwait',
                'delay_hours' => self::DELAY_HOURS,
            ],
            'currency' => $singleCurrency,
            'currency_totals' => $currencyTotals,
            'kpis' => [
                'orders' => $totalOrders,
                'recognized_revenue' => $recognizedRevenue,
                'average_order_value' => $averageOrderValue,
                'open_orders' => $openOrders,
                'delayed_orders' => $delayedOrders,
                'awaiting_dispatch' => $awaitingDispatch,
                'cancelled_refunded' => $cancelledRefunded,
                'open_within_sla_percent' => $openWithinSlaPercent,
                'average_fulfillment_minutes' => $fulfillmentAverage,
            ],
            'daily' => $daily,
            'status_breakdown' => $statusBreakdown,
            'channel_breakdown' => $channelBreakdown,
            'store_breakdown' => $storeBreakdown,
            'payment_breakdown' => $paymentProviders,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
            'cancellation_reasons' => $cancellationReasons,
            'fulfillment_breakdown' => $fulfillmentBreakdown,
            'source_breakdown' => $sourceBreakdown,
            'exceptions' => [
                ['key' => 'delayed', 'count' => $delayedOrders],
                ['key' => 'awaiting_dispatch', 'count' => $awaitingDispatch],
                ['key' => 'cancelled_refunded', 'count' => $cancelledRefunded],
            ],
            'generated_at' => CarbonImmutable::now('Asia/Kuwait')->format('Y-m-d H:i'),
        ];
    }

    public function applyExceptionFilter(Builder|EloquentBuilder $query, string $exception): void
    {
        if (! in_array($exception, self::EXCEPTIONS, true)) {
            return;
        }

        if ($exception === 'delayed') {
            $query
                ->whereNotIn('orders.status', self::TERMINAL_STATUSES)
                ->where('orders.created_at', '<=', CarbonImmutable::now('UTC')->subHours(self::DELAY_HOURS));

            return;
        }

        if ($exception === 'awaiting_dispatch') {
            $query->whereExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('order_dispatch_states')
                    ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                    ->where('order_dispatch_states.status', 'awaiting_dispatch');
            });

            return;
        }

        if ($exception === 'payment') {
            $query->whereExists(function (Builder $sub): void {
                $sub->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereIn('payments.status', ['failed', 'voided']);
            });

            return;
        }

        if ($exception === 'invoice') {
            $query
                ->where('orders.status', 'delivered')
                ->whereNotExists(function (Builder $sub): void {
                    $sub->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.order_id', 'orders.id')
                        ->whereNotIn('invoices.status', ['cancelled', 'void', 'voided']);
                });

            return;
        }

        $query->whereIn('orders.status', self::NON_REVENUE_STATUSES);
    }

    /**
     * @param  array{b2b:list<int>,b2c:list<int>}  $scopes
     */
    private function orders(
        array $scopes,
        ?int $storeId,
        CarbonImmutable $fromUtc,
        CarbonImmutable $toUtc,
    ): Builder {
        $query = DB::table('orders');
        $this->applyScope($query, $scopes, $storeId, $fromUtc, $toUtc);

        return $query;
    }

    /**
     * @param  array{b2b:list<int>,b2c:list<int>}  $scopes
     */
    private function applyScope(
        Builder $query,
        array $scopes,
        ?int $storeId,
        CarbonImmutable $fromUtc,
        CarbonImmutable $toUtc,
    ): void {
        $query
            ->where(function (Builder $scope) use ($scopes): void {
                $hasScope = false;
                foreach (['b2b', 'b2c'] as $channel) {
                    $ids = array_values(array_unique(array_map('intval', $scopes[$channel])));
                    if ($ids === []) {
                        continue;
                    }

                    $method = $hasScope ? 'orWhere' : 'where';
                    $scope->{$method}(function (Builder $channelScope) use ($channel, $ids): void {
                        $channelScope
                            ->where('orders.channel', $channel)
                            ->whereIn('orders.store_id', $ids);
                    });
                    $hasScope = true;
                }

                if (! $hasScope) {
                    $scope->whereRaw('1 = 0');
                }
            })
            ->when($storeId !== null, fn (Builder $builder) => $builder->where('orders.store_id', $storeId))
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc]);
    }

    private function fulfillmentDurationExpression(): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => '((julianday(order_status_history.created_at) - julianday(orders.created_at)) * 1440.0)',
            'pgsql' => '(EXTRACT(EPOCH FROM (order_status_history.created_at - orders.created_at)) / 60.0)',
            'sqlsrv' => 'DATEDIFF(minute, orders.created_at, order_status_history.created_at)',
            default => 'TIMESTAMPDIFF(MINUTE, orders.created_at, order_status_history.created_at)',
        };
    }

    /**
     * @return array{CarbonImmutable,CarbonImmutable,CarbonImmutable,CarbonImmutable}
     */
    private function bounds(?string $fromDate, ?string $toDate): array
    {
        $now = CarbonImmutable::now('Asia/Kuwait');
        $from = $fromDate === null || trim($fromDate) === ''
            ? $now->subDays(29)->startOfDay()
            : CarbonImmutable::parse($fromDate, 'Asia/Kuwait')->startOfDay();
        $to = $toDate === null || trim($toDate) === ''
            ? $now->endOfDay()
            : CarbonImmutable::parse($toDate, 'Asia/Kuwait')->endOfDay();

        if ($to->lessThan($from)) {
            $to = $from->endOfDay();
        }

        return [
            $from->utc(),
            $to->utc(),
            $from,
            $to,
        ];
    }
}
