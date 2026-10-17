<?php

namespace App\Services;

use Carbon\CarbonImmutable;
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

        $openOrders = (clone $base)
            ->whereNotIn('orders.status', self::TERMINAL_STATUSES)
            ->count('orders.id');

        $delayedOrders = (clone $base)
            ->whereNotIn('orders.status', self::TERMINAL_STATUSES)
            ->where('orders.created_at', '<=', $delayCutoff)
            ->count('orders.id');

        $awaitingDispatch = (clone $base)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('order_dispatch_states')
                    ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                    ->where('order_dispatch_states.status', 'awaiting_dispatch');
            })
            ->count('orders.id');

        $paymentExceptions = (clone $base)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereIn('payments.status', ['failed', 'voided']);
            })
            ->count('orders.id');

        $invoiceExceptions = (clone $base)
            ->where('orders.status', 'delivered')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->whereNotIn('invoices.status', ['cancelled', 'void', 'voided']);
            })
            ->count('orders.id');

        $cancelledRefunded = (clone $base)
            ->whereIn('orders.status', self::NON_REVENUE_STATUSES)
            ->count('orders.id');

        $daily = (clone $base)
            ->selectRaw(
                "DATE(orders.created_at) as day, COUNT(*) as orders_count, ".
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

        $totalOrders = (clone $base)->count('orders.id');
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
                'payment_exceptions' => $paymentExceptions,
                'invoice_exceptions' => $invoiceExceptions,
                'cancelled_refunded' => $cancelledRefunded,
                'open_within_sla_percent' => $openWithinSlaPercent,
            ],
            'daily' => $daily,
            'status_breakdown' => $statusBreakdown,
            'channel_breakdown' => $channelBreakdown,
            'payment_breakdown' => $paymentProviders,
            'top_products' => $topProducts,
            'source_breakdown' => $sourceBreakdown,
            'exceptions' => [
                ['key' => 'delayed', 'count' => $delayedOrders],
                ['key' => 'awaiting_dispatch', 'count' => $awaitingDispatch],
                ['key' => 'payment', 'count' => $paymentExceptions],
                ['key' => 'invoice', 'count' => $invoiceExceptions],
                ['key' => 'cancelled_refunded', 'count' => $cancelledRefunded],
            ],
            'generated_at' => CarbonImmutable::now('Asia/Kuwait')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array{b2b:list<int>,b2c:list<int>}  $scopes
     */
    public function applyExceptionFilter(Builder $query, array $scopes, string $exception): void
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
                    $ids = array_values(array_unique(array_map('intval', $scopes[$channel] ?? [])));
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
