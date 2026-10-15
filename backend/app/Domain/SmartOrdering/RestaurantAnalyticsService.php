<?php

namespace App\Domain\SmartOrdering;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RestaurantAnalyticsService
{
    /**
     * @param  list<int>  $storeIds
     * @param  array<string,mixed>  $filters
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
        $grossRevenue = $this->completedRevenue($storeIds, $filters, $from, $to, $rawDimensionFilter);
        $refunds = $this->refundedCompleted($storeIds, $filters, $from, $to);
        $recognizedCompleted = max(0, $completed - $refunds['orders']);
        $revenue = max(0.0, $grossRevenue - $refunds['revenue']);
        $uniqueVisitors = $this->uniqueVisitors($storeIds, $filters, $from, $to);
        $offerImpressions = $eventCounts['offer_impression'] ?? 0;
        $offerRedeemed = $eventCounts['offer_redeemed'] ?? 0;
        $repeatStarted = $eventCounts['repeat_order_started'] ?? 0;
        $repeatCompleted = $eventCounts['repeat_order_completed'] ?? 0;
        $rating = $this->ratingSummary($storeIds, $filters, $from, $to);

        $kpis = [
            'menu_views' => $views,
            'unique_visitors' => $uniqueVisitors,
            'carts' => $carts,
            'checkout_starts' => $checkouts,
            'orders' => $orders,
            'completed_orders' => $recognizedCompleted,
            'refunded_orders' => $refunds['orders'],
            'conversion_rate' => $views > 0 ? round(($orders / $views) * 100, 2) : 0.0,
            'revenue' => round($revenue, 3),
            'aov' => $recognizedCompleted > 0 ? round($revenue / $recognizedCompleted, 3) : 0.0,
            'cancellation_rate' => $orders > 0 ? round(($cancelled / $orders) * 100, 2) : 0.0,
            'abandoned_carts' => max(0, $carts - $orders),
            'repeat_conversion' => $repeatStarted > 0 ? round(($repeatCompleted / $repeatStarted) * 100, 2) : 0.0,
            'offer_conversion' => $offerImpressions > 0 ? round(($offerRedeemed / $offerImpressions) * 100, 2) : 0.0,
            'feedback_count' => $rating['count'],
            'average_rating' => $rating['average'],
        ];

        $funnel = [
            ['key' => 'storefront_viewed', 'count' => $views],
            ['key' => 'cart_started', 'count' => $carts],
            ['key' => 'checkout_started', 'count' => $checkouts],
            ['key' => 'order_created', 'count' => $orders],
            ['key' => 'order_completed', 'count' => $recognizedCompleted],
        ];

        $sources = $this->sourcePerformance($storeIds, $filters, $from, $to);
        $branches = $this->branchPerformance($storeIds, $filters, $from, $to);
        $products = $this->productPerformance($storeIds, $filters, $from, $to);
        $categories = $this->categoryPerformance($storeIds, $filters, $from, $to);
        $fulfillment = $this->dimensionMix($storeIds, $filters, $from, $to, 'fulfillment_mode_key');
        $customers = $this->dimensionMix($storeIds, $filters, $from, $to, 'customer_type_key');
        $offers = $this->offerPerformance($storeIds, $filters, $from, $to);
        $peakHours = $this->peakHours($storeIds, $filters, $from, $to);
        $repeatTrend = $this->repeatOrderTrend($storeIds, $filters, $from, $to);

        return [
            'filters' => $filters,
            'kpis' => $kpis,
            'funnel' => $funnel,
            'sources' => $sources,
            'branches' => $branches,
            'products' => $products,
            'categories' => $categories,
            'fulfillment' => $fulfillment,
            'customer_mix' => $customers,
            'offers' => $offers,
            'peak_hours' => $peakHours,
            'insights' => $this->insights($kpis, $products, $sources, $repeatTrend),
            'formula_version' => 'smart_ordering_analytics_v1',
            'formulas' => [
                'conversion_rate' => 'order_created / storefront_viewed',
                'revenue' => 'sum(order_completed revenue) - revenue of orders currently refunded',
                'aov' => 'recognized completed revenue / non-refunded order_completed',
                'cancellation_rate' => 'order_cancelled / order_created',
                'abandoned_carts' => 'max(cart_started - order_created, 0)',
                'offer_conversion' => 'offer_redeemed / offer_impression',
                'repeat_conversion' => 'repeat_order_completed / repeat_order_started',
            ],
        ];
    }

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
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
     * @param  array<string,mixed>  $filters
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
     * @param  array<string,mixed>  $filters
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

    /**
     * @param  list<int>  $storeIds
     * @param  array<string,mixed>  $filters
     * @return array{orders:int,revenue:float}
     */
    private function refundedCompleted(
        array $storeIds,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $query = DB::table('smart_ordering_analytics_events as e')
            ->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereIn('e.store_id', $storeIds)
            ->where('e.event_name', 'order_completed')
            ->where('o.status', 'refunded')
            ->whereBetween('e.occurred_at', [$from, $to]);

        if (! empty($filters['branch_id'])) {
            $query->where('e.branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['qr_public_id'])) {
            $query->where('e.qr_public_id', (string) $filters['qr_public_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('e.source_snapshot', (string) $filters['source']);
        }
        if (! empty($filters['campaign'])) {
            $query->where('e.campaign_snapshot', (string) $filters['campaign']);
        }
        if (! empty($filters['offer'])) {
            $query->where('e.offer_reference', (string) $filters['offer']);
        }
        if (! empty($filters['customer_type'])) {
            $query->where('e.customer_type', (string) $filters['customer_type']);
        }
        if (! empty($filters['fulfillment'])) {
            $query->where('e.fulfillment_mode', (string) $filters['fulfillment']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('e.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('e.category_id', (int) $filters['category_id']);
        }

        $row = $query->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(e.revenue),0) AS refunded_revenue')->first();

        return [
            'orders' => (int) ($row->orders_count ?? 0),
            'revenue' => (float) ($row->refunded_revenue ?? 0),
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @param  array<string,mixed>  $filters
     * @return array{count:int,average:float}
     */
    private function ratingSummary(
        array $storeIds,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if (! empty($filters['product_id']) || ! empty($filters['category_id'])) {
            return ['count' => 0, 'average' => 0.0];
        }

        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->where('event_name', 'feedback_submitted')
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyRollupDimensions($query, $filters);

        $row = $query->selectRaw('COALESCE(SUM(rating_count),0) AS rating_count, COALESCE(SUM(rating_sum),0) AS rating_sum')->first();
        $count = (int) ($row->rating_count ?? 0);
        $sum = (float) ($row->rating_sum ?? 0);

        return [
            'count' => $count,
            'average' => $count > 0 ? round($sum / $count, 2) : 0.0,
        ];
    }

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
     */
    private function uniqueVisitors(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = DB::table('smart_ordering_analytics_daily_visitors')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_key', (int) $filters['branch_id']);
        }
        if (! empty($filters['qr_public_id'])) {
            $query->where('qr_key', (string) $filters['qr_public_id']);
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
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function sourcePerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('event_name', ['storefront_viewed', 'order_created', 'order_completed']);
        $this->applyRollupDimensions($query, $filters);

        return $query->groupBy('qr_key', 'source_key', 'campaign_key')
            ->selectRaw('qr_key, source_key, campaign_key')
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
                    'qr_public_id' => (string) $row->qr_key,
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
     * @param  array<string,mixed>  $filters
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
            ->limit(100)
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
     * @param  array<string,mixed>  $filters
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

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function categoryPerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_category_daily as d')
            ->leftJoin('categories as c', 'c.id', '=', 'd.category_id')
            ->whereIn('d.store_id', $storeIds)
            ->whereBetween('d.event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('d.event_name', ['category_viewed', 'product_viewed', 'order_completed']);

        if (! empty($filters['branch_id'])) {
            $query->where('d.branch_key', (int) $filters['branch_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('d.category_id', (int) $filters['category_id']);
        }

        return $query->groupBy('d.category_id', 'c.name')
            ->selectRaw('d.category_id, c.name')
            ->selectRaw("SUM(CASE WHEN d.event_name IN ('category_viewed','product_viewed') THEN d.event_count ELSE 0 END) AS views")
            ->selectRaw("SUM(CASE WHEN d.event_name='order_completed' THEN d.event_count ELSE 0 END) AS completed")
            ->orderByDesc('completed')
            ->orderByDesc('views')
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'category_id' => (int) $row->category_id,
                'name' => $row->name,
                'views' => (int) $row->views,
                'completed' => (int) $row->completed,
            ])->all();
    }

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
     * @return list<array{key:string,count:int}>
     */
    private function dimensionMix(
        array $storeIds,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $column,
    ): array {
        abort_unless(in_array($column, ['fulfillment_mode_key', 'customer_type_key'], true), 500);

        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->where('event_name', 'order_created')
            ->where($column, '<>', '');
        $skip = $column === 'fulfillment_mode_key' ? ['fulfillment'] : ['customer_type'];
        $this->applyRollupDimensions($query, $filters, $skip);

        return $query->groupBy($column)
            ->selectRaw($column.' AS dimension_key, SUM(event_count) AS total')
            ->orderByDesc('total')
            ->get()
            ->map(static fn (object $row): array => [
                'key' => (string) $row->dimension_key,
                'count' => (int) $row->total,
            ])->all();
    }

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
     * @return list<array<string,mixed>>
     */
    private function offerPerformance(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_daily')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->where('offer_key', '<>', '')
            ->whereIn('event_name', ['offer_impression', 'offer_clicked', 'offer_redeemed']);
        $this->applyRollupDimensions($query, $filters, ['offer']);

        return $query->groupBy('offer_key')
            ->selectRaw('offer_key')
            ->selectRaw("SUM(CASE WHEN event_name='offer_impression' THEN event_count ELSE 0 END) AS impressions")
            ->selectRaw("SUM(CASE WHEN event_name='offer_clicked' THEN event_count ELSE 0 END) AS clicks")
            ->selectRaw("SUM(CASE WHEN event_name='offer_redeemed' THEN event_count ELSE 0 END) AS redeemed")
            ->orderByDesc('redeemed')
            ->limit(25)
            ->get()
            ->map(static function (object $row): array {
                $impressions = (int) $row->impressions;
                $redeemed = (int) $row->redeemed;

                return [
                    'offer' => (string) $row->offer_key,
                    'impressions' => $impressions,
                    'clicks' => (int) $row->clicks,
                    'redeemed' => $redeemed,
                    'conversion' => $impressions > 0 ? round(($redeemed / $impressions) * 100, 2) : 0.0,
                ];
            })->all();
    }

    /** @param list<int> $storeIds
     * @param  array<string,mixed>  $filters
     * @return list<array{hour:int,orders:int}>
     */
    private function peakHours(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('smart_ordering_analytics_hourly')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->where('event_name', 'order_created');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_key', (int) $filters['branch_id']);
        }
        if (! empty($filters['qr_public_id'])) {
            $query->where('qr_key', (string) $filters['qr_public_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('source_key', (string) $filters['source']);
        }
        if (! empty($filters['campaign'])) {
            $query->where('campaign_key', (string) $filters['campaign']);
        }
        if (! empty($filters['fulfillment'])) {
            $query->where('fulfillment_mode_key', (string) $filters['fulfillment']);
        }

        return $query->groupBy('event_hour')
            ->selectRaw('event_hour, SUM(event_count) AS orders')
            ->orderByDesc('orders')
            ->orderBy('event_hour')
            ->limit(8)
            ->get()
            ->map(static fn (object $row): array => [
                'hour' => (int) $row->event_hour,
                'orders' => (int) $row->orders,
            ])->all();
    }

    /**
     * @param  list<int>  $storeIds
     * @param  array<string,mixed>  $filters
     * @return array{current:int,previous:int,change_percent:float}
     */
    private function repeatOrderTrend(
        array $storeIds,
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        if (! empty($filters['product_id']) || ! empty($filters['category_id'])) {
            return ['current' => 0, 'previous' => 0, 'change_percent' => 0.0];
        }

        $days = max(1, $from->startOfDay()->diffInDays($to->startOfDay()) + 1);
        $previousTo = $from->subDay()->endOfDay();
        $previousFrom = $previousTo->subDays($days - 1)->startOfDay();

        $count = function (CarbonImmutable $start, CarbonImmutable $end) use ($storeIds, $filters): int {
            $query = DB::table('smart_ordering_analytics_daily')
                ->whereIn('store_id', $storeIds)
                ->where('event_name', 'order_created')
                ->where('customer_type_key', 'repeat')
                ->whereBetween('event_date', [$start->toDateString(), $end->toDateString()]);
            $this->applyRollupDimensions($query, $filters, ['customer_type']);

            return (int) $query->sum('event_count');
        };

        $current = $count($from, $to);
        $previous = $count($previousFrom, $previousTo);
        $change = $previous > 0
            ? round((($current - $previous) / $previous) * 100, 2)
            : ($current > 0 ? 100.0 : 0.0);

        return ['current' => $current, 'previous' => $previous, 'change_percent' => $change];
    }

    /** @return list<array{code:string,severity:string,message:string}> */
    private function insights(array $kpis, array $products, array $sources, array $repeatTrend): array
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

        $topAddOn = collect($products)
            ->filter(static fn (array $row): bool => ($row['adds'] ?? 0) > 0)
            ->sortByDesc('adds')
            ->first();
        if (is_array($topAddOn)) {
            $insights[] = [
                'code' => 'top_add_on',
                'severity' => 'positive',
                'message' => 'Top add-on: '.($topAddOn['name'] ?: '#'.$topAddOn['product_id']).' with '.$topAddOn['adds'].' cart adds.',
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

        if (($repeatTrend['current'] ?? 0) > 0 || ($repeatTrend['previous'] ?? 0) > 0) {
            $change = (float) ($repeatTrend['change_percent'] ?? 0);
            $direction = $change >= 0 ? 'increased' : 'decreased';
            $insights[] = [
                'code' => 'repeat_trend',
                'severity' => $change >= 0 ? 'positive' : 'warning',
                'message' => 'Repeat orders '.$direction.' by '.abs($change).'% versus the previous equal period.',
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
     * @param  array<string,mixed>  $filters
     */
    private function rawBase(array $storeIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = DB::table('smart_ordering_analytics_events')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('occurred_at', [$from, $to]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['qr_public_id'])) {
            $query->where('qr_public_id', (string) $filters['qr_public_id']);
        }
        if (! empty($filters['source'])) {
            $query->where('source_snapshot', (string) $filters['source']);
        }
        if (! empty($filters['campaign'])) {
            $query->where('campaign_snapshot', (string) $filters['campaign']);
        }
        if (! empty($filters['offer'])) {
            $query->where('offer_reference', (string) $filters['offer']);
        }
        if (! empty($filters['customer_type'])) {
            $query->where('customer_type', (string) $filters['customer_type']);
        }
        if (! empty($filters['fulfillment'])) {
            $query->where('fulfillment_mode', (string) $filters['fulfillment']);
        }
        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        return $query;
    }

    /** @param array<string,mixed> $filters
     * @param  list<string>  $skip
     */
    private function applyRollupDimensions(Builder $query, array $filters, array $skip = [], string $prefix = ''): void
    {
        if (! in_array('branch', $skip, true) && ! empty($filters['branch_id'])) {
            $query->where($prefix.'branch_key', (int) $filters['branch_id']);
        }
        if (! in_array('qr', $skip, true) && ! empty($filters['qr_public_id'])) {
            $query->where($prefix.'qr_key', (string) $filters['qr_public_id']);
        }
        if (! in_array('source', $skip, true) && ! empty($filters['source'])) {
            $query->where($prefix.'source_key', (string) $filters['source']);
        }
        if (! in_array('campaign', $skip, true) && ! empty($filters['campaign'])) {
            $query->where($prefix.'campaign_key', (string) $filters['campaign']);
        }
        if (! in_array('offer', $skip, true) && ! empty($filters['offer'])) {
            $query->where($prefix.'offer_key', (string) $filters['offer']);
        }
        if (! in_array('customer_type', $skip, true) && ! empty($filters['customer_type'])) {
            $query->where($prefix.'customer_type_key', (string) $filters['customer_type']);
        }
        if (! in_array('fulfillment', $skip, true) && ! empty($filters['fulfillment'])) {
            $query->where($prefix.'fulfillment_mode_key', (string) $filters['fulfillment']);
        }
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
                'repeat_conversion' => 0.0, 'offer_conversion' => 0.0, 'refunded_orders' => 0,
                'feedback_count' => 0, 'average_rating' => 0.0,
            ],
            'funnel' => [], 'sources' => [], 'branches' => [], 'products' => [], 'categories' => [],
            'fulfillment' => [], 'customer_mix' => [], 'offers' => [], 'peak_hours' => [], 'insights' => [],
            'formula_version' => 'smart_ordering_analytics_v1', 'formulas' => [],
        ];
    }
}
