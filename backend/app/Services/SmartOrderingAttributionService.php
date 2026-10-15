<?php

namespace App\Services;

use App\Domain\SmartOrdering\SmartOrderingAnalyticsService;
use App\Models\Cart;
use App\Models\Order;
use App\Models\SmartOrderingQrCode;
use App\Models\SmartOrderingQrToken;
use App\Models\SmartOrderingQrVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingAttributionService
{
    public function __construct(private readonly SmartOrderingAnalyticsService $analytics) {}

    public const EVENT_QR_SCANNED = 'qr_scanned';

    public const EVENT_STOREFRONT_VIEWED = 'storefront_viewed';

    public const EVENT_CART_STARTED = 'cart_started';

    public const EVENT_CHECKOUT_STARTED = 'checkout_started';

    public const EVENT_ORDER_CREATED = 'order_created';

    public function startLanding(
        SmartOrderingQrCode $qr,
        SmartOrderingQrToken $token,
        string $visitorToken,
        ?string $referrer = null,
    ): SmartOrderingQrVisit {
        $visitorHash = hash_hmac('sha256', $visitorToken, $this->signingKey());

        $visit = SmartOrderingQrVisit::query()
            ->where('qr_code_id', $qr->getKey())
            ->where('visitor_hash', $visitorHash)
            ->where('landing_at', '>=', now()->subSeconds(30))
            ->latest('id')
            ->first();

        if (! $visit instanceof SmartOrderingQrVisit) {
            $visit = SmartOrderingQrVisit::query()->create([
                'public_id' => (string) Str::uuid(),
                'qr_code_id' => $qr->getKey(),
                'qr_token_id' => $token->getKey(),
                'visitor_hash' => $visitorHash,
                'source_snapshot' => $qr->source,
                'medium_snapshot' => $qr->medium,
                'campaign_snapshot' => $qr->campaign,
                'branch_reference_snapshot' => $qr->branch_reference,
                'table_reference_snapshot' => $qr->table_reference,
                'referrer_snapshot' => $this->boundedReferrer($referrer),
                'landing_at' => now(),
            ]);
        }

        $this->recordEvent($visit, self::EVENT_QR_SCANNED);

        return $visit;
    }

    public function signedContext(SmartOrderingQrVisit $visit): string
    {
        $payload = $this->base64UrlEncode((string) $visit->public_id);
        $signature = hash_hmac('sha256', 'v1.'.$payload, $this->signingKey());

        return 'v1.'.$payload.'.'.$signature;
    }

    public function contextFromRequest(Request $request): ?string
    {
        $candidates = [
            $request->header('X-Smart-Ordering-Context'),
            $request->query('so_ctx'),
            $request->cookie('foodex_so_attribution'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $context = trim($candidate);
            if (strlen($context) > 512
                || preg_match('/^v1\.[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $context) !== 1) {
                $this->invalidContext();
            }

            return $context;
        }

        return null;
    }

    public function markStorefrontViewed(string $context, int $expectedStoreId): SmartOrderingQrVisit
    {
        return $this->transition(
            $context,
            $expectedStoreId,
            'storefront_viewed_at',
            self::EVENT_STOREFRONT_VIEWED,
        );
    }

    public function attachCart(string $context, Cart $cart): SmartOrderingQrVisit
    {
        $visit = $this->resolveContext($context, (int) $cart->store_id);

        $conflict = SmartOrderingQrVisit::query()
            ->where('cart_id', $cart->getKey())
            ->whereKeyNot($visit->getKey())
            ->exists();

        if ($conflict || ($visit->cart_id !== null && (int) $visit->cart_id !== (int) $cart->getKey())) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.cart_attribution_conflict')],
            ]);
        }

        if ($visit->cart_id === null) {
            $visit->forceFill([
                'cart_id' => $cart->getKey(),
                'cart_started_at' => $visit->cart_started_at ?? now(),
            ])->save();
        }

        $this->recordEvent($visit, self::EVENT_CART_STARTED);

        return $visit->fresh();
    }

    public function markCheckoutStarted(string $context, int $expectedStoreId): SmartOrderingQrVisit
    {
        return $this->transition(
            $context,
            $expectedStoreId,
            'checkout_started_at',
            self::EVENT_CHECKOUT_STARTED,
        );
    }

    public function markCheckoutStartedForCart(Cart $cart): ?SmartOrderingQrVisit
    {
        $visit = SmartOrderingQrVisit::query()
            ->with('qrCode')
            ->where('cart_id', $cart->getKey())
            ->first();

        if (! $visit instanceof SmartOrderingQrVisit) {
            return null;
        }

        $this->assertVisitStore($visit, (int) $cart->store_id);

        if ($visit->checkout_started_at === null) {
            $visit->forceFill(['checkout_started_at' => now()])->save();
        }

        $this->recordEvent($visit, self::EVENT_CHECKOUT_STARTED);

        return $visit->fresh();
    }

    public function transferCartAttribution(Cart $source, Cart $target): void
    {
        if ((int) $source->store_id !== (int) $target->store_id) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.attribution_scope')],
            ]);
        }

        $sourceVisit = SmartOrderingQrVisit::query()
            ->with('qrCode')
            ->where('cart_id', $source->getKey())
            ->lockForUpdate()
            ->first();

        if (! $sourceVisit instanceof SmartOrderingQrVisit) {
            return;
        }

        $this->assertVisitStore($sourceVisit, (int) $target->store_id);

        $targetVisit = SmartOrderingQrVisit::query()
            ->where('cart_id', $target->getKey())
            ->whereKeyNot($sourceVisit->getKey())
            ->lockForUpdate()
            ->first();

        if ($targetVisit instanceof SmartOrderingQrVisit) {
            // First-touch attribution wins for an already-attributed authenticated cart.
            $sourceVisit->forceFill(['cart_id' => null])->save();

            return;
        }

        $sourceVisit->forceFill([
            'cart_id' => $target->getKey(),
            'cart_started_at' => $sourceVisit->cart_started_at ?? now(),
        ])->save();
    }

    public function promotionIdForCart(Cart $cart): ?int
    {
        $visit = SmartOrderingQrVisit::query()
            ->with('qrCode:id,store_id,promotion_id')
            ->where('cart_id', $cart->getKey())
            ->first();

        if (! $visit instanceof SmartOrderingQrVisit) {
            return null;
        }

        $qr = $visit->qrCode;
        if (! $qr instanceof SmartOrderingQrCode) {
            return null;
        }

        $this->assertVisitStore($visit, (int) $cart->store_id);

        return $qr->promotion_id === null ? null : (int) $qr->promotion_id;
    }

    public function attachOrderFromCart(Cart $cart, Order $order): ?SmartOrderingQrVisit
    {
        if ((int) $cart->store_id !== (int) $order->store_id) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.attribution_scope')],
            ]);
        }

        $this->attachSupplyReorderDraft($cart, $order);

        $visit = SmartOrderingQrVisit::query()
            ->with('qrCode')
            ->where('cart_id', $cart->getKey())
            ->lockForUpdate()
            ->first();

        if (! $visit instanceof SmartOrderingQrVisit) {
            return null;
        }

        $this->assertVisitStore($visit, (int) $order->store_id);

        return $this->attachOrderToVisit($visit, $order);
    }

    public function attachOrder(string $context, Order $order): SmartOrderingQrVisit
    {
        return DB::transaction(function () use ($context, $order): SmartOrderingQrVisit {
            $visit = $this->resolveContext($context, (int) $order->store_id, true);

            return $this->attachOrderToVisit($visit, $order);
        });
    }

    /**
     * @return array{store_id:int,qr_public_id:string,type:string,branch_reference:?string,table_reference:?string,promotion_id:?int,source:?string,campaign:?string}
     */
    public function serverContext(string $context, ?int $expectedStoreId = null): array
    {
        $visit = $this->resolveContext($context, $expectedStoreId);
        $qr = $visit->qrCode;
        if (! $qr instanceof SmartOrderingQrCode) {
            $this->invalidContext();
        }

        return [
            'store_id' => (int) $qr->store_id,
            'qr_public_id' => (string) $qr->public_id,
            'type' => (string) $qr->type,
            'branch_reference' => $visit->branch_reference_snapshot,
            'table_reference' => $visit->table_reference_snapshot,
            'promotion_id' => $qr->promotion_id === null ? null : (int) $qr->promotion_id,
            'source' => $visit->source_snapshot,
            'campaign' => $visit->campaign_snapshot,
        ];
    }

    /**
     * @param  list<int>  $qrIds
     * @return array<int, array{scans:int,unique_visits:int,carts:int,orders:int,conversion:float,revenue:float}>
     */
    public function metricsForQrIds(array $qrIds): array
    {
        if ($qrIds === []) {
            return [];
        }

        $rows = DB::table('smart_ordering_qr_visits')
            ->whereIn('qr_code_id', $qrIds)
            ->groupBy('qr_code_id')
            ->selectRaw('qr_code_id, COUNT(*) AS scans')
            ->selectRaw('COUNT(DISTINCT visitor_hash) AS unique_visits')
            ->selectRaw('SUM(CASE WHEN cart_started_at IS NOT NULL THEN 1 ELSE 0 END) AS carts')
            ->selectRaw('SUM(CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END) AS orders')
            ->selectRaw('COALESCE(SUM(revenue), 0) AS revenue')
            ->get();

        $metrics = [];
        foreach ($rows as $row) {
            $unique = (int) $row->unique_visits;
            $orders = (int) $row->orders;
            $metrics[(int) $row->qr_code_id] = [
                'scans' => (int) $row->scans,
                'unique_visits' => $unique,
                'carts' => (int) $row->carts,
                'orders' => $orders,
                'conversion' => $unique === 0 ? 0.0 : round(($orders / $unique) * 100, 2),
                'revenue' => round((float) $row->revenue, 3),
            ];
        }

        return $metrics;
    }

    private function transition(
        string $context,
        int $expectedStoreId,
        string $timestampColumn,
        string $eventName,
    ): SmartOrderingQrVisit {
        $visit = $this->resolveContext($context, $expectedStoreId);

        if ($visit->getAttribute($timestampColumn) === null) {
            $visit->forceFill([$timestampColumn => now()])->save();
        }

        $this->recordEvent($visit, $eventName);

        return $visit->fresh();
    }

    private function resolveContext(
        string $context,
        ?int $expectedStoreId = null,
        bool $lock = false,
    ): SmartOrderingQrVisit {
        $parts = explode('.', $context);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            $this->invalidContext();
        }

        [$version, $payload, $signature] = $parts;
        $expected = hash_hmac('sha256', $version.'.'.$payload, $this->signingKey());
        if (! hash_equals($expected, $signature)) {
            $this->invalidContext();
        }

        $publicId = $this->base64UrlDecode($payload);
        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            $this->invalidContext();
        }

        $query = SmartOrderingQrVisit::query()->with('qrCode')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $visit = $query->first();
        if (! $visit instanceof SmartOrderingQrVisit || ! $visit->qrCode instanceof SmartOrderingQrCode) {
            $this->invalidContext();
        }

        if ($expectedStoreId !== null && (int) $visit->qrCode->store_id !== $expectedStoreId) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.attribution_scope')],
            ]);
        }

        return $visit;
    }

    private function attachOrderToVisit(SmartOrderingQrVisit $visit, Order $order): SmartOrderingQrVisit
    {
        $conflict = SmartOrderingQrVisit::query()
            ->where('order_id', $order->getKey())
            ->whereKeyNot($visit->getKey())
            ->exists();

        if ($conflict || ($visit->order_id !== null && (int) $visit->order_id !== (int) $order->getKey())) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.order_attribution_conflict')],
            ]);
        }

        if ($visit->order_id === null) {
            $visit->forceFill([
                'order_id' => $order->getKey(),
                'order_created_at' => now(),
                // Persisted Order state is authoritative; no client discount/revenue value is accepted.
                'revenue' => (string) $order->grand_total,
            ])->save();
        }

        $this->recordEvent($visit, self::EVENT_ORDER_CREATED, [
            'order_reference' => (string) $order->order_number,
        ]);

        return $visit->fresh();
    }

    private function attachSupplyReorderDraft(Cart $cart, Order $order): void
    {
        if ((string) $cart->channel !== 'b2b' || (string) $order->channel !== 'b2b') {
            return;
        }

        DB::table('smart_ordering_supply_reorder_drafts')
            ->where('cart_id', $cart->getKey())
            ->where('b2b_customer_id', $order->b2b_customer_id)
            ->whereNull('converted_order_id')
            ->update([
                'converted_order_id' => $order->getKey(),
                'converted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function assertVisitStore(SmartOrderingQrVisit $visit, int $expectedStoreId): void
    {
        if (! $visit->qrCode instanceof SmartOrderingQrCode
            || (int) $visit->qrCode->store_id !== $expectedStoreId) {
            throw ValidationException::withMessages([
                'attribution' => [__('smart_ordering_qr.validation.attribution_scope')],
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordEvent(SmartOrderingQrVisit $visit, string $eventName, array $metadata = []): void
    {
        $eventKey = hash('sha256', $visit->public_id.':'.$eventName);

        $inserted = DB::table('smart_ordering_qr_events')->insertOrIgnore([
            'qr_visit_id' => $visit->getKey(),
            'event_name' => $eventName,
            'event_key' => $eventKey,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            return;
        }

        $qr = $visit->qrCode;
        if (! $qr instanceof SmartOrderingQrCode) {
            $qr = SmartOrderingQrCode::query()->find($visit->qr_code_id);
        }
        if (! $qr instanceof SmartOrderingQrCode) {
            return;
        }

        $analyticsIdentity = $eventName === self::EVENT_ORDER_CREATED && $visit->order_id !== null
            ? 'order:'.$visit->order_id.':order-created'
            : 'qr:'.$visit->public_id.':'.$eventName;

        $this->analytics->record(
            (int) $qr->store_id,
            $eventName,
            $analyticsIdentity,
            [
                'branch_id' => $this->branchIdForVisit($visit, (int) $qr->store_id),
                'qr_public_id' => (string) $qr->public_id,
                'visitor_hash' => (string) $visit->visitor_hash,
                'order_id' => $visit->order_id === null ? null : (int) $visit->order_id,
                'source' => $visit->source_snapshot,
                'campaign' => $visit->campaign_snapshot,
                'offer_reference' => $qr->promotion_id === null ? null : 'promotion:'.$qr->promotion_id,
                'metadata' => $metadata,
            ],
        );
    }

    private function branchIdForVisit(SmartOrderingQrVisit $visit, int $storeId): ?int
    {
        $reference = trim((string) ($visit->branch_reference_snapshot ?? ''));
        if ($reference === '') {
            return null;
        }

        $query = DB::table('smart_ordering_branches as b')
            ->join('smart_ordering_sites as s', 's.id', '=', 'b.site_id')
            ->where('s.store_id', $storeId);

        $query->where(function ($nested) use ($reference): void {
            $nested->where('b.code', $reference);
            if (Str::isUuid($reference)) {
                $nested->orWhere('b.public_id', $reference);
            }
        });

        $id = $query->value('b.id');

        return $id === null ? null : (int) $id;
    }

    private function boundedReferrer(?string $referrer): ?string
    {
        if (! is_string($referrer)) {
            return null;
        }

        $referrer = trim($referrer);
        if ($referrer === '') {
            return null;
        }

        return mb_substr($referrer, 0, 1024);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('Application key is required for Smart Ordering attribution signing.');
        }

        return $key;
    }

    private function invalidContext(): never
    {
        throw ValidationException::withMessages([
            'attribution' => [__('smart_ordering_qr.validation.invalid_attribution')],
        ]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
