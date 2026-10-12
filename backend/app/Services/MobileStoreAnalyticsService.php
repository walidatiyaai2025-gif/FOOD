<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MobileStoreAnalyticsService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const MAX_RANGE_DAYS = 31;

    private const STALE_AFTER_HOURS = 24;

    public function __construct(
        private readonly B2cDashboardService $dashboard,
        private readonly RetailMerchantIdentityService $identity,
    ) {}

    /** @return array<string,mixed> */
    public function forUser(
        User $user,
        ?int $requestedRetailStoreId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $seenInsightVersion = null,
    ): array {
        $retailStoreId = $this->resolveRetailStoreId($user, $requestedRetailStoreId);
        [$rangeFrom, $rangeTo] = $this->range($fromDate, $toDate);

        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $retailStoreId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->first([
                'stores.id',
                'stores.name',
                'stores.logo_path',
            ]);

        abort_if($store === null, 404);

        $dashboard = $this->dashboard->build(
            $user,
            [$retailStoreId],
            $rangeFrom->toDateString(),
            $rangeTo->toDateString(),
        );

        $days = (int) $rangeFrom->diffInDays($rangeTo) + 1;
        $previousTo = $rangeFrom->subDay();
        $previousFrom = $previousTo->subDays($days - 1);
        $topProducts = $this->topProducts($retailStoreId, $rangeFrom, $rangeTo);
        $peakActivity = $this->hourlyActivity($retailStoreId, $rangeFrom, $rangeTo);
        $freshness = $this->freshness($retailStoreId, $rangeTo);

        $metrics = [
            'revenue' => $this->metric($dashboard, 'revenue'),
            'orders' => $this->metric($dashboard, 'orders'),
            'average_order_value' => $this->metric($dashboard, 'average_order_value'),
        ];

        $distribution = (array) ($dashboard['distribution'] ?? []);
        $previousDistribution = (array) ($dashboard['previous_distribution'] ?? []);
        $orderFacts = [
            'completed' => [
                'value' => (int) ($distribution['delivered'] ?? 0),
                'previous_value' => (int) ($previousDistribution['delivered'] ?? 0),
            ],
            'cancelled' => [
                'value' => (int) ($distribution['cancelled'] ?? 0),
                'previous_value' => (int) ($previousDistribution['cancelled'] ?? 0),
            ],
            'late' => [
                'supported' => false,
                'value' => null,
                'previous_value' => null,
            ],
        ];

        $attention = [
            'low_stock' => array_values((array) ($dashboard['low_stock'] ?? [])),
            'merchant_summary' => (array) data_get($dashboard, 'merchant_intelligence.summary', []),
            'merchant_attention' => array_values((array) data_get($dashboard, 'merchant_intelligence.attention', [])),
        ];

        $insights = $this->insights(
            $metrics,
            $orderFacts,
            $topProducts,
            $attention,
        );

        $insightVersion = $this->insightVersion(
            $retailStoreId,
            $rangeFrom,
            $rangeTo,
            $metrics,
            $orderFacts,
            $topProducts,
            $attention,
            $insights,
        );
        $seenVersion = $seenInsightVersion === null ? null : strtolower(trim($seenInsightVersion));
        $hasNewInsights = $insights !== []
            && ($seenVersion === null || ! hash_equals($insightVersion, $seenVersion));

        $merchantRecommendationCount = count((array) data_get(
            $dashboard,
            'merchant_intelligence.recommendations',
            [],
        ));
        $dataState = match (true) {
            (int) data_get($metrics, 'orders.value', 0) === 0 && $merchantRecommendationCount === 0 => 'no_data',
            (bool) ($freshness['is_stale'] ?? false) => 'stale',
            default => 'ready',
        };

        return [
            'store' => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'logo_url' => $this->assetUrl($store->logo_path),
                'currency' => 'EGP',
            ],
            'period' => [
                'from' => $rangeFrom->toDateString(),
                'to' => $rangeTo->toDateString(),
                'days' => $days,
            ],
            'previous_period' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'days' => $days,
            ],
            'metrics' => $metrics,
            'order_facts' => $orderFacts,
            'daily_series' => collect((array) ($dashboard['series'] ?? []))
                ->map(static fn (array $row): array => [
                    'date' => (string) $row['date'],
                    'sales' => round((float) $row['revenue'], 3),
                    'orders' => (int) $row['orders'],
                ])
                ->values()
                ->all(),
            'top_products' => $topProducts,
            'peak_activity' => $peakActivity,
            'attention' => $attention,
            'insights' => $insights,
            'freshness' => $freshness,
            'insight_state' => [
                'version' => $insightVersion,
                'has_new_insights' => $hasNewInsights,
                'unread_count' => $hasNewInsights ? count($insights) : 0,
                'lifecycle' => [
                    'mode' => 'client_version_echo',
                    'request_parameter' => 'seen_insight_version',
                    'clear_when_seen_version_matches_current' => true,
                ],
            ],
            'data_state' => $dataState,
        ];
    }

    private function resolveRetailStoreId(User $user, ?int $requestedRetailStoreId): int
    {
        $entitled = $this->identity->retailStoreIds($user);

        if ($requestedRetailStoreId !== null) {
            abort_unless(in_array($requestedRetailStoreId, $entitled, true), 403);

            return $requestedRetailStoreId;
        }

        abort_if($entitled === [], 403);

        $owned = array_values(array_intersect(
            $this->identity->ownedRetailStoreIds($user),
            $entitled,
        ));

        if (count($owned) === 1) {
            return (int) $owned[0];
        }

        if (count($entitled) === 1) {
            return (int) $entitled[0];
        }

        throw ValidationException::withMessages([
            'retail_store_id' => 'Select an authorized Retail store when multiple store contexts are available.',
        ]);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(?string $fromDate, ?string $toDate): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $to = CarbonImmutable::parse($toDate ?: $today->toDateString(), self::TIMEZONE)->startOfDay();
        $from = CarbonImmutable::parse($fromDate ?: $to->subDays(6)->toDateString(), self::TIMEZONE)->startOfDay();

        if ($from->gt($to)) {
            throw ValidationException::withMessages([
                'from' => 'The from date must be on or before the to date.',
            ]);
        }

        if (((int) $from->diffInDays($to) + 1) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'from' => 'Store analytics supports a maximum 31-day range.',
            ]);
        }

        return [$from, $to];
    }

    /** @return array{value:float|int,previous_value:float|int,delta_percent:?float} */
    private function metric(array $dashboard, string $key): array
    {
        $value = data_get($dashboard, 'kpis.'.$key.'.value', 0);
        $previous = data_get($dashboard, 'kpis.'.$key.'.previous_value', 0);
        $delta = data_get($dashboard, 'kpis.'.$key.'.delta');

        return [
            'value' => is_int($value) ? $value : round((float) $value, 3),
            'previous_value' => is_int($previous) ? $previous : round((float) $previous, 3),
            'delta_percent' => $delta === null ? null : round((float) $delta, 1),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function topProducts(
        int $retailStoreId,
        CarbonImmutable $rangeFrom,
        CarbonImmutable $rangeTo,
    ): array {
        [$from, $to] = $this->utcBounds($rangeFrom, $rangeTo);

        $base = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.store_id', $retailStoreId)
            ->where('orders.channel', 'b2c')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('orders.status', ['cancelled', 'refunded']);

        $itemRevenue = (float) (clone $base)->sum('order_items.line_total');

        return (clone $base)
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->select([
                'products.id',
                'products.sku',
                'products.name',
            ])
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->orderBy('products.id')
            ->limit(5)
            ->get()
            ->map(static function (object $row) use ($itemRevenue): array {
                $revenue = round((float) $row->revenue, 3);

                return [
                    'product_id' => (int) $row->id,
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'units' => round((float) $row->units, 3),
                    'revenue' => $revenue,
                    'contribution_percent' => $itemRevenue > 0
                        ? round(($revenue / $itemRevenue) * 100, 1)
                        : 0.0,
                    'currency' => 'EGP',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{hourly:list<array<string,mixed>>,peak_window:?array<string,mixed>} */
    private function hourlyActivity(
        int $retailStoreId,
        CarbonImmutable $rangeFrom,
        CarbonImmutable $rangeTo,
    ): array {
        [$from, $to] = $this->utcBounds($rangeFrom, $rangeTo);
        $buckets = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $buckets[$hour] = [
                'hour' => $hour,
                'orders' => 0,
                'sales' => 0.0,
            ];
        }

        $orders = DB::table('orders')
            ->where('store_id', $retailStoreId)
            ->where('channel', 'b2c')
            ->whereBetween('created_at', [$from, $to])
            ->get(['created_at', 'grand_total', 'status']);

        foreach ($orders as $order) {
            $hour = CarbonImmutable::parse((string) $order->created_at, 'UTC')
                ->setTimezone(self::TIMEZONE)
                ->hour;
            $buckets[$hour]['orders']++;

            if (! in_array((string) $order->status, ['cancelled', 'refunded'], true)) {
                $buckets[$hour]['sales'] += (float) $order->grand_total;
            }
        }

        foreach ($buckets as $hour => $bucket) {
            $buckets[$hour]['sales'] = round((float) $bucket['sales'], 3);
        }

        $peak = null;
        foreach ($buckets as $bucket) {
            if ((int) $bucket['orders'] === 0) {
                continue;
            }

            if (
                $peak === null
                || (float) $bucket['sales'] > (float) $peak['sales']
                || (
                    (float) $bucket['sales'] === (float) $peak['sales']
                    && (int) $bucket['orders'] > (int) $peak['orders']
                )
            ) {
                $peak = $bucket;
            }
        }

        return [
            'hourly' => array_values($buckets),
            'peak_window' => $peak === null ? null : [
                'start_hour' => (int) $peak['hour'],
                'end_hour' => ((int) $peak['hour'] + 1) % 24,
                'window_hours' => 1,
                'orders' => (int) $peak['orders'],
                'sales' => round((float) $peak['sales'], 3),
                'currency' => 'EGP',
                'source' => 'orders.created_at',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function freshness(int $retailStoreId, CarbonImmutable $rangeTo): array
    {
        $orderUpdatedAt = DB::table('orders')
            ->where('store_id', $retailStoreId)
            ->where('channel', 'b2c')
            ->max('updated_at');
        $inventoryUpdatedAt = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $retailStoreId)
            ->max('inventories.updated_at');

        $sourceUpdatedAt = null;
        foreach ([$orderUpdatedAt, $inventoryUpdatedAt] as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $candidate = CarbonImmutable::parse($value, 'UTC')->setTimezone(self::TIMEZONE);
            if ($sourceUpdatedAt === null || $candidate->gt($sourceUpdatedAt)) {
                $sourceUpdatedAt = $candidate;
            }
        }

        $now = CarbonImmutable::now(self::TIMEZONE);
        $isLivePeriod = $rangeTo->isSameDay($now);
        $isStale = $sourceUpdatedAt !== null
            && $isLivePeriod
            && $sourceUpdatedAt->lt($now->subHours(self::STALE_AFTER_HOURS));

        return [
            'computed_at' => $now->toIso8601String(),
            'source_updated_at' => $sourceUpdatedAt?->toIso8601String(),
            'stale_after_seconds' => self::STALE_AFTER_HOURS * 3600,
            'is_stale' => $isStale,
            'state' => $sourceUpdatedAt === null
                ? 'no_data'
                : ($isStale ? 'stale' : 'fresh'),
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $orderFacts
     * @param  list<array<string,mixed>>  $topProducts
     * @param  array<string,mixed>  $attention
     * @return list<array<string,mixed>>
     */
    private function insights(
        array $metrics,
        array $orderFacts,
        array $topProducts,
        array $attention,
    ): array {
        $insights = [];
        $cancelled = (int) data_get($orderFacts, 'cancelled.value', 0);
        $orders = max(0, (int) data_get($metrics, 'orders.value', 0));

        if ($cancelled > 0) {
            $insights[] = [
                'id' => 'cancelled_orders',
                'code' => 'cancelled_orders_attention',
                'severity' => 'warning',
                'priority' => 90,
                'facts' => [
                    'cancelled_orders' => $cancelled,
                    'order_count' => $orders,
                    'cancelled_percent' => $orders > 0 ? round(($cancelled / $orders) * 100, 1) : 0.0,
                ],
            ];
        }

        $lowStock = (array) ($attention['low_stock'] ?? []);
        $reorderNow = (int) data_get($attention, 'merchant_summary.reorder_now', 0);
        if ($lowStock !== [] || $reorderNow > 0) {
            $insights[] = [
                'id' => 'stock_attention',
                'code' => 'stock_attention_required',
                'severity' => 'warning',
                'priority' => 80,
                'facts' => [
                    'low_stock_count' => count($lowStock),
                    'reorder_now_count' => $reorderNow,
                    'first_product_id' => isset($lowStock[0]['id']) ? (int) $lowStock[0]['id'] : null,
                ],
            ];
        }

        $revenueDelta = data_get($metrics, 'revenue.delta_percent');
        if ($revenueDelta !== null) {
            $delta = round((float) $revenueDelta, 1);
            $insights[] = [
                'id' => 'revenue_change',
                'code' => $delta > 0
                    ? 'revenue_increased'
                    : ($delta < 0 ? 'revenue_decreased' : 'revenue_flat'),
                'severity' => $delta < 0 ? 'warning' : ($delta > 0 ? 'positive' : 'neutral'),
                'priority' => $delta < 0 ? 70 : 50,
                'facts' => [
                    'delta_percent' => $delta,
                    'current_revenue' => (float) data_get($metrics, 'revenue.value', 0),
                    'previous_revenue' => (float) data_get($metrics, 'revenue.previous_value', 0),
                    'currency' => 'EGP',
                ],
            ];
        }

        if ($topProducts !== []) {
            $top = $topProducts[0];
            $insights[] = [
                'id' => 'top_product',
                'code' => 'top_product_contribution',
                'severity' => 'positive',
                'priority' => 40,
                'facts' => [
                    'product_id' => (int) $top['product_id'],
                    'name' => (string) $top['name'],
                    'revenue' => (float) $top['revenue'],
                    'contribution_percent' => (float) $top['contribution_percent'],
                    'currency' => 'EGP',
                ],
            ];
        }

        usort(
            $insights,
            static fn (array $left, array $right): int => ((int) $right['priority'] <=> (int) $left['priority'])
                ?: strcmp((string) $left['id'], (string) $right['id']),
        );

        return array_slice($insights, 0, 4);
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  array<string,mixed>  $orderFacts
     * @param  list<array<string,mixed>>  $topProducts
     * @param  array<string,mixed>  $attention
     * @param  list<array<string,mixed>>  $insights
     */
    private function insightVersion(
        int $retailStoreId,
        CarbonImmutable $rangeFrom,
        CarbonImmutable $rangeTo,
        array $metrics,
        array $orderFacts,
        array $topProducts,
        array $attention,
        array $insights,
    ): string {
        $payload = [
            'store_id' => $retailStoreId,
            'from' => $rangeFrom->toDateString(),
            'to' => $rangeTo->toDateString(),
            'metrics' => $metrics,
            'order_facts' => $orderFacts,
            'top_products' => $topProducts,
            'low_stock' => $attention['low_stock'] ?? [],
            'merchant_summary' => $attention['merchant_summary'] ?? [],
            'insights' => $insights,
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array{0:string,1:string} */
    private function utcBounds(CarbonImmutable $rangeFrom, CarbonImmutable $rangeTo): array
    {
        return [
            $rangeFrom->startOfDay()->utc()->toDateTimeString(),
            $rangeTo->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }
}
