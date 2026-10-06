<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CommercialPolicyService
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_RESTRICTED = 'RESTRICTED';

    public const STATUS_CLOSED = 'CLOSED';

    public const RESERVATION_RESERVED = 'RESERVED';

    public const RESERVATION_CONSUMED = 'CONSUMED';

    public const RESERVATION_RELEASED = 'RELEASED';

    /** @var list<string> */
    private const LIMIT_KEYS = [
        'max_per_order',
        'max_per_day',
        'max_per_week',
        'max_per_month',
        'max_lifetime',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Canonical server-side policy decision.
     *
     * @return array{
     *   allowed:bool,
     *   status:string,
     *   hide_when_closed:bool,
     *   reason_codes:list<string>,
     *   limits:array<string,float|null>,
     *   usage:array<string,float>,
     *   business_timezone:string
     * }
     */
    public function evaluate(
        int $productId,
        ?int $customerId,
        string $channel,
        float $baseQuantity,
        ?DateTimeInterface $at = null,
    ): array {
        if ($baseQuantity <= 0) {
            throw new InvalidArgumentException('Commercial quantity must be greater than zero.');
        }

        $channel = strtolower(trim($channel));
        if ($channel === '') {
            throw new InvalidArgumentException('Commercial channel is required.');
        }

        $product = DB::table('products')->where('id', $productId)->first(['id', 'is_active']);
        if ($product === null) {
            throw new InvalidArgumentException('Unknown product.');
        }

        $policy = DB::table('product_commercial_policies')->where('product_id', $productId)->first();
        $status = strtoupper((string) ($policy->status ?? self::STATUS_OPEN));
        $timezone = (string) ($policy->business_timezone ?? 'UTC');
        $now = $this->time($at, $timezone);

        $limits = [
            'max_per_order' => $this->number($policy->default_max_per_order ?? null),
            'max_per_day' => $this->number($policy->default_max_per_day ?? null),
            'max_per_week' => $this->number($policy->default_max_per_week ?? null),
            'max_per_month' => $this->number($policy->default_max_per_month ?? null),
            'max_lifetime' => $this->number($policy->default_max_lifetime ?? null),
        ];

        $allowed = (bool) $product->is_active && $status === self::STATUS_OPEN;
        $channelAllowed = $this->policyChannelAllowed($policy->channels ?? null, $channel);
        if ($channelAllowed !== null) {
            $allowed = $allowed && $channelAllowed;
        }

        $resolvedAllowed = null;
        foreach ($this->rulesInPrecedence($productId, $customerId, $channel) as $rule) {
            foreach (self::LIMIT_KEYS as $key) {
                $value = $this->number($rule->{$key} ?? null);
                if ($value !== null) {
                    $limits[$key] = $value;
                }
            }

            if ($rule->is_allowed !== null) {
                $resolvedAllowed = (bool) $rule->is_allowed;
            }
        }

        if ($status === self::STATUS_RESTRICTED) {
            $allowed = (bool) $product->is_active
                && $channelAllowed !== false
                && $resolvedAllowed === true;
        } elseif ($status === self::STATUS_CLOSED) {
            $allowed = false;
        } elseif ($resolvedAllowed !== null) {
            $allowed = $allowed && $resolvedAllowed;
        }

        $reasonCodes = [];
        if (! (bool) $product->is_active) {
            $reasonCodes[] = 'PRODUCT_INACTIVE';
        }
        if ($status === self::STATUS_CLOSED) {
            $reasonCodes[] = 'PRODUCT_CLOSED';
        } elseif ($status === self::STATUS_RESTRICTED && $resolvedAllowed !== true) {
            $reasonCodes[] = 'PRODUCT_RESTRICTED';
        }
        if ($channelAllowed === false) {
            $reasonCodes[] = 'CHANNEL_BLOCKED';
        }
        if (! $this->insideAvailabilityWindow($productId, $now)) {
            $allowed = false;
            $reasonCodes[] = 'OUTSIDE_AVAILABILITY';
        }

        $usage = $customerId === null
            ? ['day' => 0.0, 'week' => 0.0, 'month' => 0.0, 'lifetime' => 0.0]
            : $this->quotaUsage(
                $productId,
                $customerId,
                $now,
                (int) ($policy->week_starts_on ?? 1),
            );

        $quotaReasons = $this->quotaReasons($baseQuantity, $limits, $usage);
        if ($quotaReasons !== []) {
            $allowed = false;
            array_push($reasonCodes, ...$quotaReasons);
        }

        return [
            'allowed' => $allowed,
            'status' => $status,
            'hide_when_closed' => (bool) ($policy->hide_when_closed ?? false),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'limits' => $limits,
            'usage' => $usage,
            'business_timezone' => $timezone,
        ];
    }

    /**
     * Resolve a selling unit to base inventory quantity and immutable order-line snapshot fields.
     *
     * @return array{
     *   selling_unit_id:int,
     *   code:string,
     *   name:string,
     *   selling_quantity:float,
     *   conversion_factor:float,
     *   base_quantity:float,
     *   price:float|null,
     *   sku:string|null,
     *   barcode:string|null
     * }
     */
    public function sellingUnit(
        int $productId,
        string $code,
        float $sellingQuantity,
    ): array {
        if ($sellingQuantity <= 0) {
            throw new InvalidArgumentException('Selling quantity must be greater than zero.');
        }

        $unit = DB::table('product_selling_units')
            ->where('product_id', $productId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($unit === null) {
            $unit = DB::table('products')
                ->join('units', 'units.id', '=', 'products.unit_id')
                ->where('products.id', $productId)
                ->where('units.code', $code)
                ->first([
                    'units.id as unit_id',
                    'units.code',
                    'units.name',
                    'products.sku',
                ]);

            if ($unit === null) {
                throw new InvalidArgumentException('Selling unit is not available for this product.');
            }

            $unit->id = 0;
            $unit->conversion_factor = 1;
            $unit->price = null;
            $unit->barcode = null;
        }

        $factor = (float) $unit->conversion_factor;
        if ($factor <= 0) {
            throw new RuntimeException('Selling unit conversion factor must be greater than zero.');
        }

        return [
            'selling_unit_id' => (int) $unit->id,
            'code' => (string) $unit->code,
            'name' => (string) $unit->name,
            'selling_quantity' => round($sellingQuantity, 3),
            'conversion_factor' => round($factor, 3),
            'base_quantity' => round($sellingQuantity * $factor, 3),
            'price' => $this->number($unit->price),
            'sku' => $unit->sku === null ? null : (string) $unit->sku,
            'barcode' => $unit->barcode === null ? null : (string) $unit->barcode,
        ];
    }

    /**
     * Stable selling-unit contract for Customer/Van/Dashboard clients.
     *
     * @return list<array{code:string,name:string,conversion_factor:float,price:float|null,sku:string|null,barcode:string|null,is_base:bool}>
     */
    public function sellingUnits(int $productId): array
    {
        $units = DB::table('product_selling_units')
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            $base = DB::table('products')
                ->join('units', 'units.id', '=', 'products.unit_id')
                ->where('products.id', $productId)
                ->first([
                    'units.code',
                    'units.name',
                    'products.sku',
                ]);

            if ($base === null) {
                return [];
            }

            return [[
                'code' => (string) $base->code,
                'name' => (string) $base->name,
                'conversion_factor' => 1.0,
                'price' => null,
                'sku' => $base->sku === null ? null : (string) $base->sku,
                'barcode' => null,
                'is_base' => true,
            ]];
        }

        return $units
            ->map(fn (object $unit): array => [
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'conversion_factor' => (float) $unit->conversion_factor,
                'price' => $this->number($unit->price),
                'sku' => $unit->sku === null ? null : (string) $unit->sku,
                'barcode' => $unit->barcode === null ? null : (string) $unit->barcode,
                'is_base' => (bool) $unit->is_base,
            ])
            ->values()
            ->all();
    }

    /**
     * Atomic authoritative quota reservation at order confirmation.
     *
     * @return array{reservation_token:string,base_quantity:float,decision:array<string,mixed>,selling_unit:array<string,mixed>}
     */
    public function reserveForOrder(
        int $orderId,
        int $customerId,
        int $productId,
        string $sellingUnitCode,
        float $sellingQuantity,
        string $channel,
        ?DateTimeInterface $at = null,
    ): array {
        $unit = $this->sellingUnit($productId, $sellingUnitCode, $sellingQuantity);
        $reservation = $this->reserveBaseQuantityForOrder(
            $orderId,
            $customerId,
            $productId,
            (float) $unit['base_quantity'],
            $channel,
            $at,
        );

        return [
            ...$reservation,
            'selling_unit' => $unit,
        ];
    }

    /**
     * Atomic authoritative quota reservation when the caller already holds a base-unit quantity.
     *
     * @return array{reservation_token:string,base_quantity:float,decision:array<string,mixed>}
     */
    public function reserveBaseQuantityForOrder(
        int $orderId,
        int $customerId,
        int $productId,
        float $baseQuantity,
        string $channel,
        ?DateTimeInterface $at = null,
    ): array {
        if ($baseQuantity <= 0) {
            throw new InvalidArgumentException('Commercial base quantity must be greater than zero.');
        }

        return DB::transaction(function () use (
            $orderId,
            $customerId,
            $productId,
            $baseQuantity,
            $channel,
            $at,
        ): array {
            DB::table('commercial_quota_locks')->insertOrIgnore([
                'product_id' => $productId,
                'customer_id' => $customerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('commercial_quota_locks')
                ->where('product_id', $productId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            $existing = DB::table('commercial_quota_reservations')
                ->where('order_id', $orderId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status !== self::RESERVATION_RELEASED) {
                return [
                    'reservation_token' => (string) $existing->reservation_token,
                    'base_quantity' => (float) $existing->base_quantity,
                    'decision' => [
                        'allowed' => true,
                        'status' => 'IDEMPOTENT_REPLAY',
                        'reason_codes' => [],
                    ],
                ];
            }

            $decision = $this->evaluate(
                $productId,
                $customerId,
                $channel,
                $baseQuantity,
                $at,
            );

            if (! $decision['allowed']) {
                throw new DomainException(implode(',', $decision['reason_codes']));
            }

            $reservedAt = $this->time($at, 'UTC');
            $token = (string) Str::uuid();

            if ($existing === null) {
                DB::table('commercial_quota_reservations')->insert([
                    'reservation_token' => $token,
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'customer_id' => $customerId,
                    'base_quantity' => $baseQuantity,
                    'status' => self::RESERVATION_RESERVED,
                    'reserved_at' => $reservedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('commercial_quota_reservations')
                    ->where('id', $existing->id)
                    ->update([
                        'reservation_token' => $token,
                        'customer_id' => $customerId,
                        'base_quantity' => $baseQuantity,
                        'status' => self::RESERVATION_RESERVED,
                        'reserved_at' => $reservedAt,
                        'consumed_at' => null,
                        'released_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            return [
                'reservation_token' => $token,
                'base_quantity' => $baseQuantity,
                'decision' => $decision,
            ];
        }, 3);
    }

    public function consumeReservation(string $reservationToken): void
    {
        DB::transaction(function () use ($reservationToken): void {
            $reservation = DB::table('commercial_quota_reservations')
                ->where('reservation_token', $reservationToken)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw new InvalidArgumentException('Unknown quota reservation.');
            }

            if ($reservation->status === self::RESERVATION_CONSUMED) {
                return;
            }

            if ($reservation->status === self::RESERVATION_RELEASED) {
                throw new DomainException('Released quota reservation cannot be consumed.');
            }

            DB::table('commercial_quota_reservations')
                ->where('id', $reservation->id)
                ->update([
                    'status' => self::RESERVATION_CONSUMED,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    public function releaseOrderReservations(int $orderId): int
    {
        return DB::table('commercial_quota_reservations')
            ->where('order_id', $orderId)
            ->whereIn('status', [self::RESERVATION_RESERVED, self::RESERVATION_CONSUMED])
            ->update([
                'status' => self::RESERVATION_RELEASED,
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Write selling-unit facts into an order line so later configuration changes cannot alter history.
     *
     * @param array<string,mixed> $sellingUnit
     */
    public function snapshotOrderItem(int $orderItemId, array $sellingUnit): void
    {
        DB::table('order_items')->where('id', $orderItemId)->update([
            'selling_unit_code_snapshot' => $sellingUnit['code'] ?? null,
            'selling_unit_name_snapshot' => $sellingUnit['name'] ?? null,
            'selling_unit_quantity' => $sellingUnit['selling_quantity'] ?? null,
            'base_quantity' => $sellingUnit['base_quantity'] ?? null,
            'conversion_factor_snapshot' => $sellingUnit['conversion_factor'] ?? null,
            'selling_unit_sku_snapshot' => $sellingUnit['sku'] ?? null,
            'selling_unit_barcode_snapshot' => $sellingUnit['barcode'] ?? null,
            'updated_at' => now(),
        ]);
    }

    public function recordOverride(
        int $productId,
        Authenticatable $actor,
        string $reason,
        array $context = [],
    ): void {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Commercial override reason is required.');
        }

        $policy = DB::table('product_commercial_policies')->where('product_id', $productId)->first();
        if (! (bool) ($policy->override_allowed ?? false)) {
            throw new DomainException('Commercial override is not allowed for this product.');
        }

        $this->audit->record(
            'commercial_policy.override',
            $actor,
            null,
            null,
            [
                'product_id' => $productId,
                'reason' => $reason,
                'context' => $context,
            ],
        );
    }

    /** @return list<object> */
    private function rulesInPrecedence(int $productId, ?int $customerId, string $channel): array
    {
        $result = [];

        $defaults = DB::table('product_commercial_rules')
            ->where('product_id', $productId)
            ->whereNull('customer_id')
            ->whereNull('customer_group_id')
            ->where(function ($query) use ($channel): void {
                $query->whereNull('channel')->orWhere('channel', $channel);
            })
            ->get();
        $this->appendRuleVariants($result, $defaults->all(), $channel);

        if ($customerId === null) {
            return $result;
        }

        $groupRules = DB::table('product_commercial_rules as rules')
            ->join(
                'commercial_customer_group_members as members',
                'members.customer_group_id',
                '=',
                'rules.customer_group_id',
            )
            ->join(
                'commercial_customer_groups as groups',
                'groups.id',
                '=',
                'rules.customer_group_id',
            )
            ->where('rules.product_id', $productId)
            ->where('members.customer_id', $customerId)
            ->where('groups.is_active', true)
            ->whereNull('rules.customer_id')
            ->where(function ($query) use ($channel): void {
                $query->whereNull('rules.channel')->orWhere('rules.channel', $channel);
            })
            ->orderBy('groups.priority')
            ->get(['rules.*', 'groups.priority as group_priority']);

        foreach ($groupRules->groupBy('customer_group_id') as $rules) {
            $this->appendRuleVariants($result, $rules->all(), $channel);
        }

        $customerRules = DB::table('product_commercial_rules')
            ->where('product_id', $productId)
            ->where('customer_id', $customerId)
            ->whereNull('customer_group_id')
            ->where(function ($query) use ($channel): void {
                $query->whereNull('channel')->orWhere('channel', $channel);
            })
            ->get();
        $this->appendRuleVariants($result, $customerRules->all(), $channel);

        return $result;
    }

    /**
     * @param list<object> $result
     * @param list<object> $variants
     */
    private function appendRuleVariants(array &$result, array $variants, string $channel): void
    {
        foreach ($variants as $rule) {
            if ($rule->channel === null) {
                $result[] = $rule;
            }
        }
        foreach ($variants as $rule) {
            if ($rule->channel === $channel) {
                $result[] = $rule;
            }
        }
    }

    private function policyChannelAllowed(mixed $value, string $channel): ?bool
    {
        if ($value === null) {
            return null;
        }

        $channels = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($channels) || ! array_key_exists($channel, $channels)) {
            return null;
        }

        return (bool) $channels[$channel];
    }

    private function insideAvailabilityWindow(int $productId, CarbonImmutable $at): bool
    {
        $windows = DB::table('product_availability_windows')
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->get();

        if ($windows->isEmpty()) {
            return true;
        }

        foreach ($windows as $window) {
            $recurrence = strtolower((string) $window->recurrence);
            if ($recurrence === 'yearly') {
                if ($this->insideYearlyWindow($window, $at)) {
                    return true;
                }

                continue;
            }

            $startsAt = $window->starts_at === null
                ? null
                : CarbonImmutable::parse((string) $window->starts_at, 'UTC')->setTimezone($at->getTimezone());
            $endsAt = $window->ends_at === null
                ? null
                : CarbonImmutable::parse((string) $window->ends_at, 'UTC')->setTimezone($at->getTimezone());

            if (($startsAt === null || $at->greaterThanOrEqualTo($startsAt))
                && ($endsAt === null || $at->lessThanOrEqualTo($endsAt))) {
                return true;
            }
        }

        return false;
    }

    private function insideYearlyWindow(object $window, CarbonImmutable $at): bool
    {
        foreach (['start_month', 'start_day', 'end_month', 'end_day'] as $field) {
            if ($window->{$field} === null) {
                return false;
            }
        }

        $current = ((int) $at->format('m') * 100) + (int) $at->format('d');
        $start = ((int) $window->start_month * 100) + (int) $window->start_day;
        $end = ((int) $window->end_month * 100) + (int) $window->end_day;

        return $start <= $end
            ? $current >= $start && $current <= $end
            : $current >= $start || $current <= $end;
    }

    /**
     * @return array{day:float,week:float,month:float,lifetime:float}
     */
    private function quotaUsage(
        int $productId,
        int $customerId,
        CarbonImmutable $at,
        int $weekStartsOn,
    ): array {
        $base = DB::table('commercial_quota_reservations')
            ->where('product_id', $productId)
            ->where('customer_id', $customerId)
            ->whereIn('status', [self::RESERVATION_RESERVED, self::RESERVATION_CONSUMED]);

        $utcAt = $at->setTimezone('UTC');
        $dayStart = $at->startOfDay()->setTimezone('UTC');
        $monthStart = $at->startOfMonth()->setTimezone('UTC');
        $carbonWeekStart = max(1, min(7, $weekStartsOn)) % 7;
        $weekStart = $at->startOfWeek($carbonWeekStart)->setTimezone('UTC');

        return [
            'day' => $this->sumUsage(clone $base, $dayStart, $utcAt),
            'week' => $this->sumUsage(clone $base, $weekStart, $utcAt),
            'month' => $this->sumUsage(clone $base, $monthStart, $utcAt),
            'lifetime' => round((float) (clone $base)->sum('base_quantity'), 3),
        ];
    }

    private function sumUsage(mixed $query, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) $query->whereBetween('reserved_at', [$from, $to])->sum('base_quantity'), 3);
    }

    /**
     * @param array<string,float|null> $limits
     * @param array<string,float> $usage
     * @return list<string>
     */
    private function quotaReasons(float $quantity, array $limits, array $usage): array
    {
        $reasons = [];

        if ($limits['max_per_order'] !== null && $quantity > $limits['max_per_order']) {
            $reasons[] = 'MAX_PER_ORDER_EXCEEDED';
        }
        if ($limits['max_per_day'] !== null && $usage['day'] + $quantity > $limits['max_per_day']) {
            $reasons[] = 'MAX_PER_DAY_EXCEEDED';
        }
        if ($limits['max_per_week'] !== null && $usage['week'] + $quantity > $limits['max_per_week']) {
            $reasons[] = 'MAX_PER_WEEK_EXCEEDED';
        }
        if ($limits['max_per_month'] !== null && $usage['month'] + $quantity > $limits['max_per_month']) {
            $reasons[] = 'MAX_PER_MONTH_EXCEEDED';
        }
        if ($limits['max_lifetime'] !== null && $usage['lifetime'] + $quantity > $limits['max_lifetime']) {
            $reasons[] = 'MAX_LIFETIME_EXCEEDED';
        }

        return $reasons;
    }

    private function time(?DateTimeInterface $at, string $timezone): CarbonImmutable
    {
        $time = $at === null ? CarbonImmutable::now('UTC') : CarbonImmutable::instance($at);

        return $time->setTimezone($timezone);
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
