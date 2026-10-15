<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\SmartOrderingCart;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingAnalyticsService
{
    public const EVENTS = [
        'storefront_viewed',
        'qr_scanned',
        'category_viewed',
        'product_viewed',
        'search_performed',
        'modifier_opened',
        'item_added',
        'item_removed',
        'cart_started',
        'checkout_started',
        'order_created',
        'order_confirmed',
        'order_completed',
        'order_cancelled',
        'offer_impression',
        'offer_clicked',
        'offer_redeemed',
        'repeat_order_started',
        'repeat_order_completed',
        'feedback_submitted',
    ];

    public const PUBLIC_EVENTS = [
        'storefront_viewed',
        'category_viewed',
        'product_viewed',
        'search_performed',
        'modifier_opened',
        'offer_impression',
        'offer_clicked',
    ];

    private const CUSTOMER_TYPES = ['new', 'repeat'];
    private const FULFILLMENT = ['dine_in', 'pickup', 'delivery'];

    /**
     * @param array<string,mixed> $dimensions
     */
    public function record(
        int $storeId,
        string $eventName,
        ?string $eventIdentity = null,
        array $dimensions = [],
        ?CarbonInterface $occurredAt = null,
    ): bool {
        $eventName = strtolower(trim($eventName));
        if (! in_array($eventName, self::EVENTS, true)) {
            throw ValidationException::withMessages(['event_name' => ['Unsupported Smart Ordering analytics event.']]);
        }

        $occurredAt ??= now();
        $eventKey = hash('sha256', $storeId.'|'.($eventIdentity ?: Str::uuid()->toString()));
        $branchId = $this->nullablePositiveInt($dimensions['branch_id'] ?? null);
        $productId = $this->nullablePositiveInt($dimensions['product_id'] ?? null);
        $categoryId = $this->nullablePositiveInt($dimensions['category_id'] ?? null);
        $orderId = $this->nullablePositiveInt($dimensions['order_id'] ?? null);
        $visitorHash = $this->hashValue($dimensions['visitor_token'] ?? $dimensions['visitor_hash'] ?? null, 'visitor');
        $qrPublicId = $this->uuid($dimensions['qr_public_id'] ?? null);
        $source = $this->bounded($dimensions['source'] ?? null, 64);
        $campaign = $this->bounded($dimensions['campaign'] ?? null, 120);
        $offer = $this->bounded($dimensions['offer_reference'] ?? null, 120);
        $customerType = $this->enum($dimensions['customer_type'] ?? null, self::CUSTOMER_TYPES);
        $fulfillment = $this->enum($dimensions['fulfillment_mode'] ?? null, self::FULFILLMENT);
        $cartPublicId = $this->uuid($dimensions['cart_public_id'] ?? null);
        $revenue = round(max(0.0, (float) ($dimensions['revenue'] ?? 0)), 3);
        $metadata = $this->safeMetadata((array) ($dimensions['metadata'] ?? []));
        $rating = $eventName === 'feedback_submitted' && isset($metadata['rating'])
            ? max(0.0, min(5.0, (float) $metadata['rating']))
            : null;

        return DB::transaction(function () use (
            $storeId,
            $eventName,
            $eventKey,
            $branchId,
            $productId,
            $categoryId,
            $orderId,
            $visitorHash,
            $qrPublicId,
            $source,
            $campaign,
            $offer,
            $customerType,
            $fulfillment,
            $cartPublicId,
            $revenue,
            $metadata,
            $rating,
            $occurredAt,
        ): bool {
            $inserted = DB::table('smart_ordering_analytics_events')->insertOrIgnore([
                'store_id' => $storeId,
                'branch_id' => $branchId,
                'event_name' => $eventName,
                'event_key' => $eventKey,
                'visitor_hash' => $visitorHash,
                'cart_public_id' => $cartPublicId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'qr_public_id' => $qrPublicId,
                'source_snapshot' => $source,
                'campaign_snapshot' => $campaign,
                'offer_reference' => $offer,
                'customer_type' => $customerType,
                'fulfillment_mode' => $fulfillment,
                'revenue' => $revenue,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted !== 1) {
                return false;
            }

            $date = $this->localEventDate($branchId, $occurredAt);
            $hour = $this->localEventHour($branchId, $occurredAt);
            $branchKey = $branchId ?? 0;
            $this->incrementDaily(
                $date,
                $storeId,
                $branchKey,
                $eventName,
                $qrPublicId ?? '',
                $source ?? '',
                $campaign ?? '',
                $offer ?? '',
                $customerType ?? '',
                $fulfillment ?? '',
                $revenue,
                $rating,
            );

            $this->incrementHourly(
                $date,
                $hour,
                $storeId,
                $branchKey,
                $eventName,
                $qrPublicId ?? '',
                $source ?? '',
                $campaign ?? '',
                $fulfillment ?? '',
                $revenue,
            );

            if ($visitorHash !== null) {
                DB::table('smart_ordering_analytics_daily_visitors')->insertOrIgnore([
                    'event_date' => $date,
                    'store_id' => $storeId,
                    'branch_key' => $branchKey,
                    'qr_key' => $qrPublicId ?? '',
                    'source_key' => $source ?? '',
                    'campaign_key' => $campaign ?? '',
                    'visitor_hash' => $visitorHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($productId !== null) {
                $this->incrementProductDaily($date, $storeId, $branchKey, $productId, $eventName, $revenue);
            }

            if ($categoryId !== null) {
                $this->incrementCategoryDaily($date, $storeId, $branchKey, $categoryId, $eventName, $revenue);
            }

            return true;
        }, 3);
    }

    /**
     * @param array<string,mixed> $dimensions
     */
    public function recordForCart(
        SmartOrderingCart $cart,
        string $eventName,
        ?string $eventIdentity = null,
        array $dimensions = [],
    ): bool {
        $branch = SmartOrderingBranch::query()->findOrFail((int) $cart->branch_id);

        return $this->record(
            (int) $branch->store_id,
            $eventName,
            $eventIdentity,
            $dimensions + [
                'branch_id' => (int) $branch->getKey(),
                'cart_public_id' => (string) $cart->public_id,
            ],
        );
    }

    public function rollupProductOnly(
        int $storeId,
        int $branchId,
        int $productId,
        string $eventName,
        float $revenue = 0.0,
        ?CarbonInterface $occurredAt = null,
    ): void {
        abort_unless(in_array($eventName, self::EVENTS, true), 422, 'Unsupported Smart Ordering analytics event.');
        abort_unless($storeId > 0 && $branchId > 0 && $productId > 0, 422, 'Invalid product analytics scope.');

        $occurredAt ??= now();
        DB::transaction(function () use ($storeId, $branchId, $productId, $eventName, $revenue, $occurredAt): void {
            $this->incrementProductDaily(
                $this->localEventDate($branchId, $occurredAt),
                $storeId,
                $branchId,
                $productId,
                $eventName,
                round(max(0.0, $revenue), 3),
            );
        }, 3);
    }

    public function rollupCategoryOnly(
        int $storeId,
        int $branchId,
        int $categoryId,
        string $eventName,
        float $revenue = 0.0,
        ?CarbonInterface $occurredAt = null,
    ): void {
        abort_unless(in_array($eventName, self::EVENTS, true), 422, 'Unsupported Smart Ordering analytics event.');
        abort_unless($storeId > 0 && $branchId > 0 && $categoryId > 0, 422, 'Invalid category analytics scope.');

        $occurredAt ??= now();
        DB::transaction(function () use ($storeId, $branchId, $categoryId, $eventName, $revenue, $occurredAt): void {
            $this->incrementCategoryDaily(
                $this->localEventDate($branchId, $occurredAt),
                $storeId,
                $branchId,
                $categoryId,
                $eventName,
                round(max(0.0, $revenue), 3),
            );
        }, 3);
    }

    /**
     * Public browser analytics are deliberately limited to non-financial interaction events.
     *
     * @param array<string,mixed> $dimensions
     */
    public function recordPublic(
        SmartOrderingSite $site,
        string $branchPublicId,
        string $eventName,
        string $eventId,
        string $visitorToken,
        array $dimensions = [],
    ): bool {
        $eventName = strtolower(trim($eventName));
        abort_unless(in_array($eventName, self::PUBLIC_EVENTS, true), 422, 'Unsupported public analytics event.');
        abort_unless(Str::isUuid($eventId), 422, 'Analytics event id must be a UUID.');
        abort_unless(strlen($visitorToken) >= 16 && strlen($visitorToken) <= 160, 422, 'Invalid analytics visitor token.');

        $branch = SmartOrderingBranch::query()
            ->where('site_id', $site->getKey())
            ->where('public_id', $branchPublicId)
            ->firstOrFail();

        return $this->record(
            (int) $site->store_id,
            $eventName,
            'public:'.$eventId,
            $dimensions + [
                'branch_id' => (int) $branch->getKey(),
                'visitor_token' => $visitorToken,
            ],
        );
    }

    public function pruneExpired(): int
    {
        $days = max(30, (int) config('smart_ordering.analytics.retention_days', 400));
        $cutoff = now()->subDays($days)->startOfDay();

        return DB::table('smart_ordering_analytics_events')->where('occurred_at', '<', $cutoff)->delete();
    }

    private function incrementDaily(
        string $date,
        int $storeId,
        int $branchKey,
        string $eventName,
        string $qr,
        string $source,
        string $campaign,
        string $offer,
        string $customerType,
        string $fulfillment,
        float $revenue,
        ?float $rating = null,
    ): void {
        $dimensions = [
            'event_date' => $date,
            'store_id' => $storeId,
            'branch_key' => $branchKey,
            'event_name' => $eventName,
            'qr_key' => $qr,
            'source_key' => $source,
            'campaign_key' => $campaign,
            'offer_key' => $offer,
            'customer_type_key' => $customerType,
            'fulfillment_mode_key' => $fulfillment,
        ];

        $inserted = DB::table('smart_ordering_analytics_daily')->insertOrIgnore([
            ...$dimensions,
            'event_count' => 1,
            'revenue' => $revenue,
            'rating_sum' => $rating ?? 0,
            'rating_count' => $rating === null ? 0 : 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return;
        }

        DB::table('smart_ordering_analytics_daily')
            ->where($dimensions)
            ->update([
                'event_count' => DB::raw('event_count + 1'),
                'revenue' => DB::raw('revenue + '.number_format($revenue, 3, '.', '')),
                'rating_sum' => DB::raw('rating_sum + '.number_format($rating ?? 0, 3, '.', '')),
                'rating_count' => DB::raw('rating_count + '.($rating === null ? '0' : '1')),
                'updated_at' => now(),
            ]);
    }

    private function incrementProductDaily(
        string $date,
        int $storeId,
        int $branchKey,
        int $productId,
        string $eventName,
        float $revenue,
    ): void {
        $dimensions = [
            'event_date' => $date,
            'store_id' => $storeId,
            'branch_key' => $branchKey,
            'product_id' => $productId,
            'event_name' => $eventName,
        ];

        $inserted = DB::table('smart_ordering_analytics_product_daily')->insertOrIgnore([
            ...$dimensions,
            'event_count' => 1,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return;
        }

        DB::table('smart_ordering_analytics_product_daily')
            ->where($dimensions)
            ->update([
                'event_count' => DB::raw('event_count + 1'),
                'revenue' => DB::raw('revenue + '.number_format($revenue, 3, '.', '')),
                'updated_at' => now(),
            ]);
    }

    private function incrementCategoryDaily(
        string $date,
        int $storeId,
        int $branchKey,
        int $categoryId,
        string $eventName,
        float $revenue,
    ): void {
        $dimensions = [
            'event_date' => $date,
            'store_id' => $storeId,
            'branch_key' => $branchKey,
            'category_id' => $categoryId,
            'event_name' => $eventName,
        ];

        $inserted = DB::table('smart_ordering_analytics_category_daily')->insertOrIgnore([
            ...$dimensions,
            'event_count' => 1,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            DB::table('smart_ordering_analytics_category_daily')
                ->where($dimensions)
                ->update([
                    'event_count' => DB::raw('event_count + 1'),
                    'revenue' => DB::raw('revenue + '.number_format($revenue, 3, '.', '')),
                    'updated_at' => now(),
                ]);
        }
    }

    private function incrementHourly(
        string $date,
        int $hour,
        int $storeId,
        int $branchKey,
        string $eventName,
        string $qr,
        string $source,
        string $campaign,
        string $fulfillment,
        float $revenue,
    ): void {
        $dimensions = [
            'event_date' => $date,
            'event_hour' => $hour,
            'store_id' => $storeId,
            'branch_key' => $branchKey,
            'event_name' => $eventName,
            'qr_key' => $qr,
            'source_key' => $source,
            'campaign_key' => $campaign,
            'fulfillment_mode_key' => $fulfillment,
        ];

        $inserted = DB::table('smart_ordering_analytics_hourly')->insertOrIgnore([
            ...$dimensions,
            'event_count' => 1,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            DB::table('smart_ordering_analytics_hourly')
                ->where($dimensions)
                ->update([
                    'event_count' => DB::raw('event_count + 1'),
                    'revenue' => DB::raw('revenue + '.number_format($revenue, 3, '.', '')),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array<string,string|int|float|bool|null> */
    private function safeMetadata(array $metadata): array
    {
        $allowed = [
            'placement', 'query_length', 'quantity', 'rating', 'reason_code',
            'order_reference', 'recommendation_type', 'result_count',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            if (is_string($value)) {
                $value = mb_substr(trim($value), 0, 160);
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    private function localEventDate(?int $branchId, CarbonInterface $occurredAt): string
    {
        $timezone = (string) config('app.timezone', 'UTC');
        if ($branchId !== null) {
            $branchTimezone = DB::table('smart_ordering_branches')
                ->where('id', $branchId)
                ->value('timezone');
            if (is_string($branchTimezone) && trim($branchTimezone) !== '') {
                $timezone = trim($branchTimezone);
            }
        }

        return $occurredAt->copy()->setTimezone($timezone)->toDateString();
    }

    private function localEventHour(?int $branchId, CarbonInterface $occurredAt): int
    {
        $timezone = (string) config('app.timezone', 'UTC');
        if ($branchId !== null) {
            $branchTimezone = DB::table('smart_ordering_branches')
                ->where('id', $branchId)
                ->value('timezone');
            if (is_string($branchTimezone) && trim($branchTimezone) !== '') {
                $timezone = trim($branchTimezone);
            }
        }

        return (int) $occurredAt->copy()->setTimezone($timezone)->format('G');
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function bounded(mixed $value, int $length): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $length);
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function hashValue(mixed $value, string $domain): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $secret = (string) config('app.key');
        if ($secret === '') {
            throw new \RuntimeException('Application key is required for Smart Ordering analytics hashing.');
        }

        return hash_hmac('sha256', $domain.'|'.trim($value), $secret);
    }
}
