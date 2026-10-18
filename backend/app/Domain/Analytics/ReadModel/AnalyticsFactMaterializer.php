<?php

namespace App\Domain\Analytics\ReadModel;

use App\Domain\Analytics\Semantic\MetricDictionary;
use App\Domain\Analytics\Semantic\SemanticMetricService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AnalyticsFactMaterializer
{
    public const TIMEZONE = 'Asia/Kuwait';

    public const MAX_REBUILD_DAYS = 90;

    public function __construct(private SemanticMetricService $semanticMetrics) {}

    /**
     * Rebuild one bounded date slice from authoritative transactional tables.
     *
     * @param list<int> $storeIds
     * @return array{from:string,to:string,stores:int,commerce_rows:int,inventory_rows:int}
     */
    public function rebuild(string $from, string $to, array $storeIds = []): array
    {
        [$fromDate, $toDate] = $this->validatedRange($from, $to, self::MAX_REBUILD_DAYS);
        $stores = $this->stores($storeIds);
        $commerceRows = 0;
        $inventoryRows = 0;

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            $channel = strtolower((string) $store->channel);
            $sourceMax = null;
            $written = 0;
            $lastQuality = 'complete';
            $lastReason = null;

            for ($day = $fromDate; $day->lte($toDate); $day = $day->addDay()) {
                $fact = $this->dailyFact($storeId, $channel, $day);
                DB::table('analytics_daily_commerce_facts')->upsert(
                    [$fact],
                    ['store_id', 'channel', 'fact_date'],
                    array_keys(array_diff_key($fact, array_flip(['store_id', 'channel', 'fact_date']))),
                );

                $commerceRows++;
                $written++;
                $sourceMax = $this->latestTimestamp([$sourceMax, $fact['source_max_updated_at']]);
                $lastQuality = (string) $fact['quality_state'];
                $lastReason = $fact['incomplete_reason'];
            }

            $this->watermark(
                'commerce_daily',
                $storeId,
                $channel,
                $toDate->toDateString(),
                $sourceMax,
                $written,
                $lastQuality,
                $lastReason,
            );

            if ($toDate->isSameDay(CarbonImmutable::now(self::TIMEZONE))) {
                $this->snapshotInventory($storeId, $toDate);
                $inventoryRows++;
            }
        }

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'stores' => count($stores),
            'commerce_rows' => $commerceRows,
            'inventory_rows' => $inventoryRows,
        ];
    }

    /**
     * Chunked historical backfill; each transactional slice is capped at 90 days.
     *
     * @param list<int> $storeIds
     * @return array{from:string,to:string,chunks:int,commerce_rows:int,inventory_rows:int}
     */
    public function backfill(string $from, string $to, array $storeIds = []): array
    {
        [$fromDate, $toDate] = $this->validatedRange($from, $to, 3660);
        $cursor = $fromDate;
        $chunks = 0;
        $commerceRows = 0;
        $inventoryRows = 0;

        while ($cursor->lte($toDate)) {
            $chunkTo = $cursor->addDays(self::MAX_REBUILD_DAYS - 1);
            if ($chunkTo->gt($toDate)) {
                $chunkTo = $toDate;
            }

            $result = $this->rebuild($cursor->toDateString(), $chunkTo->toDateString(), $storeIds);
            $chunks++;
            $commerceRows += $result['commerce_rows'];
            $inventoryRows += $result['inventory_rows'];
            $cursor = $chunkTo->addDay();
        }

        return [
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'chunks' => $chunks,
            'commerce_rows' => $commerceRows,
            'inventory_rows' => $inventoryRows,
        ];
    }

    /**
     * Recompute the rolling edge so late-arriving updates become visible.
     *
     * @param list<int> $storeIds
     * @return array{from:string,to:string,stores:int,commerce_rows:int,inventory_rows:int}
     */
    public function refreshIncremental(array $storeIds = []): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $from = $today->subDay();

        if ($storeIds !== []) {
            $earliestWatermark = DB::table('analytics_pipeline_watermarks')
                ->where('pipeline', 'commerce_daily')
                ->whereIn('store_id', $storeIds)
                ->min('materialized_through_date');

            if ($earliestWatermark === null) {
                $from = $today->subDays(6);
            }
        } elseif (! DB::table('analytics_pipeline_watermarks')->where('pipeline', 'commerce_daily')->exists()) {
            $from = $today->subDays(6);
        }

        return $this->rebuild($from->toDateString(), $today->toDateString(), $storeIds);
    }

    /** @return array<string,mixed> */
    private function dailyFact(int $storeId, string $channel, CarbonImmutable $day): array
    {
        [$fromUtc, $toUtc] = $this->utcBounds($day);
        $orders = DB::table('orders')
            ->where('orders.store_id', $storeId)
            ->where('orders.channel', $channel)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc]);

        $currencyValues = (clone $orders)
            ->whereNotNull('orders.currency')
            ->distinct()
            ->pluck('orders.currency')
            ->map(static fn ($currency): string => strtoupper(trim((string) $currency)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $currency = count($currencyValues) === 1 ? $currencyValues[0] : null;
        $quality = count($currencyValues) <= 1 ? 'complete' : 'incomplete';
        $reason = count($currencyValues) <= 1 ? null : 'mixed_currency';

        $metrics = $this->semanticMetrics->orderSnapshot(
            $orders,
            $channel === 'b2b' ? MetricDictionary::FLOW_SELL_IN : MetricDictionary::FLOW_SELL_OUT,
            $day->toDateString(),
            $day->toDateString(),
            ['store_ids' => [$storeId]],
            $currency ?? 'KWD',
        );

        $paymentBase = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.store_id', $storeId)
            ->where('orders.channel', $channel)
            ->whereBetween('payments.created_at', [$fromUtc, $toUtc]);

        $paymentsCollected = round((float) (clone $paymentBase)
            ->whereIn('payments.status', ['paid', 'captured'])
            ->sum('payments.amount'), 3);
        $paymentsRefunded = round((float) (clone $paymentBase)
            ->where('payments.status', 'refunded')
            ->sum('payments.amount'), 3);

        $wholesalePurchases = 0.0;
        $wholesalePurchaseUnits = 0.0;
        if ($channel === 'b2c') {
            $purchaseBase = DB::table('retail_replenishments as replenishments')
                ->join('retail_replenishment_items as items', 'items.replenishment_id', '=', 'replenishments.id')
                ->where('replenishments.retail_store_id', $storeId)
                ->whereBetween('replenishments.received_at', [$fromUtc, $toUtc]);

            $wholesalePurchases = round((float) (clone $purchaseBase)->sum('items.line_total'), 3);
            $wholesalePurchaseUnits = round((float) (clone $purchaseBase)->sum('items.quantity'), 3);
        }

        $sourceMax = $this->latestTimestamp([
            (clone $orders)->max('orders.updated_at'),
            (clone $paymentBase)->max('payments.updated_at'),
            DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.store_id', $storeId)
                ->where('orders.channel', $channel)
                ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
                ->max('order_items.updated_at'),
            $channel === 'b2c'
                ? DB::table('retail_replenishments')
                    ->where('retail_store_id', $storeId)
                    ->whereBetween('received_at', [$fromUtc, $toUtc])
                    ->max('updated_at')
                : null,
        ]);

        return [
            'store_id' => $storeId,
            'channel' => $channel,
            'fact_date' => $day->toDateString(),
            'orders_total' => (int) $metrics['values']['orders'],
            'orders_recognized' => (int) $metrics['values']['recognized_orders'],
            'gross_value' => round((float) (clone $orders)->sum('orders.grand_total'), 3),
            'gross_revenue' => (float) $metrics['values']['gross_revenue'],
            'discounts_total' => (float) $metrics['values']['discounts'],
            'net_revenue' => (float) $metrics['values']['net_revenue'],
            'refunded_value' => (float) $metrics['values']['refunded_value'],
            'refunded_orders' => (int) $metrics['values']['refunded_orders'],
            'cancelled_value' => (float) $metrics['values']['cancelled_value'],
            'cancelled_orders' => (int) $metrics['values']['cancelled_orders'],
            'units_sold' => (float) $metrics['values']['units'],
            'average_order_value' => (float) $metrics['values']['average_order_value'],
            'payments_collected' => $paymentsCollected,
            'payments_refunded' => $paymentsRefunded,
            'wholesale_purchases' => $wholesalePurchases,
            'wholesale_purchase_units' => $wholesalePurchaseUnits,
            'currency' => $currency,
            'source_max_updated_at' => $sourceMax,
            'materialized_at' => now('UTC'),
            'quality_state' => $quality,
            'incomplete_reason' => $reason,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];
    }

    private function snapshotInventory(int $storeId, CarbonImmutable $day): void
    {
        $inventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId);

        $onHand = round((float) (clone $inventory)->sum('inventories.quantity'), 3);
        $reserved = round((float) (clone $inventory)->sum('inventories.reserved_quantity'), 3);
        $available = round($onHand - $reserved, 3);
        $skuCount = (int) (clone $inventory)->distinct()->count('inventories.product_id');
        $sourceMax = $this->latestTimestamp([
            (clone $inventory)->max('inventories.updated_at'),
            DB::table('stock_movements')
                ->join('inventories', 'inventories.id', '=', 'stock_movements.inventory_id')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', $storeId)
                ->max('stock_movements.updated_at'),
        ]);

        $row = [
            'store_id' => $storeId,
            'snapshot_date' => $day->toDateString(),
            'on_hand_units' => $onHand,
            'reserved_units' => $reserved,
            'available_units' => $available,
            'sku_count' => $skuCount,
            'source_max_updated_at' => $sourceMax,
            'materialized_at' => now('UTC'),
            'quality_state' => 'complete',
            'incomplete_reason' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];

        DB::table('analytics_inventory_snapshots')->upsert(
            [$row],
            ['store_id', 'snapshot_date'],
            array_keys(array_diff_key($row, array_flip(['store_id', 'snapshot_date']))),
        );

        $this->watermark(
            'inventory_snapshot',
            $storeId,
            'all',
            $day->toDateString(),
            $sourceMax,
            1,
            'complete',
            null,
        );
    }

    private function watermark(
        string $pipeline,
        int $storeId,
        string $channel,
        string $throughDate,
        mixed $sourceMax,
        int $rowsWritten,
        string $quality,
        ?string $reason,
    ): void {
        $row = [
            'pipeline' => $pipeline,
            'store_id' => $storeId,
            'channel' => $channel,
            'materialized_through_date' => $throughDate,
            'source_max_updated_at' => $sourceMax,
            'materialized_at' => now('UTC'),
            'rows_written' => $rowsWritten,
            'quality_state' => $quality,
            'incomplete_reason' => $reason,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];

        DB::table('analytics_pipeline_watermarks')->upsert(
            [$row],
            ['pipeline', 'store_id', 'channel'],
            array_keys(array_diff_key($row, array_flip(['pipeline', 'store_id', 'channel']))),
        );
    }

    /** @param list<int> $storeIds
     * @return list<object>
     */
    private function stores(array $storeIds): array
    {
        $ids = collect($storeIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->whereIn('store_types.code', ['B2B', 'B2C'])
            ->when($ids !== [], fn (Builder $builder) => $builder->whereIn('stores.id', $ids))
            ->orderBy('stores.id');

        $stores = $query->get(['stores.id', 'store_types.code as channel'])->all();

        if ($ids !== [] && count($stores) !== count($ids)) {
            throw ValidationException::withMessages([
                'store_ids' => ['Every requested analytics store must be an active B2B or B2C store.'],
            ]);
        }

        return $stores;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function validatedRange(string $from, string $to, int $maxDays): array
    {
        $fromDate = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay();

        if ($toDate->lt($fromDate) || $fromDate->diffInDays($toDate) + 1 > $maxDays) {
            throw ValidationException::withMessages([
                'to' => ["Analytics rebuild range must be between 1 and {$maxDays} days."],
            ]);
        }

        return [$fromDate, $toDate];
    }

    /** @return array{0:string,1:string} */
    private function utcBounds(CarbonImmutable $day): array
    {
        return [
            $day->startOfDay()->utc()->toDateTimeString(),
            $day->endOfDay()->utc()->toDateTimeString(),
        ];
    }

    /** @param list<mixed> $values */
    private function latestTimestamp(array $values): ?string
    {
        $latest = collect($values)
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): CarbonImmutable => CarbonImmutable::parse($value, 'UTC'))
            ->sortByDesc(static fn (CarbonImmutable $value): int => $value->getTimestamp())
            ->first();

        return $latest instanceof CarbonImmutable ? $latest->utc()->toDateTimeString() : null;
    }
}
