<?php

namespace App\Enterprise\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CommercialProfitabilityProjectionRefresher
{
    public const EVENT_TYPE = 'CommercialProfitabilityOrderSnapshot';

    public const EVENT_VERSION = 1;

    public const SCHEMA = 'foodex.commercial.profitability.v1';

    public const MAX_SOURCE_EVENTS_PER_SLICE = 100;

    /**
     * @return array{
     *   scanned:int,
     *   projected:int,
     *   skipped:int,
     *   next_cursor:int,
     *   has_more:bool
     * }
     */
    public function refreshStore(
        int $storeId,
        int $afterId = 0,
        int $limit = 50,
    ): array {
        if ($storeId < 1) {
            throw ValidationException::withMessages([
                'store_id' => ['Profitability refresh requires a positive store identifier.'],
            ]);
        }

        if ($afterId < 0) {
            throw ValidationException::withMessages([
                'after_id' => ['Profitability refresh cursor cannot be negative.'],
            ]);
        }

        if ($limit < 1 || $limit > self::MAX_SOURCE_EVENTS_PER_SLICE) {
            throw ValidationException::withMessages([
                'limit' => ['Profitability refresh slice must contain between 1 and 100 source events.'],
            ]);
        }

        $outbox = $this->source('commercial_outbox');
        $orders = $this->source('orders');
        $facts = $this->source('profitability_facts');
        $valuation = $this->source('valuation_policies');

        $this->assertSources([$outbox, $orders, $facts]);

        $rows = DB::table($outbox)
            ->where('id', '>', $afterId)
            ->where('event_type', self::EVENT_TYPE)
            ->where('event_version', self::EVENT_VERSION)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'event_id', 'payload']);

        $hasMore = $rows->count() > $limit;
        $batch = $rows->take($limit)->values();
        $nextCursor = $batch->isEmpty() ? $afterId : (int) $batch->last()->id;

        $events = [];
        foreach ($batch as $row) {
            $event = $this->parseEvent((int) $row->id, (string) $row->event_id, (string) $row->payload);

            if ((int) $event['scope_store_id'] !== $storeId) {
                continue;
            }

            $events[] = $event;
        }

        if ($events === []) {
            return [
                'scanned' => $batch->count(),
                'projected' => 0,
                'skipped' => $batch->count(),
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ];
        }

        $orderIds = array_values(array_unique(array_map(
            static fn (array $event): int => (int) $event['order_id'],
            $events,
        )));

        $canonicalOrders = DB::table($orders)
            ->where('store_id', $storeId)
            ->whereIn('id', $orderIds)
            ->get(['id', 'store_id', 'created_at'])
            ->keyBy(static fn (object $row): int => (int) $row->id);

        if ($canonicalOrders->count() !== count($orderIds)) {
            throw ValidationException::withMessages([
                'source' => ['A profitability event references an unavailable canonical order.'],
            ]);
        }

        $policies = $this->valuationPolicies($valuation, $storeId, $events);

        $projected = 0;
        $skipped = $batch->count() - count($events);

        foreach ($events as $event) {
            $order = $canonicalOrders->get((int) $event['order_id']);
            if (! is_object($order)) {
                throw ValidationException::withMessages([
                    'source' => ['A profitability event references an unavailable canonical order.'],
                ]);
            }

            $result = DB::transaction(function () use (
                $facts,
                $storeId,
                $event,
                $order,
                $policies,
            ): int {
                $latestSourceId = DB::table($facts)
                    ->where('store_id', $storeId)
                    ->where('order_id', (int) $event['order_id'])
                    ->lockForUpdate()
                    ->max('source_outbox_id');

                if ($latestSourceId !== null && (int) $latestSourceId >= (int) $event['outbox_id']) {
                    return 0;
                }

                $factRows = $this->factRows($storeId, $event, $order, $policies);

                DB::table($facts)
                    ->where('store_id', $storeId)
                    ->where('order_id', (int) $event['order_id'])
                    ->delete();

                if ($factRows !== []) {
                    DB::table($facts)->insert($factRows);
                }

                return count($factRows);
            }, attempts: 3);

            if ($result === 0) {
                $skipped++;
            } else {
                $projected += $result;
            }
        }

        return [
            'scanned' => $batch->count(),
            'projected' => $projected,
            'skipped' => $skipped,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array{
     *   outbox_id:int,
     *   event_id:string,
     *   fingerprint:?string,
     *   occurred_at:CarbonImmutable,
     *   scope_store_id:int,
     *   order_id:int,
     *   order_status:string,
     *   warehouse_id:?int,
     *   channel:string,
     *   customer_id:int,
     *   customer_domain:string,
     *   domain_customer_id:?int,
     *   currency:string,
     *   discount_total:string,
     *   items:list<array{
     *     order_item_id:int,
     *     product_id:int,
     *     quantity:string,
     *     line_total:string
     *   }>
     * }
     */
    private function parseEvent(int $outboxId, string $eventId, string $json): array
    {
        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] contains invalid JSON."],
            ]);
        }

        if (! is_array($envelope)
            || (string) ($envelope['type'] ?? '') !== self::EVENT_TYPE
            || (int) ($envelope['version'] ?? 0) !== self::EVENT_VERSION
            || ! is_array($envelope['scope'] ?? null)
            || ! is_array($envelope['payload'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] has an unsupported event envelope."],
            ]);
        }

        $payload = $envelope['payload'];
        $data = $payload['data'] ?? null;
        if ((string) ($payload['schema'] ?? '') !== self::SCHEMA
            || ! is_array($data)
            || (string) ($data['fact'] ?? '') !== 'order_snapshot'
            || (int) ($data['contract_version'] ?? 0) !== self::EVENT_VERSION
            || ! is_array($data['source'] ?? null)
            || ! is_array($data['customer'] ?? null)
            || ! is_array($data['money'] ?? null)
            || ! is_array($data['items'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] has an unsupported payload contract."],
            ]);
        }

        $scopeStoreId = (int) ($envelope['scope']['store_id'] ?? 0);
        $sourceStoreId = (int) ($data['source']['store_id'] ?? 0);
        $orderId = (int) ($data['source']['order_id'] ?? 0);
        $customerId = (int) ($data['customer']['legacy_customer_id'] ?? 0);
        $channel = strtolower(trim((string) ($data['source']['channel'] ?? '')));
        $currency = strtoupper(trim((string) ($data['money']['currency'] ?? '')));

        if ($scopeStoreId < 1
            || $sourceStoreId !== $scopeStoreId
            || $orderId < 1
            || $customerId < 1
            || ! in_array($channel, ['b2b', 'b2c'], true)
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || count($data['items']) > 100
        ) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] violates source identity or scope invariants."],
            ]);
        }

        $items = [];
        foreach ($data['items'] as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'source' => ["Profitability outbox row [{$outboxId}] contains an invalid line snapshot."],
                ]);
            }

            $orderItemId = (int) ($item['order_item_id'] ?? 0);
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = $this->decimal($item['quantity'] ?? null, 'quantity', $outboxId);
            $lineTotal = $this->decimal($item['line_total'] ?? null, 'line_total', $outboxId);

            if ($orderItemId < 1 || $productId < 1 || (float) $quantity <= 0 || (float) $lineTotal < 0) {
                throw ValidationException::withMessages([
                    'source' => ["Profitability outbox row [{$outboxId}] contains an invalid commercial line snapshot."],
                ]);
            }

            $items[] = [
                'order_item_id' => $orderItemId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
            ];
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] contains no commercial line snapshots."],
            ]);
        }

        $domainCustomerId = $channel === 'b2b'
            ? ($data['customer']['b2b_customer_id'] ?? null)
            : ($data['customer']['b2c_customer_id'] ?? null);

        return [
            'outbox_id' => $outboxId,
            'event_id' => (string) ($envelope['id'] ?? $eventId),
            'fingerprint' => isset($payload['fingerprint']) ? (string) $payload['fingerprint'] : null,
            'occurred_at' => CarbonImmutable::parse((string) ($envelope['occurred_at'] ?? ''), 'UTC'),
            'scope_store_id' => $scopeStoreId,
            'order_id' => $orderId,
            'order_status' => trim((string) ($data['source']['status'] ?? '')),
            'warehouse_id' => isset($data['source']['warehouse_id'])
                ? (int) $data['source']['warehouse_id']
                : null,
            'channel' => $channel,
            'customer_id' => $customerId,
            'customer_domain' => $channel,
            'domain_customer_id' => $domainCustomerId === null ? null : (int) $domainCustomerId,
            'currency' => $currency,
            'discount_total' => $this->decimal($data['money']['discount_total'] ?? 0, 'discount_total', $outboxId),
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return Collection<int, object>
     */
    private function valuationPolicies(string $source, int $storeId, array $events): Collection
    {
        if (Schema::hasTable($source) === false) {
            return collect();
        }

        $productIds = [];
        foreach ($events as $event) {
            foreach ((array) $event['items'] as $item) {
                $productIds[] = (int) $item['product_id'];
            }
        }
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return collect();
        }

        return DB::table($source)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereIn('product_id', $productIds)
            ->get([
                'id',
                'warehouse_id',
                'product_id',
                'unit_cost',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<array<string, mixed>>
     */
    private function factRows(
        int $storeId,
        array $event,
        object $order,
        Collection $policies,
    ): array {
        $items = $event['items'];
        $discountAllocations = $this->allocateDiscount($items, (string) $event['discount_total']);
        $occurredAt = $event['occurred_at'];
        $now = now();
        $rows = [];

        foreach ($items as $index => $item) {
            $gross = round((float) $item['line_total'], 3);
            $discount = $discountAllocations[$index];
            $net = round(max(0.0, $gross - $discount), 3);
            $policy = $this->costPolicy(
                $policies,
                (int) $item['product_id'],
                $event['warehouse_id'] === null ? null : (int) $event['warehouse_id'],
                $occurredAt,
            );

            $unitCost = $policy === null ? null : round((float) $policy->unit_cost, 3);
            $costTotal = $unitCost === null
                ? null
                : round($unitCost * (float) $item['quantity'], 3);
            $margin = $costTotal === null ? null : round($net - $costTotal, 3);

            $rows[] = [
                'source_event_id' => (string) $event['event_id'],
                'source_outbox_id' => (int) $event['outbox_id'],
                'source_fingerprint' => $event['fingerprint'],
                'store_id' => $storeId,
                'order_id' => (int) $event['order_id'],
                'order_item_id' => (int) $item['order_item_id'],
                'order_created_at' => $order->created_at,
                'order_status' => (string) $event['order_status'],
                'channel' => (string) $event['channel'],
                'customer_id' => (int) $event['customer_id'],
                'customer_domain' => (string) $event['customer_domain'],
                'domain_customer_id' => $event['domain_customer_id'],
                'warehouse_id' => $event['warehouse_id'],
                'product_id' => (int) $item['product_id'],
                'currency' => (string) $event['currency'],
                'quantity' => $item['quantity'],
                'gross_revenue' => $this->format($gross),
                'discount_amount' => $this->format($discount),
                'net_revenue' => $this->format($net),
                'unit_cost_snapshot' => $unitCost === null ? null : $this->format($unitCost),
                'cost_total' => $costTotal === null ? null : $this->format($costTotal),
                'margin' => $margin === null ? null : $this->format($margin),
                'cost_state' => $policy === null ? 'missing' : 'valued',
                'cost_policy_id' => $policy?->id,
                'cost_policy_updated_at' => $policy?->updated_at,
                'source_occurred_at' => $occurredAt->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{line_total:string}>  $items
     * @return list<float>
     */
    private function allocateDiscount(array $items, string $discountTotal): array
    {
        $discountMills = max(0, (int) round((float) $discountTotal * 1000));
        $grossMills = array_map(
            static fn (array $item): int => max(0, (int) round((float) $item['line_total'] * 1000)),
            $items,
        );
        $grossTotal = array_sum($grossMills);

        if ($discountMills === 0 || $grossTotal === 0) {
            return array_fill(0, count($items), 0.0);
        }

        $discountMills = min($discountMills, $grossTotal);
        $allocated = [];
        $remaining = $discountMills;

        foreach ($grossMills as $index => $gross) {
            if ($index === array_key_last($grossMills)) {
                $lineDiscount = min($remaining, $gross);
            } else {
                $lineDiscount = min(
                    $gross,
                    (int) floor(($discountMills * $gross) / $grossTotal),
                );
            }

            $allocated[] = $lineDiscount / 1000;
            $remaining -= $lineDiscount;
        }

        return $allocated;
    }

    private function costPolicy(
        Collection $policies,
        int $productId,
        ?int $warehouseId,
        CarbonImmutable $occurredAt,
    ): ?object {
        $eligible = $policies
            ->filter(function (object $policy) use ($productId, $occurredAt): bool {
                if ((int) $policy->product_id !== $productId) {
                    return false;
                }

                $createdAt = CarbonImmutable::parse((string) $policy->created_at, 'UTC');
                $updatedAt = CarbonImmutable::parse((string) $policy->updated_at, 'UTC');

                return $createdAt->lessThanOrEqualTo($occurredAt)
                    && $updatedAt->lessThanOrEqualTo($occurredAt);
            })
            ->values();

        if ($warehouseId !== null) {
            return $eligible->first(
                static fn (object $policy): bool => (int) $policy->warehouse_id === $warehouseId,
            );
        }

        return $eligible->count() === 1 ? $eligible->first() : null;
    }

    private function decimal(mixed $value, string $field, int $outboxId): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'source' => ["Profitability outbox row [{$outboxId}] has invalid {$field}."],
            ]);
        }

        return $this->format((float) $value);
    }

    private function format(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }

    /** @param list<string> $tables */
    private function assertSources(array $tables): void
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table) === false) {
                throw ValidationException::withMessages([
                    'source' => ["Canonical profitability source [{$table}] is unavailable."],
                ]);
            }
        }
    }

    private function source(string $key): string
    {
        $source = config('enterprise_reporting.sources.'.$key);
        if (! is_string($source) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $source) !== 1) {
            throw ValidationException::withMessages([
                'source' => ["Invalid configured profitability source [{$key}]."],
            ]);
        }

        return $source;
    }
}
