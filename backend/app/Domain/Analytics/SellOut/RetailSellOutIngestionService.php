<?php

namespace App\Domain\Analytics\SellOut;

use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RetailSellOutIngestionService
{
    public const BUSINESS_TIMEZONE = 'Asia/Kuwait';

    public const MAX_ROWS_PER_RUN = 5000;

    private const SOURCE_TYPES = ['pos_api', 'api', 'csv'];

    private const EVENT_TYPES = ['sale', 'refund', 'cancelled'];

    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly RetailerSellOutAccessPolicy $sellOutPolicy,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function connections(User $actor, int $retailerStoreId): array
    {
        $this->scope->assertStore($actor, $retailerStoreId, 'assistant.sellout.share', 'b2c');

        return DB::table('retail_sellout_connections')
            ->where('retailer_store_id', $retailerStoreId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->connectionPayload($row))
            ->all();
    }

    /** @return array<string,mixed> */
    public function connect(
        User $actor,
        int $retailerStoreId,
        string $sourceType,
        string $provider,
        string $sourceKey,
        string $sourceTimezone = self::BUSINESS_TIMEZONE,
    ): array {
        $this->scope->assertStore($actor, $retailerStoreId, 'assistant.sellout.share', 'b2c');

        $sourceType = strtolower(trim($sourceType));
        $provider = trim($provider);
        $sourceKey = trim($sourceKey);

        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw ValidationException::withMessages([
                'source_type' => ['Sell-out source type must be pos_api, api, or csv.'],
            ]);
        }
        if ($provider === '' || strlen($provider) > 64) {
            throw ValidationException::withMessages(['provider' => ['Provider is required and may not exceed 64 characters.']]);
        }
        if ($sourceKey === '' || strlen($sourceKey) > 128) {
            throw ValidationException::withMessages(['source_key' => ['Source key is required and may not exceed 128 characters.']]);
        }
        if (! in_array($sourceTimezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['source_timezone' => ['Source timezone is invalid.']]);
        }

        return DB::transaction(function () use (
            $actor,
            $retailerStoreId,
            $sourceType,
            $provider,
            $sourceKey,
            $sourceTimezone,
        ): array {
            $existing = DB::table('retail_sellout_connections')
                ->where('retailer_store_id', $retailerStoreId)
                ->where('source_type', $sourceType)
                ->where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();

            $before = $existing === null ? null : (array) $existing;
            $now = now('UTC');

            DB::table('retail_sellout_connections')->updateOrInsert(
                [
                    'retailer_store_id' => $retailerStoreId,
                    'source_type' => $sourceType,
                    'source_key' => $sourceKey,
                ],
                [
                    'provider' => $provider,
                    'source_timezone' => $sourceTimezone,
                    'status' => 'active',
                    'created_by_user_id' => $existing?->created_by_user_id ?? $actor->getKey(),
                    'revoked_at' => null,
                    'revoked_by_user_id' => null,
                    'created_at' => $existing?->created_at ?? $now,
                    'updated_at' => $now,
                ],
            );

            $row = DB::table('retail_sellout_connections')
                ->where('retailer_store_id', $retailerStoreId)
                ->where('source_type', $sourceType)
                ->where('source_key', $sourceKey)
                ->firstOrFail();

            $after = (array) $row;
            $after['store_id'] = $retailerStoreId;
            $this->audit->record('assistant.sellout.connection.saved', $actor, null, $before, $after);

            return $this->connectionPayload($row);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function mapSku(User $actor, int $connectionId, string $externalSku, int $productId): array
    {
        $connection = $this->authorizedConnection($actor, $connectionId, true);
        $externalSku = trim($externalSku);
        if ($externalSku === '' || strlen($externalSku) > 128) {
            throw ValidationException::withMessages([
                'external_sku' => ['External SKU is required and may not exceed 128 characters.'],
            ]);
        }

        $this->assertRetailProduct($productId, (int) $connection->retailer_store_id);

        DB::table('retail_sellout_sku_mappings')->updateOrInsert(
            [
                'connection_id' => $connectionId,
                'external_sku' => $externalSku,
            ],
            [
                'product_id' => $productId,
                'mapped_by_user_id' => $actor->getKey(),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ],
        );

        $row = DB::table('retail_sellout_sku_mappings')
            ->where('connection_id', $connectionId)
            ->where('external_sku', $externalSku)
            ->firstOrFail();

        $this->audit->record(
            'assistant.sellout.sku-mapping.saved',
            $actor,
            null,
            null,
            [
                'store_id' => (int) $connection->retailer_store_id,
                'connection_id' => $connectionId,
                'external_sku' => $externalSku,
                'product_id' => $productId,
            ],
        );

        return [
            'id' => (int) $row->id,
            'connection_id' => (int) $row->connection_id,
            'external_sku' => (string) $row->external_sku,
            'product_id' => (int) $row->product_id,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function ingest(User $actor, int $connectionId, string $idempotencyKey, array $rows): array
    {
        if ($rows === [] || count($rows) > self::MAX_ROWS_PER_RUN) {
            throw ValidationException::withMessages([
                'rows' => ['Provide between 1 and 5000 sell-out rows per ingestion run.'],
            ]);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key is required and may not exceed 128 characters.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $connectionId, $idempotencyKey, $rows): array {
            $connection = $this->authorizedConnection($actor, $connectionId, true, true);
            abort_unless((string) $connection->status === 'active', 409, 'Sell-out connection is not active.');

            $existingRun = DB::table('retail_sellout_ingest_runs')
                ->where('connection_id', $connectionId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingRun !== null) {
                return $this->runPayload($existingRun);
            }

            $startedAt = now('UTC');
            $runId = (int) DB::table('retail_sellout_ingest_runs')->insertGetId([
                'connection_id' => $connectionId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'processing',
                'rows_received' => count($rows),
                'started_by_user_id' => $actor->getKey(),
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            DB::table('retail_sellout_connections')->where('id', $connectionId)->update([
                'last_sync_started_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            $accepted = 0;
            $rejected = 0;
            $duplicates = 0;
            $coverageDates = [];
            $sourceTimes = [];

            foreach (array_values($rows) as $index => $row) {
                [$normalized, $error] = $this->normalizeRow(
                    $connection,
                    is_array($row) ? $row : [],
                );

                if ($error !== null) {
                    $rejected++;
                    $this->reject($runId, $index + 1, is_array($row) ? $row : [], $error[0], $error[1]);

                    continue;
                }

                $duplicate = DB::table('retail_sellout_events')
                    ->where('connection_id', $connectionId)
                    ->where('source_event_id', $normalized['source_event_id'])
                    ->exists();

                if ($duplicate) {
                    $duplicates++;
                    $coverageDates[] = $normalized['business_date'];
                    $sourceTimes[] = $normalized['source_updated_at'] ?? $normalized['occurred_at'];

                    continue;
                }

                DB::table('retail_sellout_events')->insert($normalized + [
                    'connection_id' => $connectionId,
                    'retailer_store_id' => (int) $connection->retailer_store_id,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);

                $accepted++;
                $coverageDates[] = $normalized['business_date'];
                $sourceTimes[] = $normalized['source_updated_at'] ?? $normalized['occurred_at'];
            }

            $usable = $accepted + $duplicates;
            $quality = round(($usable / count($rows)) * 100, 2);
            $status = $rejected === 0 ? 'complete' : ($usable > 0 ? 'partial' : 'failed');
            $coverageFrom = $coverageDates === [] ? null : min($coverageDates);
            $coverageTo = $coverageDates === [] ? null : max($coverageDates);
            $sourceMax = $this->latestTimestamp($sourceTimes);
            $completedAt = now('UTC');

            DB::table('retail_sellout_ingest_runs')->where('id', $runId)->update([
                'status' => $status,
                'rows_accepted' => $accepted,
                'rows_rejected' => $rejected,
                'rows_duplicate' => $duplicates,
                'coverage_from' => $coverageFrom,
                'coverage_to' => $coverageTo,
                'quality_score' => $quality,
                'source_max_at' => $sourceMax,
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

            $allCoverage = DB::table('retail_sellout_events')
                ->where('connection_id', $connectionId)
                ->selectRaw('MIN(business_date) as coverage_from, MAX(business_date) as coverage_to')
                ->first();

            DB::table('retail_sellout_connections')->where('id', $connectionId)->update([
                'last_sync_state' => $status,
                'last_sync_at' => $completedAt,
                'last_success_at' => $usable > 0 ? $completedAt : $connection->last_success_at,
                'last_source_at' => $sourceMax ?? $connection->last_source_at,
                'coverage_from' => $allCoverage?->coverage_from,
                'coverage_to' => $allCoverage?->coverage_to,
                'quality_score' => $quality,
                'rows_accepted_total' => DB::raw('rows_accepted_total + '.(int) $accepted),
                'rows_rejected_total' => DB::raw('rows_rejected_total + '.(int) $rejected),
                'rows_duplicate_total' => DB::raw('rows_duplicate_total + '.(int) $duplicates),
                'updated_at' => $completedAt,
            ]);

            $this->audit->record(
                'assistant.sellout.ingest.completed',
                $actor,
                null,
                null,
                [
                    'store_id' => (int) $connection->retailer_store_id,
                    'connection_id' => $connectionId,
                    'run_id' => $runId,
                    'status' => $status,
                    'rows_received' => count($rows),
                    'rows_accepted' => $accepted,
                    'rows_rejected' => $rejected,
                    'rows_duplicate' => $duplicates,
                    'quality_score' => $quality,
                ],
            );

            return $this->runPayload(
                DB::table('retail_sellout_ingest_runs')->where('id', $runId)->firstOrFail(),
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function run(User $actor, int $runId): array
    {
        $run = DB::table('retail_sellout_ingest_runs')->where('id', $runId)->first();
        abort_unless($run !== null, 404);
        $this->authorizedConnection($actor, (int) $run->connection_id, false);

        return $this->runPayload($run);
    }

    public function revoke(User $actor, int $connectionId): void
    {
        $connection = $this->authorizedConnection($actor, $connectionId, true, true);

        if ((string) $connection->status === 'revoked') {
            return;
        }

        $now = now('UTC');
        DB::table('retail_sellout_connections')->where('id', $connectionId)->update([
            'status' => 'revoked',
            'revoked_at' => $now,
            'revoked_by_user_id' => $actor->getKey(),
            'updated_at' => $now,
        ]);

        $this->audit->record(
            'assistant.sellout.connection.revoked',
            $actor,
            null,
            (array) $connection,
            [
                'store_id' => (int) $connection->retailer_store_id,
                'connection_id' => $connectionId,
                'status' => 'revoked',
            ],
        );
    }

    /**
     * Re-authorize protected sell-out visibility and expose explicit source coverage.
     *
     * @param  list<int>  $storeIds
     * @return list<array<string,mixed>>
     */
    public function feedMetadata(
        User $actor,
        array $storeIds,
        ?int $wholesaleStoreId = null,
        string $locale = 'en',
    ): array {
        $this->sellOutPolicy->assertCanReadMany(
            $actor,
            $storeIds,
            $wholesaleStoreId,
            RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS,
            $locale,
        );

        return array_map(fn (int $storeId): array => $this->feedForStore($storeId), $storeIds);
    }

    /** @return array<string,mixed> */
    public function dailyAggregate(int $storeId, string $businessDate): array
    {
        $base = DB::table('retail_sellout_events')
            ->join('retail_sellout_connections as connections', 'connections.id', '=', 'retail_sellout_events.connection_id')
            ->where('retail_sellout_events.retailer_store_id', $storeId)
            ->where('retail_sellout_events.business_date', $businessDate)
            ->where('connections.status', 'active');

        $sales = (clone $base)->where('retail_sellout_events.event_type', 'sale');
        $refunds = (clone $base)->where('retail_sellout_events.event_type', 'refund');
        $cancelled = (clone $base)->where('retail_sellout_events.event_type', 'cancelled');

        $recognizedOrders = (int) (clone $sales)->distinct()->count('retail_sellout_events.transaction_id');
        $netRevenue = round((float) (clone $sales)->sum('retail_sellout_events.net_amount'), 3);
        $currencies = (clone $base)
            ->distinct()
            ->pluck('retail_sellout_events.currency')
            ->map(static fn ($currency): string => strtoupper(trim((string) $currency)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $connectionStates = DB::table('retail_sellout_connections')
            ->where('retailer_store_id', $storeId)
            ->where('status', 'active')
            ->pluck('last_sync_state')
            ->all();

        $quality = count($currencies) <= 1
            && collect($connectionStates)->every(static fn ($state): bool => $state === 'complete');
        $reason = count($currencies) > 1
            ? 'mixed_currency'
            : ($quality ? null : 'partial_external_sellout_feed');

        return [
            'orders_total' => (int) (clone $base)->distinct()->count('retail_sellout_events.transaction_id'),
            'orders_recognized' => $recognizedOrders,
            'gross_value' => round((float) (clone $base)->sum('retail_sellout_events.gross_amount'), 3),
            'gross_revenue' => round((float) (clone $sales)->sum('retail_sellout_events.gross_amount'), 3),
            'discounts_total' => round((float) (clone $sales)->sum('retail_sellout_events.discount_amount'), 3),
            'net_revenue' => $netRevenue,
            'refunded_value' => round((float) (clone $refunds)->sum('retail_sellout_events.net_amount'), 3),
            'refunded_orders' => (int) (clone $refunds)->distinct()->count('retail_sellout_events.transaction_id'),
            'cancelled_value' => round((float) (clone $cancelled)->sum('retail_sellout_events.net_amount'), 3),
            'cancelled_orders' => (int) (clone $cancelled)->distinct()->count('retail_sellout_events.transaction_id'),
            'units_sold' => round((float) (clone $sales)->sum('retail_sellout_events.quantity'), 3),
            'average_order_value' => $recognizedOrders === 0 ? 0.0 : round($netRevenue / $recognizedOrders, 3),
            'currency' => count($currencies) === 1 ? $currencies[0] : null,
            'source_max_updated_at' => $this->latestTimestamp([
                (clone $base)->max('retail_sellout_events.source_updated_at'),
                (clone $base)->max('retail_sellout_events.updated_at'),
            ]),
            'quality_state' => $quality ? 'complete' : 'incomplete',
            'incomplete_reason' => $reason,
        ];
    }

    /** @return array{0:?array<string,mixed>,1:?array{0:string,1:string}} */
    private function normalizeRow(object $connection, array $row): array
    {
        $eventId = $this->boundedString($row['event_id'] ?? null, 128);
        $transactionId = $this->boundedString($row['transaction_id'] ?? null, 128);
        $sku = $this->boundedString($row['sku'] ?? null, 128);
        $eventType = strtolower((string) ($row['event_type'] ?? 'sale'));

        if ($eventId === null) {
            return [null, ['missing_event_id', 'event_id is required.']];
        }
        if ($transactionId === null) {
            return [null, ['missing_transaction_id', 'transaction_id is required.']];
        }
        if ($sku === null) {
            return [null, ['missing_sku', 'sku is required.']];
        }
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            return [null, ['bad_event_type', 'event_type must be sale, refund, or cancelled.']];
        }

        $productId = $this->mappedProductId((int) $connection->id, (int) $connection->retailer_store_id, $sku);
        if ($productId === null) {
            return [null, ['bad_sku', 'SKU is not mapped to an active product in this retail store.']];
        }

        $quantity = filter_var($row['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $gross = filter_var($row['gross_amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $discount = filter_var($row['discount_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
        $net = filter_var($row['net_amount'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($quantity === false || $quantity <= 0) {
            return [null, ['bad_quantity', 'quantity must be greater than zero.']];
        }
        if ($gross === false || $gross < 0 || $discount === false || $discount < 0 || $net === false || $net < 0) {
            return [null, ['bad_amount', 'gross_amount, discount_amount and net_amount must be non-negative numbers.']];
        }
        if ($discount > $gross || $net > $gross) {
            return [null, ['bad_amount', 'discount_amount and net_amount cannot exceed gross_amount.']];
        }

        $currency = strtoupper(trim((string) ($row['currency'] ?? 'KWD')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return [null, ['bad_currency', 'currency must be a three-letter ISO-style code.']];
        }

        try {
            $occurredAt = CarbonImmutable::parse((string) ($row['occurred_at'] ?? ''), (string) $connection->source_timezone);
        } catch (Throwable) {
            return [null, ['bad_timestamp', 'occurred_at is invalid for the configured source timezone.']];
        }

        $sourceUpdatedAt = null;
        if (isset($row['source_updated_at']) && trim((string) $row['source_updated_at']) !== '') {
            try {
                $sourceUpdatedAt = CarbonImmutable::parse((string) $row['source_updated_at'], (string) $connection->source_timezone)
                    ->utc()
                    ->toDateTimeString();
            } catch (Throwable) {
                return [null, ['bad_source_timestamp', 'source_updated_at is invalid for the configured source timezone.']];
            }
        }

        return [[
            'source_event_id' => $eventId,
            'transaction_id' => $transactionId,
            'product_id' => $productId,
            'external_sku' => $sku,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt->utc()->toDateTimeString(),
            'business_date' => $occurredAt->setTimezone(self::BUSINESS_TIMEZONE)->toDateString(),
            'quantity' => round((float) $quantity, 3),
            'gross_amount' => round((float) $gross, 3),
            'discount_amount' => round((float) $discount, 3),
            'net_amount' => round((float) $net, 3),
            'currency' => $currency,
            'source_updated_at' => $sourceUpdatedAt,
        ], null];
    }

    private function reject(int $runId, int $rowNumber, array $row, string $code, string $message): void
    {
        DB::table('retail_sellout_ingest_rejections')->insert([
            'run_id' => $runId,
            'row_number' => $rowNumber,
            'source_event_id' => $this->boundedString($row['event_id'] ?? null, 128),
            'code' => $code,
            'message' => $message,
            'payload_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($row)),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function mappedProductId(int $connectionId, int $storeId, string $sku): ?int
    {
        $mapped = DB::table('retail_sellout_sku_mappings')
            ->where('connection_id', $connectionId)
            ->where('external_sku', $sku)
            ->value('product_id');

        if ($mapped !== null) {
            return (int) $mapped;
        }

        $matches = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('products.sku', $sku)
            ->pluck('products.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function assertRetailProduct(int $productId, int $storeId): void
    {
        $valid = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'product_id' => ['Mapped product must be an active product owned by the selected retail store.'],
            ]);
        }
    }

    private function authorizedConnection(
        User $actor,
        int $connectionId,
        bool $manage,
        bool $lock = false,
    ): object {
        $query = DB::table('retail_sellout_connections')->where('id', $connectionId);
        $connection = $lock ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($connection !== null, 404);

        $this->scope->assertStore(
            $actor,
            (int) $connection->retailer_store_id,
            $manage ? 'assistant.sellout.share' : 'reports.view',
            'b2c',
        );

        return $connection;
    }

    /** @return array<string,mixed> */
    private function feedForStore(int $storeId): array
    {
        $internal = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('channel', 'b2c');

        $internalCount = (int) (clone $internal)->count();
        $internalFirst = (clone $internal)->min('created_at');
        $internalLast = (clone $internal)->max('created_at');
        $internalUpdated = (clone $internal)->max('updated_at');

        $connections = DB::table('retail_sellout_connections')
            ->where('retailer_store_id', $storeId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        $external = DB::table('retail_sellout_events')
            ->join('retail_sellout_connections as connections', 'connections.id', '=', 'retail_sellout_events.connection_id')
            ->where('retail_sellout_events.retailer_store_id', $storeId)
            ->where('connections.status', 'active');

        $externalCount = (int) (clone $external)->count();
        $externalFirst = (clone $external)->min('retail_sellout_events.business_date');
        $externalLast = (clone $external)->max('retail_sellout_events.business_date');

        $hasPartialExternal = $connections->contains(
            static fn (object $connection): bool => ! in_array((string) $connection->last_sync_state, ['complete'], true),
        );

        $hasData = $internalCount + $externalCount > 0;
        $state = ! $hasData ? 'none' : ($hasPartialExternal ? 'partial' : 'available');
        $quality = $connections->isEmpty()
            ? ($hasData ? 100.0 : 0.0)
            : round((float) $connections->avg(static fn (object $connection): float => (float) ($connection->quality_score ?? 0)), 2);

        $coverageCandidatesFrom = array_values(array_filter([
            $internalFirst === null ? null : CarbonImmutable::parse((string) $internalFirst, 'UTC')->setTimezone(self::BUSINESS_TIMEZONE)->toDateString(),
            $externalFirst,
        ]));
        $coverageCandidatesTo = array_values(array_filter([
            $internalLast === null ? null : CarbonImmutable::parse((string) $internalLast, 'UTC')->setTimezone(self::BUSINESS_TIMEZONE)->toDateString(),
            $externalLast,
        ]));

        $lastSync = $this->latestTimestamp(array_merge(
            [$internalUpdated],
            $connections->pluck('last_sync_at')->all(),
        ));

        return [
            'retailer_store_id' => $storeId,
            'state' => $state,
            'message_en' => match ($state) {
                'none' => 'No actual retail sell-out feed is available. Wholesale purchases are not used as a substitute.',
                'partial' => 'Retail sell-out feed is partial; analytics must disclose incomplete coverage.',
                default => 'Actual retail sell-out feed is available.',
            },
            'message_ar' => match ($state) {
                'none' => 'لا توجد بيانات فعلية متاحة للبيع للمستهلك، ولا يتم استخدام مشتريات الجملة كبديل.',
                'partial' => 'بيانات البيع للمستهلك جزئية ويجب إظهار نقص التغطية في التحليلات.',
                default => 'بيانات البيع الفعلية للمستهلك متاحة.',
            },
            'last_sync_at' => $lastSync,
            'coverage_from' => $coverageCandidatesFrom === [] ? null : min($coverageCandidatesFrom),
            'coverage_to' => $coverageCandidatesTo === [] ? null : max($coverageCandidatesTo),
            'quality_score' => $quality,
            'internal_foodex' => [
                'rows' => $internalCount,
                'last_source_at' => $internalUpdated === null ? null : (string) $internalUpdated,
            ],
            'external' => [
                'connections' => $connections->count(),
                'rows' => $externalCount,
                'partial_connections' => $connections->filter(
                    static fn (object $connection): bool => (string) $connection->last_sync_state !== 'complete',
                )->count(),
                'sources' => $connections->map(static fn (object $connection): array => [
                    'id' => (int) $connection->id,
                    'source_type' => (string) $connection->source_type,
                    'provider' => (string) $connection->provider,
                    'last_sync_state' => $connection->last_sync_state === null ? null : (string) $connection->last_sync_state,
                    'last_sync_at' => $connection->last_sync_at === null ? null : (string) $connection->last_sync_at,
                    'coverage_from' => $connection->coverage_from === null ? null : (string) $connection->coverage_from,
                    'coverage_to' => $connection->coverage_to === null ? null : (string) $connection->coverage_to,
                    'quality_score' => $connection->quality_score === null ? null : (float) $connection->quality_score,
                ])->all(),
            ],
            'wholesale_purchase_inference' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function connectionPayload(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'retailer_store_id' => (int) $row->retailer_store_id,
            'source_type' => (string) $row->source_type,
            'provider' => (string) $row->provider,
            'source_key' => (string) $row->source_key,
            'source_timezone' => (string) $row->source_timezone,
            'status' => (string) $row->status,
            'last_sync_state' => $row->last_sync_state === null ? null : (string) $row->last_sync_state,
            'last_sync_at' => $row->last_sync_at === null ? null : (string) $row->last_sync_at,
            'last_success_at' => $row->last_success_at === null ? null : (string) $row->last_success_at,
            'last_source_at' => $row->last_source_at === null ? null : (string) $row->last_source_at,
            'coverage_from' => $row->coverage_from === null ? null : (string) $row->coverage_from,
            'coverage_to' => $row->coverage_to === null ? null : (string) $row->coverage_to,
            'quality_score' => $row->quality_score === null ? null : (float) $row->quality_score,
            'rows_accepted_total' => (int) $row->rows_accepted_total,
            'rows_rejected_total' => (int) $row->rows_rejected_total,
            'rows_duplicate_total' => (int) $row->rows_duplicate_total,
        ];
    }

    /** @return array<string,mixed> */
    private function runPayload(object $run): array
    {
        $rejections = DB::table('retail_sellout_ingest_rejections')
            ->where('run_id', $run->id)
            ->orderBy('row_number')
            ->get()
            ->map(static fn (object $row): array => [
                'row_number' => (int) $row->row_number,
                'source_event_id' => $row->source_event_id === null ? null : (string) $row->source_event_id,
                'code' => (string) $row->code,
                'message' => (string) $row->message,
                'payload_hash' => (string) $row->payload_hash,
            ])
            ->all();

        return [
            'id' => (int) $run->id,
            'connection_id' => (int) $run->connection_id,
            'idempotency_key' => (string) $run->idempotency_key,
            'status' => (string) $run->status,
            'rows_received' => (int) $run->rows_received,
            'rows_accepted' => (int) $run->rows_accepted,
            'rows_rejected' => (int) $run->rows_rejected,
            'rows_duplicate' => (int) $run->rows_duplicate,
            'coverage_from' => $run->coverage_from === null ? null : (string) $run->coverage_from,
            'coverage_to' => $run->coverage_to === null ? null : (string) $run->coverage_to,
            'quality_score' => $run->quality_score === null ? null : (float) $run->quality_score,
            'source_max_at' => $run->source_max_at === null ? null : (string) $run->source_max_at,
            'completed_at' => $run->completed_at === null ? null : (string) $run->completed_at,
            'reconciliation' => [
                'accounted_rows' => (int) $run->rows_accepted + (int) $run->rows_rejected + (int) $run->rows_duplicate,
                'balanced' => (int) $run->rows_received === (int) $run->rows_accepted + (int) $run->rows_rejected + (int) $run->rows_duplicate,
            ],
            'rejections' => $rejections,
        ];
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && strlen($value) <= $max ? $value : null;
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
