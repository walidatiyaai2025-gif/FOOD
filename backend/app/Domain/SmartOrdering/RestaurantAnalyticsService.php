<?php

namespace App\Domain\SmartOrdering;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RestaurantAnalyticsService
{
    /**
     * @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function dashboard(array $storeIds, array $filters): array
    {
        if ($storeIds === []) {
            return $this->emptyDashboard();
        }

        $from = CarbonImmutable::parse((string) ($filters['from'] ?? now()->subDays(29)->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse((string) ($filters['to'] ?? now()->toDateString()))->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366)->startOfDay();
        }

        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        $rawDimensionFilter = ! empty($filters['product_id']) || ! empty($filters['category_id']);
        $eventCounts = $rawDimensionFilter
            ? $this->eventCountsFromRaw($storeIds, $filters, $from, $to)
            : $this->eventCountsFromRollup($storeIds, $filters, $from, $to);

        $views = $eventCounts['storefront_viewed'] ?? 0;
        $carts = $eventCounts['cart_started'] ?? 0;
        $checkouts = $eventCounts['checkout_started'] ?? 0;
        $orders = $eventCounts['order_created'] ?? 0;
        $completed = $eventCounts['order_completed'] ?? 0;
        $cancelled = $eventCounts['order_cancelled'] ?? 0;
        $revenue = $this->completedRevenue($storeIds, $filters, $from, $to, $rawDimensionFilter);
        $uniqueVisitors = $this->uniqueVisitors($storeIds, $filters, $from, $to);
        $offerImpressions = $eventCounts['offer_impression'] ?? 0;
        $offerRedeemed = $eventCounts['offer_redeemed'] ?? 0;
        $repeatStarted = $eventCounts['repeat_order_started'] ?? 0;
        $repeatCompleted = $eventCounts['repeat_order_completed'] ?? 0;

        $kpis = [
            'menu_views' => $views,
            'unique_visitors' => $uniqueVisitors,
            'carts' => $carts,
            'checkout_starts' => $checkouts,
            'orders' => $orders,
            'completed_orders' => $completed,
            'conversion_rate' => $views > 0 ? round(($orders / $views) * 100, 2) : 0.0,
            'revenue' => round($revenue, 3),
            'aov' => $completed > 0 ? round($revenue / $completed, 3) : 0.0,
            'cancellation_rate' => $orders > 0 ? round(($cancelled / $orders) * 100, 2) : 0.0,
            'abandoned_carts' => max(0, $carts - $orders),
            'repeat_conversion' => $repeatStarted > 0 ? round(($repeatCompleted / $repeatStarted) * 100, 2) : 0.0,
            'offer_conversion' => $offerImpressions > 0 ? round(($offerRedeemed / $offerImpressions) * 100, 2) : 0.0,
        ];

        $funnel = [
            ['key' => 'storefront_viewed', 'count' => $views],
            ['key' => 'cart_started', 'count' => $carts],
            ['key' => 'checkout_started', 'count' => $checkouts],
            ['key' => 'order_created', 'count' => $orders],
            ['key' => 'order_completed', 'count' => $completed],
        ];

        $sources = $this->sourcePerformance($storeIds, $filters, $from, $to);
        $branches = $this->branchPerformance($storeIds, $filters, $from, $to);
        $products = $this->productPerformance($storeIds, $filters, $from, $to);

        return [
            'filters' => $filters,
            'kpis' => $kpis,
            'funnel' => $funnel,
            'sources' => $sources,
            'branches' => $branches,
            'products' => $products,
            'insights' => $this->insights($kpis, $products, $sources),
            'formula_version' => 'smart_ordering_analytics_v1',
            'formulas' => [
                'conversion_rate' => 'order_created / storefront_viewed',
                'revenue' => 'sum(revenue on order_completed only)',
                'aov' => 'completed revenue / order_completed',
                'cancellation_rate' => 'order_cancelled / order_created',
                'abandoned_carts' => 'max(cart_started - order_created, 0)',
                'offer_conversion' => 'offer_redeemed / offer_impression',
                'repeat_conversion' => 'repeat_order_completed / repeat_order_started',
            ],
        ];
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    private function eventCountsFromRollup(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyRollupDimensions($query, $filters);

        return $query->groupBy('event_name')
            ->selectRaw('event_name, SUM(event_count) AS total')
            ->pluck('total', 'event_name')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return array<string,int>
     */
    private function eventCountsFromRaw(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->rawBase($storeIds, $filters, $from, $to);

        return $query->groupBy('event_name')
            ->selectRaw('event_name, COUNT(*) AS total')
            ->pluck('total', 'event_name')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     */
    private function completedRevenue(
        array $storeIds,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $raw,
    ): float {
        if ($raw) {
            return (float) $this->rawBase($storeIds, $filters, $from, $to)
                ->where('event_name', 'order_completed')
                ->sum('revenue');
        }

        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->where('event_name', 'order_completed')
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyRollupDimensions($query, $filters);

        return (float) $query->sum('revenue');
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     */
    private function uniqueVisitors(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = DB::table('smart_ordering_analytics_daily_visitors')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_key', (int) $filters['branch_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('source_key', (string) $filters['source']);
        }
        if (! empty($filters['campaign'])) {
            $query->where('campaign_key', (string) $filters['campaign']);
        }

        return (int) $query->distinct()->count('visitor_hash');
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function sourcePerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('event_name', ['storefront_viewed', 'order_created', 'order_completed']);
        $this->applyRollupDimensions($query, $filters);

        return $query->groupBy('source_key', 'campaign_key')
            ->selectRaw('source_key, campaign_key')
            ->selectRaw("SUM(CASE WHEN event_name='storefront_viewed' THEN event_count ELSE 0 END) AS views")
            ->selectRaw("SUM(CASE WHEN event_name='order_created' THEN event_count ELSE 0 END) AS orders")
            ->selectRaw("SUM(CASE WHEN event_name='order_completed' THEN revenue ELSE 0 END) AS revenue")
            ->orderByDesc('orders')
            ->limit(30)
            ->get()
            ->map(static function (object $row): array {
                $views = (int) $row->views;
                $orders = (int) $row->orders;
                $revenue = (float) $row->revenue;

                return [
                    'source' => (string) $row->source_key,
                    'campaign' => (string) $row->campaign_key,
                    'views' => $views,
                    'orders' => $orders,
                    'conversion' => $views > 0 ? round(($orders / $views) * 100, 2) : 0.0,
                    'revenue' => round($revenue, 3),
                    'aov' => $orders > 0 ? round($revenue / $orders, 3) : 0.0,
                ];
            })->all();
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function branchPerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_daily as d')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 'd.branch_key')
            ->whereIn('d.store_id', $storeIds)
            ->whereBetween('d.event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('d.event_name', ['storefront_viewed', 'order_created', 'order_completed']);
        $this->applyRollupDimensions($query, $filters, [], 'd.');

        return $query->groupBy('d.branch_key', 'b.name_ar', 'b.name_en')
            ->selectRaw('d.branch_key, b.name_ar, b.name_en')
            ->selectRaw("SUM(CASE WHEN d.event_name='storefront_viewed' THEN d.event_count ELSE 0 END) AS views")
            ->selectRaw("SUM(CASE WHEN d.event_name='order_created' THEN d.event_count ELSE 0 END) AS orders")
            ->selectRaw("SUM(CASE WHEN d.event_name='order_completed' THEN d.revenue ELSE 0 END) AS revenue")
            ->orderByDesc('orders')
            ->get()
            ->map(static function (object $row): array {
                $views = (int) $row->views;
                $orders = (int) $row->orders;

                return [
                    'branch_id' => (int) $row->branch_key,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'views' => $views,
                    'orders' => $orders,
                    'conversion' => $views > 0 ? round(($orders / $views) * 100, 2) : 0.0,
                    'revenue' => round((float) $row->revenue, 3),
                ];
            })->all();
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function productPerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_product_daily as d')
            ->leftJoin('products as p', 'p.id', '=', 'd.product_id')
            ->whereIn('d.store_id', $storeIds)
            ->whereBetween('d.event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('d.event_name', ['product_viewed', 'item_added', 'order_completed']);

        if (! empty($filters['branch_id'])) {
            $query->where('d.branch_key', (int) $filters['branch_id']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('d.product_id', (int) $filters['product_id']);
        }

        return $query->groupBy('d.product_id', 'p.name')
            ->selectRaw('d.product_id, p.name')
            ->selectRaw("SUM(CASE WHEN d.event_name='product_viewed' THEN d.event_count ELSE 0 END) AS views")
            ->selectRaw("SUM(CASE WHEN d.event_name='item_added' THEN d.event_count ELSE 0 END) AS adds")
            ->selectRaw("SUM(CASE WHEN d.event_name='order_completed' THEN d.event_count ELSE 0 END) AS completed")
            ->selectRaw("SUM(CASE WHEN d.event_name='order_completed' THEN d.revenue ELSE 0 END) AS revenue")
            ->orderByDesc('completed')
            ->orderByDesc('adds')
            ->limit(25)
            ->get()
            ->map(static function (object $row): array {
                $views = (int) $row->views;
                $completed = (int) $row->completed;

                return [
                    'product_id' => (int) $row->product_id,
                    'name' => $row->name,
                    'views' => $views,
                    'adds' => (int) $row->adds,
                    'completed' => $completed,
                    'conversion' => $views > 0 ? round(($completed / $views) * 100, 2) : 0.0,
                    'revenue' => round((float) $row->revenue, 3),
                ];
            })->all();
    }

    /** @return list<array{code:string,severity:string,message:string}> */
    private function insights(array $kpis, array $products, array $sources): array
    {
        $insights = [];
        if (($kpis['checkout_starts'] ?? 0) > 0) {
            $drop = 100 - round((($kpis['orders'] ?? 0) / max(1, $kpis['checkout_starts'])) * 100, 2);
            if ($drop >= 25) {
                $insights[] = ['code' => 'checkout_drop', 'severity' => 'warning', 'message' => "Checkout drop-off is {$drop}%."];
            }
        }

        $low = collect($products)
            ->filter(static fn (array $row): bool => $row['views'] >= 10 && $row['conversion'] < 5)
            ->sortByDesc('views')
            ->first();
        if (is_array($low)) {
            $insights[] = [
                'code' => 'high_views_low_conversion',
                'severity' => 'warning',
                'message' => 'High views, low conversion: '.($low['name'] ?: '#'.$low['product_id']).'.',
            ];
        }

        $topSource = collect($sources)->sortByDesc('aov')->first();
        if (is_array($topSource) && $topSource['orders'] >= 2) {
            $label = trim(($topSource['source'] ?: 'direct').' '.($topSource['campaign'] ?: ''));
            $insights[] = [
                'code' => 'source_high_aov',
                'severity' => 'positive',
                'message' => "{$label} has the highest AOV in the selected period.",
            ];
        }

        if (($kpis['repeat_conversion'] ?? 0) >= 30) {
            $insights[] = [
                'code' => 'repeat_strength',
                'severity' => 'positive',
                'message' => 'Repeat-order conversion is performing strongly.',
            ];
        }

        return $insights;
    }

    /** @param list<int> $storeIds
     * @param array<string,mixed> $filters
     */
    private function rawBase(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = DB::table('smart_ordering_analytics_events')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('occurred_at', [$from, $to]);

        if (! empty($filters['branch_id'])) $query->where('branch_id', (int) $filters['branch_id']);
        if (! empty($filters['source'])) $query->where('source_snapshot', (string) $filters['source']);
        if (! empty($filters['campaign'])) $query->where('campaign_snapshot', (string) $filters['campaign']);
        if (! empty($filters['offer'])) $query->where('offer_reference', (string) $filters['offer']);
        if (! empty($filters['customer_type'])) $query->where('customer_type', (string) $filters['customer_type']);
        if (! empty($filters['fulfillment'])) $query->where('fulfillment_mode', (string) $filters['fulfillment']);
        if (! empty($filters['product_id'])) $query->where('product_id', (int) $filters['product_id']);
        if (! empty($filters['category_id'])) $query->where('category_id', (int) $filters['category_id']);

        return $query;
    }

    /** @param array<string,mixed> $filters
     * @param list<string> $skip
     */
    private function applyRollupDimensions(Builder $query, array $filters, array $skip = [], string $prefix = ''): void
    {
        if (! in_array('branch', $skip, true) && ! empty($filters['branch_id'])) $query->where($prefix.'branch_key', (int) $filters['branch_id']);
        if (! in_array('source', $skip, true) && ! empty($filters['source'])) $query->where($prefix.'source_key', (string) $filters['source']);
        if (! in_array('campaign', $skip, true) && ! empty($filters['campaign'])) $query->where($prefix.'campaign_key', (string) $filters['campaign']);
        if (! in_array('offer', $skip, true) && ! empty($filters['offer'])) $query->where($prefix.'offer_key', (string) $filters['offer']);
        if (! in_array('customer_type', $skip, true) && ! empty($filters['customer_type'])) $query->where($prefix.'customer_type_key', (string) $filters['customer_type']);
        if (! in_array('fulfillment', $skip, true) && ! empty($filters['fulfillment'])) $query->where($prefix.'fulfillment_mode_key', (string) $filters['fulfillment']);
    }

    /** @return array<string,mixed> */
    private function emptyDashboard(): array
    {
        return [
            'filters' => [],
            'kpis' => [
                'menu_views' => 0, 'unique_visitors' => 0, 'carts' => 0, 'checkout_starts' => 0,
                'orders' => 0, 'completed_orders' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0,
                'aov' => 0.0, 'cancellation_rate' => 0.0, 'abandoned_carts' => 0,
                'repeat_conversion' => 0.0, 'offer_conversion' => 0.0,
            ],
            'funnel' => [], 'sources' => [], 'branches' => [], 'products' => [], 'insights' => [],
            'formula_version' => 'smart_ordering_analytics_v1', 'formulas' => [],
        ];
    }
}
