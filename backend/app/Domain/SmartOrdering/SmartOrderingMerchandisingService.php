<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Order;
use App\Models\SmartOrderingCart;
use App\Services\CommerceQuoteService;
use App\Services\SmartOrderingAttributionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingMerchandisingService
{
    public const PLACEMENTS = [
        'hero_offer',
        'category_offer',
        'product_detail_add_on',
        'cart_upsell',
        'checkout_threshold',
        'order_again',
    ];

    public const PUBLIC_EVENTS = ['impression', 'click', 'add'];

    public function __construct(
        private readonly CommerceQuoteService $quotes,
        private readonly SmartOrderingAttributionService $attribution,
        private readonly RestaurantCustomerService $customers,
        private readonly SmartOrderingAvailabilityService $availability,
    ) {}

    /** @return array<string,mixed>|null */
    public function verifiedAttribution(?string $signedContext, SmartOrderingBranch $branch): ?array
    {
        if (! is_string($signedContext) || trim($signedContext) === '') {
            return null;
        }

        $context = $this->attribution->serverContext(trim($signedContext), (int) $branch->store_id);
        $branchReference = trim((string) ($context['branch_reference'] ?? ''));

        if ($branchReference !== '' && ! in_array($branchReference, [
            (string) $branch->public_id,
            (string) $branch->code,
        ], true)) {
            throw ValidationException::withMessages([
                'attribution_context' => ['QR/campaign context does not belong to this branch.'],
            ]);
        }

        return [
            'qr_public_id' => (string) $context['qr_public_id'],
            'type' => (string) $context['type'],
            'campaign' => $context['campaign'] === null ? null : (string) $context['campaign'],
            'promotion_id' => $context['promotion_id'] === null ? null : (int) $context['promotion_id'],
        ];
    }

    /**
     * Apply deterministic Smart Ordering offers to a server-authoritative quote.
     *
     * @param list<array{product_id:int,quantity:float}> $lines
     * @param array<string,mixed> $quote
     * @param array<string,mixed>|null $trustedContext
     * @return array{quote:array<string,mixed>,applied:list<array<string,mixed>>,audit:list<array<string,mixed>>}
     */
    public function decorateQuote(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        array $lines,
        array $quote,
        ?int $customerId = null,
        ?array $trustedContext = null,
        float $deliveryTotal = 0.0,
        bool $lockUsage = false,
    ): array {
        $subtotal = round(max(0.0, (float) ($quote['subtotal'] ?? 0)), 3);
        $deliveryTotal = round(max(0.0, $deliveryTotal), 3);
        $couponDiscount = round(max(0.0, (float) ($quote['coupon_discount_total'] ?? 0)), 3);
        $couponIsDelivery = strtolower((string) data_get($quote, 'coupon.discount_type', '')) === 'free_shipping';

        $merchandiseDiscount = $couponIsDelivery ? 0.0 : min($subtotal, $couponDiscount);
        $deliveryDiscount = $couponIsDelivery ? min($deliveryTotal, $couponDiscount) : 0.0;
        $promotionDiscount = 0.0;
        $applied = [];
        $audit = [];
        $usedGroups = [];

        $rules = $this->activeOfferRules($site, $branch, $lockUsage);
        $linkedPromotionIds = DB::table('smart_ordering_offer_rules')
            ->where('site_id', $site->id)
            ->whereNotNull('promotion_id')
            ->pluck('promotion_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $generic = DB::table('promotions')
            ->where('store_id', $site->store_id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when($linkedPromotionIds !== [], fn ($query) => $query->whereNotIn('id', $linkedPromotionIds))
            ->orderBy('id')
            ->get(['id']);

        $bestLegacy = null;
        foreach ($generic as $promotion) {
            [$discount, $payload] = $this->quotes->quotePromotionById(
                (int) $site->store_id,
                $subtotal,
                $deliveryTotal,
                (int) $promotion->id,
            );
            if ($payload === null) {
                continue;
            }

            $isDelivery = strtolower((string) ($payload['type'] ?? '')) === 'free_shipping';
            $score = $isDelivery ? min($deliveryTotal, $discount) : min($subtotal, $discount);
            if ($bestLegacy === null || $score > $bestLegacy['score']) {
                $bestLegacy = [
                    'score' => $score,
                    'discount' => $discount,
                    'payload' => $payload,
                    'delivery' => $isDelivery,
                ];
            }
        }

        if ($bestLegacy !== null && $bestLegacy['score'] > 0) {
            if ($bestLegacy['delivery']) {
                $deliveryDiscount += min(
                    max(0.0, $deliveryTotal - $deliveryDiscount),
                    (float) $bestLegacy['discount'],
                );
            } else {
                $added = min(
                    max(0.0, $subtotal - $merchandiseDiscount),
                    (float) $bestLegacy['discount'],
                );
                $merchandiseDiscount += $added;
                $promotionDiscount += $added;
            }
            $quote['promotion'] = $bestLegacy['payload'];
            $usedGroups['legacy_promotion'] = true;
        }

        $profile = null;
        foreach ($rules as $rule) {
            $conditions = $this->json($rule->conditions);
            if ($profile === null && $customerId !== null && (array) ($conditions['segments'] ?? []) !== []) {
                $profile = $this->customers->profile($site, $customerId);
            }

            [$eligible, $reason] = $this->eligible(
                $rule,
                $lines,
                $subtotal,
                $customerId,
                $trustedContext,
                $profile,
                $branch,
            );

            if (! $eligible) {
                $audit[] = [
                    'offer_id' => (string) $rule->public_id,
                    'eligible' => false,
                    'reason' => $reason,
                ];
                continue;
            }

            $group = trim((string) ($rule->stack_group ?? ''));
            if ((bool) $rule->exclusive && $group !== '' && isset($usedGroups[$group])) {
                $audit[] = [
                    'offer_id' => (string) $rule->public_id,
                    'eligible' => true,
                    'reason' => 'PRECEDENCE_SUPPRESSED',
                ];
                continue;
            }

            [$itemDiscount, $offerDeliveryDiscount, $promotion] = $this->effect(
                $rule,
                $quote,
                $subtotal,
                $deliveryTotal,
            );

            $itemDiscount = min(
                max(0.0, $subtotal - $merchandiseDiscount),
                max(0.0, round($itemDiscount, 3)),
            );
            $offerDeliveryDiscount = min(
                max(0.0, $deliveryTotal - $deliveryDiscount),
                max(0.0, round($offerDeliveryDiscount, 3)),
            );

            if ($itemDiscount <= 0 && $offerDeliveryDiscount <= 0) {
                $audit[] = [
                    'offer_id' => (string) $rule->public_id,
                    'eligible' => true,
                    'reason' => 'NO_DISCOUNT_TRIGGERED',
                ];
                continue;
            }

            $merchandiseDiscount += $itemDiscount;
            $deliveryDiscount += $offerDeliveryDiscount;
            $promotionDiscount += $itemDiscount + $offerDeliveryDiscount;

            if ((bool) $rule->exclusive && $group !== '') {
                $usedGroups[$group] = true;
            }
            if ($promotion !== null) {
                $quote['promotion'] = $promotion;
            }

            $applied[] = [
                'rule_id' => (int) $rule->id,
                'id' => (string) $rule->public_id,
                'name' => (string) $rule->name,
                'type' => (string) $rule->type,
                'priority' => (int) $rule->priority,
                'discount_total' => $itemDiscount,
                'delivery_discount_total' => $offerDeliveryDiscount,
            ];
            $audit[] = [
                'offer_id' => (string) $rule->public_id,
                'eligible' => true,
                'reason' => 'APPLIED',
            ];
        }

        $merchandiseDiscount = round(min($subtotal, $merchandiseDiscount), 3);
        $deliveryDiscount = round(min($deliveryTotal, $deliveryDiscount), 3);
        $promotionDiscount = round(max(0.0, $merchandiseDiscount - ($couponIsDelivery ? 0.0 : $couponDiscount))
            + max(0.0, $deliveryDiscount - ($couponIsDelivery ? $couponDiscount : 0.0)), 3);

        $quote['promotion_discount_total'] = $promotionDiscount;
        $quote['discount_total'] = $merchandiseDiscount;
        $quote['delivery_total'] = $deliveryTotal;
        $quote['delivery_discount_total'] = $deliveryDiscount;
        $taxRate = max(0.0, (float) ($quote['tax_rate'] ?? 0));
        $taxable = max(0.0, $subtotal - $merchandiseDiscount)
            + max(0.0, $deliveryTotal - $deliveryDiscount);
        $quote['tax_total'] = round($taxable * ($taxRate / 100), 3);
        $quote['grand_total'] = round($taxable + (float) $quote['tax_total'], 3);
        $quote['merchandising'] = [
            'applied_offers' => array_map(
                static fn (array $offer): array => [
                    'id' => $offer['id'],
                    'name' => $offer['name'],
                    'type' => $offer['type'],
                    'discount_total' => $offer['discount_total'],
                    'delivery_discount_total' => $offer['delivery_discount_total'],
                ],
                $applied,
            ),
            'audit' => $audit,
            'pricing_source' => 'smart_ordering_merchandising_v1',
        ];

        return ['quote' => $quote, 'applied' => $applied, 'audit' => $audit];
    }

    /**
     * @return array{offers:list<array<string,mixed>>,recommendations:list<array<string,mixed>>}
     */
    public function placement(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        SmartOrderingCart $cart,
        array $quote,
        string $placement,
        ?int $customerId = null,
        ?int $triggerProductId = null,
    ): array {
        abort_unless(in_array($placement, self::PLACEMENTS, true), 422);

        $subtotal = round((float) ($quote['subtotal'] ?? 0), 3);
        $context = is_array($cart->merchandising_context) ? $cart->merchandising_context : null;
        $lines = DB::table('smart_ordering_cart_items')
            ->where('cart_id', $cart->id)
            ->get(['product_id', 'quantity'])
            ->map(static fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'quantity' => (float) $row->quantity,
            ])->all();

        $profile = $customerId === null ? null : $this->customers->profile($site, $customerId);
        $offers = [];
        foreach ($this->activeOfferRules($site, $branch, false) as $rule) {
            $placements = $this->json($rule->placements);
            if ($placements !== [] && ! in_array($placement, array_values($placements), true)) {
                continue;
            }
            [$eligible, $reason] = $this->eligible(
                $rule,
                $lines,
                $subtotal,
                $customerId,
                $context,
                $profile,
                $branch,
                true,
            );
            if (! $eligible && $reason !== 'MINIMUM_BASKET') {
                continue;
            }

            $minimum = $rule->minimum_basket === null ? null : (float) $rule->minimum_basket;
            $couponCode = $rule->marketing_coupon_id === null
                ? null
                : DB::table('marketing_coupons')->where('id', $rule->marketing_coupon_id)->value('code');

            $offers[] = [
                'id' => (string) $rule->public_id,
                'name' => (string) $rule->name,
                'type' => (string) $rule->type,
                'eligible' => $eligible,
                'minimum_basket' => $minimum,
                'remaining_to_unlock' => $minimum === null ? 0.0 : round(max(0.0, $minimum - $subtotal), 3),
                'coupon_code' => $couponCode === null ? null : (string) $couponCode,
            ];
        }

        return [
            'offers' => $offers,
            'recommendations' => $this->recommendations(
                $site,
                $branch,
                $placement,
                $subtotal,
                $profile,
                $triggerProductId,
            ),
        ];
    }

    /** @param array<string,mixed> $metadata */
    public function trackPublicEvent(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        SmartOrderingCart $cart,
        string $eventType,
        string $placement,
        ?string $offerPublicId,
        ?string $recommendationPublicId,
        string $eventKey,
        array $metadata = [],
        ?int $customerId = null,
    ): void {
        abort_unless(in_array($eventType, self::PUBLIC_EVENTS, true), 422);
        abort_unless(in_array($placement, self::PLACEMENTS, true), 422);

        $offerId = null;
        if ($offerPublicId !== null) {
            $offerId = DB::table('smart_ordering_offer_rules')
                ->where('site_id', $site->id)
                ->where('public_id', $offerPublicId)
                ->value('id');
            abort_unless($offerId !== null, 404);
        }

        $recommendationId = null;
        if ($recommendationPublicId !== null) {
            $recommendationId = DB::table('smart_ordering_recommendation_rules')
                ->where('site_id', $site->id)
                ->where('public_id', $recommendationPublicId)
                ->value('id');
            abort_unless($recommendationId !== null, 404);
        }

        abort_if($offerId === null && $recommendationId === null, 422, 'Offer or recommendation is required.');

        DB::table('smart_ordering_merchandising_events')->insertOrIgnore([
            'site_id' => $site->id,
            'branch_id' => $branch->id,
            'offer_rule_id' => $offerId,
            'recommendation_rule_id' => $recommendationId,
            'customer_id' => $customerId,
            'cart_public_id' => (string) $cart->public_id,
            'event_type' => $eventType,
            'placement' => $placement,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'event_key' => hash('sha256', $site->id.'|'.$eventKey),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string,mixed>> $applied */
    public function commitRedemptions(
        SmartOrderingSite $site,
        Order $order,
        array $applied,
        ?int $customerId,
        ?string $cartPublicId,
    ): void {
        foreach ($applied as $offer) {
            $rule = DB::table('smart_ordering_offer_rules')
                ->where('site_id', $site->id)
                ->where('id', (int) $offer['rule_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($rule !== null && $rule->is_active, 409, 'Offer is no longer active.');

            if ($rule->usage_limit_total !== null && (int) $rule->used_count >= (int) $rule->usage_limit_total) {
                throw ValidationException::withMessages(['offer' => ['Offer usage limit has been reached.']]);
            }

            $inserted = DB::table('smart_ordering_offer_redemptions')->insertOrIgnore([
                'offer_rule_id' => (int) $rule->id,
                'customer_id' => $customerId,
                'order_id' => $order->id,
                'discount_total' => (float) $offer['discount_total'],
                'delivery_discount_total' => (float) $offer['delivery_discount_total'],
                'redeemed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                DB::table('smart_ordering_offer_rules')->where('id', $rule->id)->increment('used_count');
            }

            DB::table('smart_ordering_merchandising_events')->insertOrIgnore([
                'site_id' => $site->id,
                'branch_id' => null,
                'offer_rule_id' => (int) $rule->id,
                'customer_id' => $customerId,
                'order_id' => $order->id,
                'cart_public_id' => $cartPublicId,
                'event_type' => 'conversion',
                'placement' => null,
                'revenue' => (float) $order->grand_total,
                'event_key' => hash('sha256', 'offer|'.$rule->id.'|order|'.$order->id),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($cartPublicId === null) {
            return;
        }

        $recommendationIds = DB::table('smart_ordering_merchandising_events')
            ->where('site_id', $site->id)
            ->where('cart_public_id', $cartPublicId)
            ->where('event_type', 'add')
            ->whereNotNull('recommendation_rule_id')
            ->distinct()
            ->pluck('recommendation_rule_id');

        foreach ($recommendationIds as $recommendationId) {
            DB::table('smart_ordering_merchandising_events')->insertOrIgnore([
                'site_id' => $site->id,
                'branch_id' => null,
                'recommendation_rule_id' => (int) $recommendationId,
                'customer_id' => $customerId,
                'order_id' => $order->id,
                'cart_public_id' => $cartPublicId,
                'event_type' => 'conversion',
                'placement' => null,
                'revenue' => (float) $order->grand_total,
                'event_key' => hash('sha256', 'recommendation|'.$recommendationId.'|order|'.$order->id),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function activeOfferRules(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        bool $lockUsage,
    ) {
        $query = DB::table('smart_ordering_offer_rules')
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('priority')
            ->orderBy('id');

        if ($lockUsage) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param list<array{product_id:int,quantity:float}> $lines
     * @param array<string,mixed>|null $trustedContext
     * @param array<string,mixed>|null $profile
     * @return array{0:bool,1:string}
     */
    private function eligible(
        object $rule,
        array $lines,
        float $subtotal,
        ?int $customerId,
        ?array $trustedContext,
        ?array $profile,
        SmartOrderingBranch $branch,
        bool $allowThresholdPreview = false,
    ): array {
        if ($rule->usage_limit_total !== null && (int) $rule->used_count >= (int) $rule->usage_limit_total) {
            return [false, 'USAGE_LIMIT'];
        }
        if ($rule->minimum_basket !== null && $subtotal + 0.0001 < (float) $rule->minimum_basket) {
            return [false, 'MINIMUM_BASKET'];
        }

        $conditions = $this->json($rule->conditions);
        $campaigns = array_values(array_filter((array) ($conditions['campaigns'] ?? []), 'is_string'));
        if ($campaigns !== [] && ! in_array((string) ($trustedContext['campaign'] ?? ''), $campaigns, true)) {
            return [false, 'CAMPAIGN'];
        }
        $qrTypes = array_values(array_filter((array) ($conditions['qr_types'] ?? []), 'is_string'));
        if ($qrTypes !== [] && ! in_array((string) ($trustedContext['type'] ?? ''), $qrTypes, true)) {
            return [false, 'QR_TYPE'];
        }

        $segments = array_map('strtoupper', array_values(array_filter((array) ($conditions['segments'] ?? []), 'is_string')));
        if ($segments !== []) {
            if ($customerId === null || $profile === null) {
                return [false, 'CUSTOMER_SEGMENT'];
            }
            if (array_intersect($segments, array_map('strtoupper', (array) ($profile['tags'] ?? []))) === []) {
                return [false, 'CUSTOMER_SEGMENT'];
            }
        }

        $customerIds = array_map('intval', (array) ($conditions['customer_ids'] ?? []));
        if ($customerIds !== [] && ($customerId === null || ! in_array($customerId, $customerIds, true))) {
            return [false, 'CUSTOMER'];
        }

        $branchIds = array_values(array_filter((array) ($conditions['branch_public_ids'] ?? []), 'is_string'));
        if ($branchIds !== [] && ! in_array((string) $branch->public_id, $branchIds, true)) {
            return [false, 'BRANCH'];
        }

        $quantities = [];
        foreach ($lines as $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $quantities[$id] = round(($quantities[$id] ?? 0) + (float) ($line['quantity'] ?? 0), 3);
        }
        foreach ((array) ($conditions['required_products'] ?? []) as $required) {
            if (! is_array($required)) {
                continue;
            }
            $id = (int) ($required['product_id'] ?? 0);
            $quantity = max(0.001, (float) ($required['quantity'] ?? 1));
            if (($quantities[$id] ?? 0) + 0.0001 < $quantity) {
                return [false, 'REQUIRED_PRODUCT'];
            }
        }

        $days = array_map('intval', (array) ($conditions['days'] ?? []));
        if ($days !== []) {
            $now = now((string) $branch->timezone);
            if (! in_array($now->dayOfWeekIso, $days, true)) {
                return [false, 'DAYPART'];
            }
            $start = (string) ($conditions['time_start'] ?? '');
            $end = (string) ($conditions['time_end'] ?? '');
            if ($start !== '' && $end !== '') {
                $clock = $now->format('H:i');
                if ($start <= $end) {
                    if ($clock < $start || $clock >= $end) {
                        return [false, 'DAYPART'];
                    }
                } elseif ($clock < $start && $clock >= $end) {
                    return [false, 'DAYPART'];
                }
            }
        }

        return [true, 'ELIGIBLE'];
    }

    /** @return array{0:float,1:float,2:array<string,mixed>|null} */
    private function effect(object $rule, array $quote, float $subtotal, float $deliveryTotal): array
    {
        $type = strtolower(trim((string) $rule->type));
        $effect = $this->json($rule->effect);

        if ($rule->promotion_id !== null || $type === 'promotion') {
            abort_if($rule->promotion_id === null, 422, 'Promotion offer requires a promotion.');
            [$discount, $promotion] = $this->quotes->quotePromotionById(
                (int) DB::table('smart_ordering_sites')->where('id', $rule->site_id)->value('store_id'),
                $subtotal,
                $deliveryTotal,
                (int) $rule->promotion_id,
            );
            $delivery = strtolower((string) ($promotion['type'] ?? '')) === 'free_shipping';

            return [$delivery ? 0.0 : $discount, $delivery ? $discount : 0.0, $promotion];
        }

        $items = (array) ($quote['items'] ?? []);
        $value = max(0.0, (float) ($effect['value'] ?? 0));

        return match ($type) {
            'percentage' => [round($subtotal * (min(100.0, $value) / 100), 3), 0.0, null],
            'fixed', 'fixed_discount' => [min($subtotal, $value), 0.0, null],
            'free_delivery' => [0.0, $deliveryTotal, null],
            'item_discount' => [$this->itemDiscount($items, $effect), 0.0, null],
            'buy_x_get_y' => [$this->buyXGetY($items, $effect), 0.0, null],
            'combo', 'bundle' => [$this->comboDiscount($items, $effect), 0.0, null],
            'free_item' => [$this->freeItemDiscount($items, $effect), 0.0, null],
            default => [0.0, 0.0, null],
        };
    }

    /** @param list<array<string,mixed>> $items */
    private function itemDiscount(array $items, array $effect): float
    {
        $target = (int) ($effect['product_id'] ?? 0);
        $method = strtolower((string) ($effect['method'] ?? 'percentage'));
        $value = max(0.0, (float) ($effect['value'] ?? 0));
        $discount = 0.0;

        foreach ($items as $line) {
            if ((int) ($line['product_id'] ?? 0) !== $target || ! ($line['is_available'] ?? false)) {
                continue;
            }
            $lineTotal = max(0.0, (float) ($line['line_total'] ?? 0));
            $discount += $method === 'fixed'
                ? min($lineTotal, $value * (float) ($line['quantity'] ?? 0))
                : $lineTotal * (min(100.0, $value) / 100);
        }

        return round($discount, 3);
    }

    /** @param list<array<string,mixed>> $items */
    private function buyXGetY(array $items, array $effect): float
    {
        $xProduct = (int) ($effect['buy_product_id'] ?? 0);
        $yProduct = (int) ($effect['get_product_id'] ?? 0);
        $x = max(0.001, (float) ($effect['buy_quantity'] ?? 1));
        $y = max(0.001, (float) ($effect['get_quantity'] ?? 1));
        $percent = min(100.0, max(0.0, (float) ($effect['discount_percent'] ?? 100)));
        $buyQty = 0.0;
        $rewardQty = 0.0;
        $rewardUnit = 0.0;

        foreach ($items as $line) {
            if (! ($line['is_available'] ?? false)) {
                continue;
            }
            if ((int) ($line['product_id'] ?? 0) === $xProduct) {
                $buyQty += (float) ($line['quantity'] ?? 0);
            }
            if ((int) ($line['product_id'] ?? 0) === $yProduct) {
                $rewardQty += (float) ($line['quantity'] ?? 0);
                $rewardUnit = max($rewardUnit, (float) ($line['unit_price'] ?? 0));
            }
        }

        $sets = floor(($buyQty + 0.0001) / $x);
        $eligibleReward = min($rewardQty, $sets * $y);

        return round($eligibleReward * $rewardUnit * ($percent / 100), 3);
    }

    /** @param list<array<string,mixed>> $items */
    private function comboDiscount(array $items, array $effect): float
    {
        $requirements = (array) ($effect['products'] ?? []);
        $bundlePrice = max(0.0, (float) ($effect['bundle_price'] ?? 0));
        if ($requirements === []) {
            return 0.0;
        }

        $byProduct = [];
        foreach ($items as $line) {
            if (! ($line['is_available'] ?? false)) {
                continue;
            }
            $id = (int) ($line['product_id'] ?? 0);
            $byProduct[$id] = [
                'quantity' => ($byProduct[$id]['quantity'] ?? 0) + (float) ($line['quantity'] ?? 0),
                'unit_price' => (float) ($line['unit_price'] ?? 0),
            ];
        }

        $sets = null;
        $normalPerSet = 0.0;
        foreach ($requirements as $required) {
            if (! is_array($required)) {
                return 0.0;
            }
            $id = (int) ($required['product_id'] ?? 0);
            $qty = max(0.001, (float) ($required['quantity'] ?? 1));
            if (! isset($byProduct[$id])) {
                return 0.0;
            }
            $availableSets = (int) floor(($byProduct[$id]['quantity'] + 0.0001) / $qty);
            $sets = $sets === null ? $availableSets : min($sets, $availableSets);
            $normalPerSet += $byProduct[$id]['unit_price'] * $qty;
        }

        if (($sets ?? 0) <= 0) {
            return 0.0;
        }

        return round(max(0.0, $normalPerSet - $bundlePrice) * $sets, 3);
    }

    /** @param list<array<string,mixed>> $items */
    private function freeItemDiscount(array $items, array $effect): float
    {
        $target = (int) ($effect['product_id'] ?? 0);
        $maxQuantity = max(0.001, (float) ($effect['quantity'] ?? 1));

        foreach ($items as $line) {
            if ((int) ($line['product_id'] ?? 0) !== $target || ! ($line['is_available'] ?? false)) {
                continue;
            }

            return round(
                min((float) ($line['quantity'] ?? 0), $maxQuantity)
                * max(0.0, (float) ($line['unit_price'] ?? 0)),
                3,
            );
        }

        return 0.0;
    }

    /** @return list<array<string,mixed>> */
    private function recommendations(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        string $placement,
        float $subtotal,
        ?array $profile,
        ?int $triggerProductId,
    ): array {
        $favoriteIds = collect((array) ($profile['favorite_products'] ?? []))
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rules = DB::table('smart_ordering_recommendation_rules')
            ->where('site_id', $site->id)
            ->where('placement', $placement)
            ->where('is_active', true)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->when($triggerProductId !== null, function ($query) use ($triggerProductId): void {
                $query->where(function ($nested) use ($triggerProductId): void {
                    $nested->whereNull('trigger_product_id')->orWhere('trigger_product_id', $triggerProductId);
                });
            })
            ->where(function ($query) use ($subtotal): void {
                $query->whereNull('minimum_basket')->orWhere('minimum_basket', '<=', $subtotal);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(20)
            ->get();

        $result = [];
        foreach ($rules as $rule) {
            $product = $this->recommendableProduct($site, $branch, (int) $rule->recommended_product_id);
            if ($product === null) {
                continue;
            }
            $result[] = [
                'id' => (string) $rule->public_id,
                'name' => (string) $rule->name,
                'product' => $product,
                'personalized' => in_array((int) $rule->recommended_product_id, $favoriteIds, true),
                'priority' => (int) $rule->priority,
            ];
        }

        usort($result, static fn (array $a, array $b): int =>
            [$a['personalized'] ? 0 : 1, $a['priority'], $a['id']]
            <=> [$b['personalized'] ? 0 : 1, $b['priority'], $b['id']]
        );

        return array_slice($result, 0, 8);
    }

    /** @return array<string,mixed>|null */
    private function recommendableProduct(
        SmartOrderingSite $site,
        SmartOrderingBranch $branch,
        int $productId,
    ): ?array {
        $revisionId = DB::table('smart_ordering_revisions')
            ->where('site_id', $site->id)
            ->where('status', 'published')
            ->value('id');
        if ($revisionId === null) {
            return null;
        }

        $menuItem = SmartOrderingMenuItem::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $revisionId)
            ->where('source_product_id', $productId)
            ->first();
        if (! $menuItem instanceof SmartOrderingMenuItem) {
            return null;
        }
        $state = $this->availability->item($site, $menuItem, $branch);
        if (! $state['available']) {
            return null;
        }

        $row = DB::table('products as p')
            ->join('store_products as sp', function ($join) use ($site): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.store_id', '=', $site->store_id);
            })
            ->where('p.id', $productId)
            ->where('p.is_active', true)
            ->where('sp.is_active', true)
            ->whereNotNull('sp.price')
            ->first(['p.id', 'p.name', 'p.sku', 'sp.price']);
        if ($row === null) {
            return null;
        }

        return [
            'product_id' => (int) $row->id,
            'menu_item_id' => (string) $menuItem->public_id,
            'name' => (string) $row->name,
            'sku' => (string) $row->sku,
            'price' => (float) $row->price,
            'best_seller' => (bool) $menuItem->best_seller,
        ];
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
