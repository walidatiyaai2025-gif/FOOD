<?php

namespace App\Domain\Analytics\Retailer;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Analytics\ReadModel\AnalyticsReadModelService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class Retailer360IntelligenceService
{
    public const SCHEMA_VERSION = 'foodex.analytics.retailer360.v1';

    public const MAX_RETAILER_STORES = 25;

    public function __construct(
        private AnalyticsReadModelService $readModel,
    ) {}

    /**
     * Build a governed retailer-account analytical view.
     *
     * Sell-in is always sourced from wholesaler-specific replenishments.
     * Sell-out is always sourced from the authorized B2C analytics read model.
     *
     * @param  list<int>  $retailerStoreIds
     * @return array<string,mixed>
     */
    public function read(
        User $actor,
        array $retailerStoreIds,
        int $wholesaleStoreId,
        string $from,
        string $to,
        string $locale = 'en',
    ): array {
        $retailerStoreIds = $this->normalizeStoreIds($retailerStoreIds);
        if ($wholesaleStoreId < 1) {
            throw ValidationException::withMessages([
                'wholesale_store_id' => ['A positive wholesale store id is required.'],
            ]);
        }

        $current = $this->readModel->read(
            $actor,
            'b2c',
            $retailerStoreIds,
            $from,
            $to,
            $wholesaleStoreId,
            $locale,
        );

        $fromDate = CarbonImmutable::parse($from, AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $days = (int) $fromDate->diffInDays($toDate) + 1;
        $previousTo = $fromDate->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $previous = null;
        $previousUnavailableReason = null;
        try {
            $previous = $this->readModel->read(
                $actor,
                'b2c',
                $current['store_ids'],
                $previousFrom->toDateString(),
                $previousTo->toDateString(),
                $wholesaleStoreId,
                $locale,
            );
        } catch (ValidationException) {
            $previousUnavailableReason = 'comparison_window_not_authorized_or_available';
        }

        [$fromUtc, $toUtc] = $this->utcRange($fromDate, $toDate);
        [$previousFromUtc, $previousToUtc] = $this->utcRange($previousFrom, $previousTo);

        $sellInByStore = $this->sellInByStore(
            $current['store_ids'],
            $wholesaleStoreId,
            $fromUtc,
            $toUtc,
        );
        $previousSellInByStore = $previous === null
            ? []
            : $this->sellInByStore(
                $current['store_ids'],
                $wholesaleStoreId,
                $previousFromUtc,
                $previousToUtc,
            );

        $sellOut = $this->factTotals($current['facts']);
        $previousSellOut = $previous === null ? null : $this->factTotals($previous['facts']);
        $sellIn = $this->sellInTotals($sellInByStore);
        $previousSellIn = $previous === null ? null : $this->sellInTotals($previousSellInByStore);
        $feed = $this->feedSummary($current['sellout_feed'] ?? []);

        $inventoryAvailable = round((float) collect($current['inventory_snapshots'] ?? [])
            ->sum(static fn (array $row): float => (float) ($row['available_units'] ?? 0)), 3);
        $dailyVelocity = $days > 0 ? round($sellOut['units'] / $days, 3) : 0.0;
        $stockCover = $feed['state'] === 'none' || $dailyVelocity <= 0
            ? null
            : round($inventoryAvailable / $dailyVelocity, 2);

        $sellThrough = $feed['state'] === 'none' || $sellIn['units'] <= 0
            ? null
            : round(($sellOut['units'] / $sellIn['units']) * 100, 2);

        $reorder = $this->reorderBehavior(
            $current['store_ids'],
            $wholesaleStoreId,
            $fromDate,
            $toDate,
            $sellIn['replenishments'],
        );

        $drilldown = $this->drilldown(
            $current,
            $sellInByStore,
            $wholesaleStoreId,
            $fromDate,
            $toDate,
            $fromUtc,
            $toUtc,
        );

        $trends = [
            'sell_out_revenue' => $this->compare(
                $sellOut['net_revenue'],
                $previousSellOut['net_revenue'] ?? null,
            ),
            'sell_out_units' => $this->compare(
                $sellOut['units'],
                $previousSellOut['units'] ?? null,
            ),
            'sell_in_value' => $this->compare(
                $sellIn['value'],
                $previousSellIn['value'] ?? null,
            ),
            'sell_in_units' => $this->compare(
                $sellIn['units'],
                $previousSellIn['units'] ?? null,
            ),
        ];

        $dataQuality = $this->dataQuality($current, $feed, $previousUnavailableReason);
        $health = $this->accountHealth(
            $sellOut,
            $trends,
            $stockCover,
            $reorder,
            $dataQuality,
        );

        $benchmark = $this->cohortBenchmark();

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'metric_dictionary_version' => $current['metric_dictionary_version'] ?? null,
            'timezone' => AnalyticsFactMaterializer::TIMEZONE,
            'account' => [
                'retailer_store_ids' => array_values($current['store_ids']),
                'store_count' => count($current['store_ids']),
                'wholesale_store_id' => $wholesaleStoreId,
            ],
            'window' => $current['window'],
            'flows' => [
                'sell_in' => [
                    'label' => 'wholesale_purchases_from_this_wholesaler',
                    'value' => $sellIn['value'],
                    'units' => $sellIn['units'],
                    'replenishments' => $sellIn['replenishments'],
                    'last_received_at' => $sellIn['last_received_at'],
                    'source' => 'retail_replenishments',
                    'is_retail_sales' => false,
                ],
                'sell_out' => [
                    'label' => 'actual_retail_to_consumer_sales',
                    'net_revenue' => $sellOut['net_revenue'],
                    'units' => $sellOut['units'],
                    'orders' => $sellOut['orders'],
                    'recognized_orders' => $sellOut['recognized_orders'],
                    'refunds' => [
                        'orders' => $sellOut['refunded_orders'],
                        'value' => $sellOut['refunded_value'],
                        'rate_percent' => $this->rate($sellOut['refunded_orders'], $sellOut['orders']),
                    ],
                    'cancellations' => [
                        'orders' => $sellOut['cancelled_orders'],
                        'value' => $sellOut['cancelled_value'],
                        'rate_percent' => $this->rate($sellOut['cancelled_orders'], $sellOut['orders']),
                    ],
                    'source' => $feed,
                    'wholesale_purchase_inference' => false,
                ],
            ],
            'sell_through' => [
                'available' => $sellThrough !== null,
                'percent' => $sellThrough,
                'basis' => 'period_sell_out_units_divided_by_period_units_received_from_this_wholesaler',
                'caveat' => 'This ratio is not an inventory-depletion equation because opening stock and purchases from other suppliers may exist.',
            ],
            'stock_cover' => [
                'available' => $stockCover !== null,
                'days' => $stockCover,
                'available_units' => $inventoryAvailable,
                'daily_sell_out_velocity' => $dailyVelocity,
                'basis' => 'latest_available_inventory_divided_by_observed_actual_sell_out_velocity',
            ],
            'trends' => $trends,
            'reorder_behavior' => $reorder,
            'account_health' => $health,
            'data_quality' => $dataQuality,
            'benchmark' => $benchmark['benchmark'],
            'drilldown' => $drilldown,
            'evidence' => [
                'sell_out_freshness' => $current['freshness'] ?? null,
                'sell_out_feed' => $current['sellout_feed'] ?? [],
                'comparison_window' => $previous === null ? null : $previous['window'],
                'comparison_unavailable_reason' => $previousUnavailableReason,
                'model_arithmetic_allowed' => false,
                'model_may_relabel_sell_in_as_sell_out' => false,
                'peer_rows_exposed' => false,
            ],
        ];


        return $result;
    }

