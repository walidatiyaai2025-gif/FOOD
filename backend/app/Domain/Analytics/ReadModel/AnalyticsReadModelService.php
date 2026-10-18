<?php

namespace App\Domain\Analytics\ReadModel;

use App\Domain\Analytics\Semantic\MetricDictionary;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AnalyticsReadModelService
{
    public const MAX_DAYS = 90;

    public const MAX_ROWS = 1000;

    public const FRESHNESS_MINUTES = 20;

    public function __construct(
        private OperationalTenantScope $scope,
        private RetailerSellOutAccessPolicy $sellOutPolicy,
    ) {}

    /**
     * Read only materialized facts; no model or caller can provide SQL.
     *
     * @param  list<int>  $storeIds
     * @return array<string,mixed>
     */
    public function read(
        User $actor,
        string $channel,
        array $storeIds,
        string $from,
        string $to,
        ?int $wholesaleStoreId = null,
        string $locale = 'en',
    ): array {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages(['channel' => ['Analytics channel must be b2b or b2c.']]);
        }

        [$fromDate, $toDate, $days] = $this->range($from, $to);
        $requestedStoreIds = $this->authorizedStores(
            $actor,
            $channel,
            $storeIds,
            $fromDate,
            $wholesaleStoreId,
            $locale,
        );

        if (count($requestedStoreIds) * $days > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'store_ids' => ['Requested analytics result exceeds the 1000-row server-side limit. Reduce stores or date range.'],
            ]);
        }

        $rows = DB::table('analytics_daily_commerce_facts')
            ->whereIn('store_id', $requestedStoreIds)
            ->where('channel', $channel)
            ->whereBetween('fact_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('fact_date')
            ->orderBy('store_id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(static fn (object $row): array => [
                'store_id' => (int) $row->store_id,
                'channel' => (string) $row->channel,
                'flow' => (string) $row->channel === 'b2b'
                    ? MetricDictionary::FLOW_SELL_IN
                    : MetricDictionary::FLOW_SELL_OUT,
                'date' => (string) $row->fact_date,
                'orders' => (int) $row->orders_total,
                'recognized_orders' => (int) $row->orders_recognized,
                'gross_value' => round((float) $row->gross_value, 3),
                'gross_revenue' => round((float) $row->gross_revenue, 3),
                'discounts' => round((float) $row->discounts_total, 3),
                'net_revenue' => round((float) $row->net_revenue, 3),
                'refunded_value' => round((float) $row->refunded_value, 3),
                'refunded_orders' => (int) $row->refunded_orders,
                'cancelled_value' => round((float) $row->cancelled_value, 3),
                'cancelled_orders' => (int) $row->cancelled_orders,
                'units' => round((float) $row->units_sold, 3),
                'average_order_value' => round((float) $row->average_order_value, 3),
                'payments_collected' => round((float) $row->payments_collected, 3),
                'payments_refunded' => round((float) $row->payments_refunded, 3),
                'wholesale_purchases' => round((float) $row->wholesale_purchases, 3),
                'wholesale_purchase_units' => round((float) $row->wholesale_purchase_units, 3),
                'currency' => $row->currency === null ? null : (string) $row->currency,
                'quality_state' => (string) $row->quality_state,
                'incomplete_reason' => $row->incomplete_reason === null ? null : (string) $row->incomplete_reason,
                'source_max_updated_at' => $row->source_max_updated_at === null ? null : (string) $row->source_max_updated_at,
                'materialized_at' => (string) $row->materialized_at,
            ])
            ->all();

        $inventory = DB::table('analytics_inventory_snapshots as snapshots')
            ->joinSub(
                DB::table('analytics_inventory_snapshots')
                    ->whereIn('store_id', $requestedStoreIds)
                    ->where('snapshot_date', '<=', $toDate->toDateString())
                    ->selectRaw('store_id, MAX(snapshot_date) as snapshot_date')
                    ->groupBy('store_id'),
                'latest',
                function ($join): void {
                    $join->on('latest.store_id', '=', 'snapshots.store_id')
                        ->on('latest.snapshot_date', '=', 'snapshots.snapshot_date');
                },
            )
            ->orderBy('snapshots.store_id')
            ->get([
                'snapshots.store_id',
                'snapshots.snapshot_date',
                'snapshots.on_hand_units',
                'snapshots.reserved_units',
                'snapshots.available_units',
                'snapshots.sku_count',
                'snapshots.quality_state',
                'snapshots.incomplete_reason',
                'snapshots.source_max_updated_at',
                'snapshots.materialized_at',
            ])
            ->map(static fn (object $row): array => [
                'store_id' => (int) $row->store_id,
                'snapshot_date' => (string) $row->snapshot_date,
                'on_hand_units' => round((float) $row->on_hand_units, 3),
                'reserved_units' => round((float) $row->reserved_units, 3),
                'available_units' => round((float) $row->available_units, 3),
                'sku_count' => (int) $row->sku_count,
                'quality_state' => (string) $row->quality_state,
                'incomplete_reason' => $row->incomplete_reason === null ? null : (string) $row->incomplete_reason,
                'source_max_updated_at' => $row->source_max_updated_at === null ? null : (string) $row->source_max_updated_at,
                'materialized_at' => (string) $row->materialized_at,
            ])
            ->all();

        $expectedRows = count($requestedStoreIds) * $days;
        $qualityIncomplete = collect($rows)->contains(
            static fn (array $row): bool => $row['quality_state'] !== 'complete',
        );
        $latestMaterializedAt = collect($rows)
            ->pluck('materialized_at')
            ->filter()
            ->sortDesc()
            ->first();

        $recentWindow = $toDate->gte(CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay()->subDay());
        $stale = $recentWindow && (
            ! is_string($latestMaterializedAt)
            || CarbonImmutable::parse($latestMaterializedAt, 'UTC')->lt(now('UTC')->subMinutes(self::FRESHNESS_MINUTES))
        );
        $complete = count($rows) === $expectedRows && ! $qualityIncomplete;

        $state = ! $complete ? 'incomplete' : ($stale ? 'stale' : 'fresh');

        return [
            'metric_dictionary_version' => MetricDictionary::VERSION,
            'channel' => $channel,
            'flow' => $channel === 'b2b' ? MetricDictionary::FLOW_SELL_IN : MetricDictionary::FLOW_SELL_OUT,
            'timezone' => AnalyticsFactMaterializer::TIMEZONE,
            'window' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'days' => $days,
            ],
            'store_ids' => $requestedStoreIds,
            'facts' => $rows,
            'inventory_snapshots' => $inventory,
            'freshness' => [
                'state' => $state,
                'expected_rows' => $expectedRows,
                'actual_rows' => count($rows),
                'latest_materialized_at' => $latestMaterializedAt,
                'stale_after_minutes' => self::FRESHNESS_MINUTES,
                'incomplete_rows' => collect($rows)->where('quality_state', '!=', 'complete')->count(),
            ],
            'limits' => [
                'max_days' => self::MAX_DAYS,
                'max_rows' => self::MAX_ROWS,
                'model_sql_allowed' => false,
            ],
        ];
    }

    /**
     * @param  list<int>  $requested
     * @return list<int>
     */
    private function authorizedStores(
        User $actor,
        string $channel,
        array $requested,
        CarbonImmutable $from,
        ?int $wholesaleStoreId,
        string $locale,
    ): array {
        $provided = $requested;
        $requested = collect($requested)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($provided !== [] && count($requested) !== count($provided)) {
            throw ValidationException::withMessages([
                'store_ids' => ['Requested analytics store ids must be unique positive integers.'],
            ]);
        }

        if (count($requested) > 100) {
            throw ValidationException::withMessages(['store_ids' => ['At most 100 analytics stores may be requested.']]);
        }

        $direct = array_values(array_intersect(
            $this->scope->allowedStoreIds($actor, 'assistant.use', $channel),
            $this->scope->allowedStoreIds($actor, 'reports.view', $channel),
        ));
        sort($direct);

        if ($requested === []) {
            $requested = $direct;
        }

        if ($requested === []) {
            abort(403);
        }

        if (count($requested) > 100) {
            throw ValidationException::withMessages(['store_ids' => ['At most 100 analytics stores may be requested.']]);
        }

        $remote = array_values(array_diff($requested, $direct));
        if ($remote !== []) {
            if ($channel !== 'b2c') {
                abort(404);
            }

            $grants = $this->sellOutPolicy->assertCanReadMany(
                $actor,
                $remote,
                $wholesaleStoreId,
                RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS,
                $locale,
            );

            foreach ($grants as $grant) {
                $retentionDays = $grant['retention_days'] ?? null;
                if (is_int($retentionDays) && $retentionDays > 0) {
                    $earliest = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)
                        ->startOfDay()
                        ->subDays($retentionDays - 1);

                    if ($from->lt($earliest)) {
                        throw ValidationException::withMessages([
                            'from' => ['Requested sell-out analytics predates the active data-sharing retention window.'],
                        ]);
                    }
                }
            }
        }

        sort($requested);

        return $requested;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:int} */
    private function range(string $from, string $to): array
    {
        $fromDate = CarbonImmutable::parse($from, AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $days = (int) $fromDate->diffInDays($toDate) + 1;

        if ($toDate->lt($fromDate) || $days < 1 || $days > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Analytics query range must be between 1 and 90 days.'],
            ]);
        }

        return [$fromDate, $toDate, $days];
    }
}