    /**
     * @param  list<int>  $storeIds
     * @return array<int,array<string,mixed>>
     */
    private function sellInByStore(
        array $storeIds,
        int $wholesaleStoreId,
        string $fromUtc,
        string $toUtc,
    ): array {
        return DB::table('retail_replenishments as replenishments')
            ->join('retail_replenishment_items as items', 'items.replenishment_id', '=', 'replenishments.id')
            ->whereIn('replenishments.retail_store_id', $storeIds)
            ->where('replenishments.source_wholesale_store_id', $wholesaleStoreId)
            ->whereBetween('replenishments.received_at', [$fromUtc, $toUtc])
            ->groupBy('replenishments.retail_store_id')
            ->get([
                'replenishments.retail_store_id',
                DB::raw('COALESCE(SUM(items.line_total), 0) as purchase_value'),
                DB::raw('COALESCE(SUM(items.quantity), 0) as purchase_units'),
                DB::raw('COUNT(DISTINCT replenishments.id) as replenishments_count'),
                DB::raw('MAX(replenishments.received_at) as last_received_at'),
            ])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->retail_store_id => [
                    'store_id' => (int) $row->retail_store_id,
                    'value' => round((float) $row->purchase_value, 3),
                    'units' => round((float) $row->purchase_units, 3),
                    'replenishments' => (int) $row->replenishments_count,
                    'last_received_at' => $row->last_received_at === null ? null : (string) $row->last_received_at,
                ],
            ])
            ->all();
    }

    /** @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function sellInTotals(array $rows): array
    {
        $collection = collect($rows);

        return [
            'value' => round((float) $collection->sum('value'), 3),
            'units' => round((float) $collection->sum('units'), 3),
            'replenishments' => (int) $collection->sum('replenishments'),
            'last_received_at' => $collection->pluck('last_received_at')->filter()->sortDesc()->first(),
        ];
    }

    /** @param list<array<string,mixed>> $facts
     * @return array<string,mixed>
     */
    private function factTotals(array $facts): array
    {
        $sum = static function (array $rows, string $key): float {
            return round((float) collect($rows)->sum(
                static fn (array $row): float => (float) ($row[$key] ?? 0),
            ), 3);
        };

        return [
            'orders' => (int) collect($facts)->sum('orders'),
            'recognized_orders' => (int) collect($facts)->sum('recognized_orders'),
            'net_revenue' => $sum($facts, 'net_revenue'),
            'units' => $sum($facts, 'units'),
            'refunded_value' => $sum($facts, 'refunded_value'),
            'refunded_orders' => (int) collect($facts)->sum('refunded_orders'),
            'cancelled_value' => $sum($facts, 'cancelled_value'),
            'cancelled_orders' => (int) collect($facts)->sum('cancelled_orders'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $feeds
     * @return array<string,mixed>
     */
    private function feedSummary(array $feeds): array
    {
        $states = collect($feeds)->pluck('state')->filter()->values();
        $state = $states->isEmpty() || $states->every(static fn ($value): bool => $value === 'none')
            ? 'none'
            : ($states->every(static fn ($value): bool => $value === 'available') ? 'available' : 'partial');

        return [
            'state' => $state,
            'last_sync_at' => collect($feeds)->pluck('last_sync_at')->filter()->sortDesc()->first(),
            'coverage_from' => collect($feeds)->pluck('coverage_from')->filter()->sort()->first(),
            'coverage_to' => collect($feeds)->pluck('coverage_to')->filter()->sortDesc()->first(),
            'quality_score' => $feeds === []
                ? 0.0
                : round((float) collect($feeds)->avg(
                    static fn (array $row): float => (float) ($row['quality_score'] ?? 0),
                ), 2),
            'store_count' => count($feeds),
        ];
    }

    /** @return array<string,mixed> */
    private function reorderBehavior(
        array $storeIds,
        int $wholesaleStoreId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $replenishmentsInWindow,
    ): array {
        $historyFrom = $from->subDays(180)->startOfDay()->utc()->toDateTimeString();
        $historyTo = $to->endOfDay()->utc()->toDateTimeString();

        $rows = DB::table('retail_replenishments')
            ->whereIn('retail_store_id', $storeIds)
            ->where('source_wholesale_store_id', $wholesaleStoreId)
            ->whereBetween('received_at', [$historyFrom, $historyTo])
            ->orderBy('retail_store_id')
            ->orderBy('received_at')
            ->get(['retail_store_id', 'received_at'])
            ->groupBy('retail_store_id');

        $intervals = [];
        $last = null;
        foreach ($rows as $storeRows) {
            $previous = null;
            foreach ($storeRows as $row) {
                $received = CarbonImmutable::parse((string) $row->received_at, 'UTC')
                    ->setTimezone(AnalyticsFactMaterializer::TIMEZONE);
                if ($previous instanceof CarbonImmutable) {
                    $intervals[] = (int) $previous->startOfDay()->diffInDays($received->startOfDay());
                }
                $previous = $received;
                if (! $last instanceof CarbonImmutable || $received->gt($last)) {
                    $last = $received;
                }
            }
        }

        $averageInterval = $intervals === []
            ? null
            : round(array_sum($intervals) / count($intervals), 1);
        $daysSince = $last instanceof CarbonImmutable
            ? (int) $last->startOfDay()->diffInDays($to->startOfDay())
            : null;

        $status = 'no_history';
        if ($last instanceof CarbonImmutable && $averageInterval === null) {
            $status = 'first_reorder_pending';
        } elseif ($averageInterval !== null && $daysSince !== null) {
            $status = $daysSince > max(1.0, $averageInterval * 1.5) ? 'overdue' : 'on_cadence';
        }

        return [
            'status' => $status,
            'replenishments_in_window' => $replenishmentsInWindow,
            'observed_intervals' => count($intervals),
            'average_interval_days' => $averageInterval,
            'last_received_at' => $last?->utc()->toDateTimeString(),
            'days_since_last_receipt' => $daysSince,
            'history_window_days' => 180,
        ];
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<int,array<string,mixed>>  $sellInByStore
     * @return array<string,mixed>
     */
    private function drilldown(
        array $current,
        array $sellInByStore,
        int $wholesaleStoreId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $fromUtc,
        string $toUtc,
    ): array {
        $storeIds = array_values($current['store_ids']);
        $sku = $this->skuDrilldown($storeIds, $wholesaleStoreId, $from, $to, $fromUtc, $toUtc);

        $category = collect($sku)
            ->groupBy(static fn (array $row): string => (string) ($row['category_id'] ?? 'unassigned'))
            ->map(function ($rows, $key): array {
                $first = $rows->first();

                return [
                    'category_id' => $key === 'unassigned' ? null : (int) $key,
                    'category_name' => (string) ($first['category_name'] ?? 'Unassigned'),
                    'sell_out_units' => round((float) $rows->sum('sell_out_units'), 3),
                    'sell_out_revenue' => round((float) $rows->sum('sell_out_revenue'), 3),
                    'sell_in_units' => round((float) $rows->sum('sell_in_units'), 3),
                    'sell_in_value' => round((float) $rows->sum('sell_in_value'), 3),
                ];
            })
            ->sortByDesc('sell_out_revenue')
            ->values()
            ->all();

        $stores = collect($current['facts'])
            ->groupBy('store_id')
            ->map(function ($facts, $storeId) use ($sellInByStore): array {
                $totals = $this->factTotals($facts->values()->all());
                $sellIn = $sellInByStore[(int) $storeId] ?? [
                    'value' => 0.0,
                    'units' => 0.0,
                    'replenishments' => 0,
                ];

                return [
                    'store_id' => (int) $storeId,
                    'sell_out_revenue' => $totals['net_revenue'],
                    'sell_out_units' => $totals['units'],
                    'sell_in_value' => (float) $sellIn['value'],
                    'sell_in_units' => (float) $sellIn['units'],
                    'replenishments' => (int) $sellIn['replenishments'],
                ];
            })
            ->sortBy('store_id')
            ->values()
            ->all();

        $sellInDaily = $this->sellInDaily($storeIds, $wholesaleStoreId, $fromUtc, $toUtc);
        $dates = collect($current['facts'])
            ->groupBy('date')
            ->map(function ($facts, $date) use ($sellInDaily): array {
                $totals = $this->factTotals($facts->values()->all());
                $sellIn = $sellInDaily[(string) $date] ?? ['value' => 0.0, 'units' => 0.0];

                return [
                    'date' => (string) $date,
                    'sell_out_revenue' => $totals['net_revenue'],
                    'sell_out_units' => $totals['units'],
                    'sell_in_value' => (float) $sellIn['value'],
                    'sell_in_units' => (float) $sellIn['units'],
                ];
            })
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'sku' => $sku,
            'category' => $category,
            'date' => $dates,
            'store' => $stores,
            'row_limits' => [
                'sku' => 100,
                'category' => 100,
            ],
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array<string,mixed>>
     */
    private function skuDrilldown(
        array $storeIds,
        int $wholesaleStoreId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $fromUtc,
        string $toUtc,
    ): array {
        $rows = [];

        $native = DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->groupBy('orders.store_id', 'items.product_id')
            ->get([
                'orders.store_id',
                'items.product_id',
                DB::raw('COALESCE(SUM(items.quantity), 0) as units'),
                DB::raw('COALESCE(SUM(items.line_total), 0) as revenue'),
            ]);

        foreach ($native as $row) {
            $key = ((int) $row->store_id).':'.((int) $row->product_id);
            $rows[$key] = $this->skuSeed((int) $row->store_id, (int) $row->product_id);
            $rows[$key]['sell_out_units'] += (float) $row->units;
            $rows[$key]['sell_out_revenue'] += (float) $row->revenue;
        }

        $external = DB::table('retail_sellout_events as events')
            ->join('retail_sellout_connections as connections', 'connections.id', '=', 'events.connection_id')
            ->whereIn('events.retailer_store_id', $storeIds)
            ->where('events.event_type', 'sale')
            ->where('connections.status', 'active')
            ->whereBetween('events.business_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('events.retailer_store_id', 'events.product_id')
            ->get([
                'events.retailer_store_id',
                'events.product_id',
                DB::raw('COALESCE(SUM(events.quantity), 0) as units'),
                DB::raw('COALESCE(SUM(events.net_amount), 0) as revenue'),
            ]);

        foreach ($external as $row) {
            $key = ((int) $row->retailer_store_id).':'.((int) $row->product_id);
            $rows[$key] ??= $this->skuSeed((int) $row->retailer_store_id, (int) $row->product_id);
            $rows[$key]['sell_out_units'] += (float) $row->units;
            $rows[$key]['sell_out_revenue'] += (float) $row->revenue;
        }

        $purchases = DB::table('retail_replenishment_items as items')
            ->join('retail_replenishments as replenishments', 'replenishments.id', '=', 'items.replenishment_id')
            ->whereIn('replenishments.retail_store_id', $storeIds)
            ->where('replenishments.source_wholesale_store_id', $wholesaleStoreId)
            ->whereBetween('replenishments.received_at', [$fromUtc, $toUtc])
            ->groupBy('replenishments.retail_store_id', 'items.retail_product_id')
            ->get([
                'replenishments.retail_store_id',
                'items.retail_product_id',
                DB::raw('COALESCE(SUM(items.quantity), 0) as units'),
                DB::raw('COALESCE(SUM(items.line_total), 0) as purchase_value'),
            ]);

        foreach ($purchases as $row) {
            $key = ((int) $row->retail_store_id).':'.((int) $row->retail_product_id);
            $rows[$key] ??= $this->skuSeed((int) $row->retail_store_id, (int) $row->retail_product_id);
            $rows[$key]['sell_in_units'] += (float) $row->units;
            $rows[$key]['sell_in_value'] += (float) $row->purchase_value;
        }

        $productIds = collect($rows)->pluck('product_id')->unique()->values()->all();
        $metadata = $productIds === []
            ? collect()
            : DB::table('products')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->whereIn('products.id', $productIds)
                ->get([
                    'products.id',
                    'products.sku',
                    'products.name',
                    'products.category_id',
                    'categories.name as category_name',
                ])
                ->keyBy('id');

        return collect($rows)
            ->map(function (array $row) use ($metadata): array {
                $meta = $metadata->get($row['product_id']);
                $row['sku'] = $meta === null ? null : (string) $meta->sku;
                $row['product_name'] = $meta === null ? null : (string) $meta->name;
                $row['category_id'] = $meta?->category_id === null ? null : (int) $meta->category_id;
                $row['category_name'] = $meta?->category_name === null ? 'Unassigned' : (string) $meta->category_name;
                $row['sell_out_units'] = round((float) $row['sell_out_units'], 3);
                $row['sell_out_revenue'] = round((float) $row['sell_out_revenue'], 3);
                $row['sell_in_units'] = round((float) $row['sell_in_units'], 3);
                $row['sell_in_value'] = round((float) $row['sell_in_value'], 3);
                $row['sell_through_percent'] = $row['sell_in_units'] <= 0
                    ? null
                    : round(($row['sell_out_units'] / $row['sell_in_units']) * 100, 2);

                return $row;
            })
            ->sortByDesc('sell_out_revenue')
            ->take(100)
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function skuSeed(int $storeId, int $productId): array
    {
        return [
            'store_id' => $storeId,
            'product_id' => $productId,
            'sku' => null,
            'product_name' => null,
            'category_id' => null,
            'category_name' => null,
            'sell_out_units' => 0.0,
            'sell_out_revenue' => 0.0,
            'sell_in_units' => 0.0,
            'sell_in_value' => 0.0,
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @return array<string,array{value:float,units:float}>
     */
    private function sellInDaily(
        array $storeIds,
        int $wholesaleStoreId,
        string $fromUtc,
        string $toUtc,
    ): array {
        $rows = DB::table('retail_replenishments as replenishments')
            ->join('retail_replenishment_items as items', 'items.replenishment_id', '=', 'replenishments.id')
            ->whereIn('replenishments.retail_store_id', $storeIds)
            ->where('replenishments.source_wholesale_store_id', $wholesaleStoreId)
            ->whereBetween('replenishments.received_at', [$fromUtc, $toUtc])
            ->get([
                'replenishments.received_at',
                'items.quantity',
                'items.line_total',
            ]);

        $daily = [];
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse((string) $row->received_at, 'UTC')
                ->setTimezone(AnalyticsFactMaterializer::TIMEZONE)
                ->toDateString();
            $daily[$date] ??= ['value' => 0.0, 'units' => 0.0];
            $daily[$date]['value'] += (float) $row->line_total;
            $daily[$date]['units'] += (float) $row->quantity;
        }

        foreach ($daily as &$row) {
            $row['value'] = round($row['value'], 3);
            $row['units'] = round($row['units'], 3);
        }
        unset($row);

        return $daily;
    }

    /** @return array<string,mixed> */
    private function dataQuality(array $current, array $feed, ?string $comparisonUnavailableReason): array
    {
        $score = 100;
        $reasons = [];

        if ($feed['state'] === 'none') {
            $score -= 40;
            $reasons[] = 'sell_out_unavailable';
        } elseif ($feed['state'] === 'partial') {
            $score -= 20;
            $reasons[] = 'sell_out_partial';
        }

        $freshness = (string) ($current['freshness']['state'] ?? 'incomplete');
        if ($freshness === 'incomplete') {
            $score -= 30;
            $reasons[] = 'read_model_incomplete';
        } elseif ($freshness === 'stale') {
            $score -= 15;
            $reasons[] = 'read_model_stale';
        }

        if ($comparisonUnavailableReason !== null) {
            $score -= 10;
            $reasons[] = $comparisonUnavailableReason;
        }

        $score = max(0, min(100, $score));
        $state = $score >= 80 ? 'good' : ($score >= 50 ? 'partial' : 'limited');

        return [
            'state' => $state,
            'score' => $score,
            'badge' => [
                'en' => match ($state) {
                    'good' => 'Good data',
                    'partial' => 'Partial data',
                    default => 'Limited data',
                },
                'ar' => match ($state) {
                    'good' => 'بيانات جيدة',
                    'partial' => 'بيانات جزئية',
                    default => 'بيانات محدودة',
                },
            ],
            'reasons' => array_values(array_unique($reasons)),
            'freshness' => $current['freshness'] ?? null,
            'sell_out_feed' => $feed,
        ];
    }

    /** @return array<string,mixed> */
    private function accountHealth(
        array $sellOut,
        array $trends,
        ?float $stockCover,
        array $reorder,
        array $dataQuality,
    ): array {
        $score = 100;
        $risks = [];
        $opportunities = [];

        $sellOutTrend = $trends['sell_out_revenue']['percent_change'] ?? null;
        if (is_float($sellOutTrend) || is_int($sellOutTrend)) {
            if ($sellOutTrend <= -20) {
                $score -= 20;
                $risks[] = 'sell_out_revenue_down_20_percent_or_more';
            } elseif ($sellOutTrend >= 15) {
                $opportunities[] = 'sell_out_growth';
            }
        }

        $refundRate = $this->rate($sellOut['refunded_orders'], $sellOut['orders']);
        if ($refundRate !== null && $refundRate >= 10) {
            $score -= 15;
            $risks[] = 'high_refund_rate';
        }

        $cancellationRate = $this->rate($sellOut['cancelled_orders'], $sellOut['orders']);
        if ($cancellationRate !== null && $cancellationRate >= 10) {
            $score -= 15;
            $risks[] = 'high_cancellation_rate';
        }

        if (($reorder['status'] ?? null) === 'overdue') {
            $score -= 15;
            $risks[] = 'reorder_overdue_vs_observed_cadence';
        }

        if ($stockCover !== null && $stockCover < 3) {
            $score -= 10;
            $risks[] = 'low_stock_cover';
            $opportunities[] = 'reorder_opportunity';
        }

        if (($dataQuality['sell_out_feed']['state'] ?? 'none') === 'none') {
            $opportunities[] = 'enable_sell_out_sharing_for_better_decisions';
        }

        $score = max(0, min(100, $score));
        $status = $score >= 80 ? 'healthy' : ($score >= 60 ? 'watch' : 'risk');
        $confidence = match ($dataQuality['state'] ?? 'limited') {
            'good' => 'high',
            'partial' => 'medium',
            default => 'low',
        };

        return [
            'score' => $score,
            'status' => $status,
            'confidence' => $confidence,
            'risks' => array_values(array_unique($risks)),
            'opportunities' => array_values(array_unique($opportunities)),
            'explanation' => 'Deterministic rule-based health signal. It is evidence-linked and does not claim causal relationships.',
        ];
    }

    /**
     * @return array{benchmark:array<string,mixed>,_privacy?:array<string,mixed>}
     */
    /** @return array{benchmark:array<string,mixed>} */
    private function cohortBenchmark(): array
    {
        return [
            'benchmark' => [
                'answerable' => false,
                'state' => 'withheld',
                'reason' => 'COHORT_PRIVACY_POLICY_PENDING_2229',
                'policy_issue' => 2229,
                'peer_rows_exposed' => false,
                'peer_identifiers_exposed' => false,
                'differencing_protected' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function compare(float $current, ?float $previous): array
    {
        if ($previous === null) {
            return [
                'current' => round($current, 3),
                'previous' => null,
                'delta' => null,
                'percent_change' => null,
                'reason' => 'missing_previous_period',
            ];
        }

        $delta = round($current - $previous, 3);

        return [
            'current' => round($current, 3),
            'previous' => round($previous, 3),
            'delta' => $delta,
            'percent_change' => $previous == 0.0 ? null : round(($delta / abs($previous)) * 100, 2),
            'reason' => $previous == 0.0 ? 'zero_previous_denominator' : null,
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator <= 0 ? null : round(($numerator / $denominator) * 100, 2);
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    private function normalizeStoreIds(array $storeIds): array
    {
        $normalized = collect($storeIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalized === [] || count($normalized) !== count($storeIds) || count($normalized) > self::MAX_RETAILER_STORES) {
            throw ValidationException::withMessages([
                'retailer_store_ids' => ['Provide between 1 and 25 unique positive retailer store ids.'],
            ]);
        }

        sort($normalized);

        return $normalized;
    }

    /** @return array{0:string,1:string} */
    private function utcRange(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            $from->startOfDay()->utc()->toDateTimeString(),
            $to->endOfDay()->utc()->toDateTimeString(),
        ];
    }
}
