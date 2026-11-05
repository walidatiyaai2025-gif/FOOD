<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\FlashReservation;
use App\Models\User;
use App\Services\CommerceQuoteService;
use App\Services\CommercialPolicyService;
use App\Services\CustomerDomainResolver;
use App\Services\FlashCheckoutService;
use App\Services\FlashOfferService;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\DisplayNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FlashOfferController extends Controller
{
    public function index(
        Request $request,
        FlashOfferService $flash,
        CommercialPolicyService $commercialPolicy,
        CustomerDomainResolver $customers,
        CommerceQuoteService $quotes,
    ): JsonResponse {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'channel' => ['required', Rule::in(['customer', 'van', 'admin'])],
        ]);

        $storeId = (int) $data['store_id'];
        $channel = (string) $data['channel'];
        $user = $this->user($request);
        $legacyCustomerId = null;

        if ($channel === 'customer') {
            // Wholesale mobile discovery has its own /b2b/flash-offers feed.
            abort_if(app(OperationalTenantScope::class)->storeChannel($storeId) === 'b2b', 404);
            [$customer] = $customers->forStore($user, $storeId, $request);
            $legacyCustomerId = $customers->legacyId($customer);
        }

        $offers = $flash->activeOffers(
            $storeId,
            $channel,
            $channel === 'customer' ? (int) $user->getKey() : null,
        );

        return response()->json([
            'server_time' => now()->toAtomString(),
            'data' => $offers
                ->map(fn (FlashOffer $offer): array => $this->offerPayload(
                    $offer,
                    $user,
                    $legacyCustomerId,
                    $commercialPolicy,
                    $quotes,
                ))
                ->values(),
        ]);
    }

    /**
     * Guest catalog for the retail Offers tab. Never expose targeted Flash
     * campaigns to anonymous users; checkout and eligibility remain gated.
     */
    public function publicIndex(
        Request $request,
        FlashOfferService $flash,
        CommercialPolicyService $commercialPolicy,
        CommerceQuoteService $quotes,
    ): JsonResponse {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);
        $storeId = (int) $data['store_id'];
        abort_unless(DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists(), 404);

        $offers = $flash->activeOffers($storeId, 'customer')
            ->filter(static fn (FlashOffer $offer): bool => (array) ($offer->audience_customer_ids ?? []) === []
                && (array) ($offer->audience_customer_group_ids ?? []) === []
                && (array) ($offer->audience_regions ?? []) === []
                && (array) ($offer->audience_routes ?? []) === []);

        return response()->json([
            'server_time' => now()->toAtomString(),
            'data' => $offers->map(fn (FlashOffer $offer): array => $this->offerPayload(
                $offer, null, null, $commercialPolicy, $quotes,
            ))->filter(static fn (array $offer): bool => $offer['products'] !== [])->values(),
        ]);
    }

    /**
     * Wholesale Customer App: show Van-only promotions without enabling
     * direct B2B customer checkout or exposing another customer's targeting.
     */
    public function wholesaleIndex(
        Request $request,
        FlashOfferService $flash,
        CommercialPolicyService $commercialPolicy,
        CustomerDomainResolver $customers,
        CommerceQuoteService $quotes,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertPrincipalWholesaleStore($storeId);
        $customer = $customers->b2bFromRequest($user, $request);
        $legacyCustomerId = $customer->legacy_customer_id === null
            ? null : (int) $customer->legacy_customer_id;

        $offers = $flash->activeOffers($storeId, 'van', (int) $user->getKey())
            ->filter(static fn (FlashOffer $offer): bool => in_array('customer', (array) $offer->channels, true));

        return response()->json([
            'server_time' => now()->toAtomString(),
            'fulfillment_channel' => 'van',
            'data' => $offers->map(fn (FlashOffer $offer): array => $this->offerPayload(
                $offer, $user, $legacyCustomerId, $commercialPolicy, $quotes, 'van',
            ))->filter(static fn (array $offer): bool => $offer['products'] !== [])->values(),
        ]);
    }

    /**
     * Guest-visible wholesale promotions are strictly untargeted and
     * informational: Van-only fulfillment remains authoritative.
     */
    public function publicWholesaleIndex(
        Request $request,
        FlashOfferService $flash,
        CommercialPolicyService $commercialPolicy,
        CommerceQuoteService $quotes,
    ): JsonResponse {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertPrincipalWholesaleStore($storeId);
        $offers = $flash->activeOffers($storeId, 'van')
            ->filter(static fn (FlashOffer $offer): bool => in_array('customer', (array) $offer->channels, true))
            ->filter(static fn (FlashOffer $offer): bool => (array) ($offer->audience_customer_ids ?? []) === []
                && (array) ($offer->audience_customer_group_ids ?? []) === []
                && (array) ($offer->audience_regions ?? []) === []
                && (array) ($offer->audience_routes ?? []) === []);

        return response()->json([
            'server_time' => now()->toAtomString(),
            'fulfillment_channel' => 'van',
            'data' => $offers->map(fn (FlashOffer $offer): array => $this->offerPayload(
                $offer, null, null, $commercialPolicy, $quotes, 'van',
            ))->filter(static fn (array $offer): bool => $offer['products'] !== [])->values(),
        ]);
    }

    private function assertPrincipalWholesaleStore(int $storeId): void
    {
        abort_unless(DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.code', WholesalePrincipal::STORE_CODE)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->exists(), 404);
    }

    public function event(
        Request $request,
        FlashOffer $offer,
        FlashOfferService $flash,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'event' => ['required', Rule::in(['impression', 'open', 'buy_now_click'])],
        ]);
        abort_unless((int) $offer->store_id === (int) $data['store_id'], 404);

        $flash->trackInteraction(
            $offer,
            (int) $user->getKey(),
            'customer',
            (string) $data['event'],
        );

        return response()->json(['accepted' => true], 202);
    }

    public function reserve(
        Request $request,
        FlashOfferProduct $offerProduct,
        FlashOfferService $flash,
        CommercialPolicyService $commercialPolicy,
        CustomerDomainResolver $customers,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'channel' => ['required', Rule::in(['customer', 'van', 'admin'])],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        $offer = FlashOffer::query()->findOrFail((int) $offerProduct->flash_offer_id);
        if ((string) $data['channel'] === 'customer') {
            [$customer] = $customers->forStore($user, (int) $offer->store_id, $request);
            $legacyCustomerId = $customers->legacyId($customer);
            $baseQuantity = round((float) $data['quantity'] * (float) $offerProduct->conversion_factor, 3);
            $decision = $commercialPolicy->evaluate(
                (int) $offerProduct->product_id,
                $legacyCustomerId,
                'customer',
                $baseQuantity,
            );

            if (! $decision['allowed']) {
                throw ValidationException::withMessages([
                    'flash_offer' => [
                        'Commercial policy rejected Flash reservation: '.implode(',', $decision['reason_codes']),
                    ],
                ]);
            }
        }

        $reservation = $flash->reserve(
            (int) $user->getKey(),
            (int) $offerProduct->getKey(),
            (float) $data['quantity'],
            (string) $data['channel'],
            (string) $data['idempotency_key'],
        );

        return response()->json($this->reservationPayload($reservation), 201);
    }

    public function activeReservation(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'channel' => ['required', Rule::in(['customer', 'van', 'admin'])],
        ]);

        $reservation = FlashReservation::query()
            ->join('flash_offers', 'flash_offers.id', '=', 'flash_reservations.flash_offer_id')
            ->where('flash_reservations.user_id', $user->getKey())
            ->where('flash_reservations.channel', (string) $data['channel'])
            ->where('flash_reservations.status', 'active')
            ->where('flash_reservations.expires_at', '>', now())
            ->where('flash_offers.store_id', (int) $data['store_id'])
            ->orderByDesc('flash_reservations.created_at')
            ->select('flash_reservations.*')
            ->first();

        abort_unless($reservation instanceof FlashReservation, 404);

        return response()->json(['data' => $this->reservationPayload($reservation)]);
    }

    public function showReservation(Request $request, FlashReservation $reservation): JsonResponse
    {
        $user = $this->user($request);
        abort_unless((int) $reservation->user_id === (int) $user->getKey(), 404);

        return response()->json($this->reservationPayload($reservation));
    }

    public function confirm(
        Request $request,
        FlashReservation $reservation,
        FlashOfferService $flash,
        FlashCheckoutService $checkout,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'min:1'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'channel' => ['nullable', Rule::in(['customer', 'van', 'admin'])],
            'address_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        if (($data['channel'] ?? null) === 'customer' || isset($data['address_id'])) {
            foreach (['store_id', 'address_id', 'payment_method'] as $required) {
                if (! isset($data[$required]) || trim((string) $data[$required]) === '') {
                    throw ValidationException::withMessages([
                        $required => ['This field is required for Customer Flash checkout.'],
                    ]);
                }
            }

            [$order, $created] = $checkout->confirmCustomer(
                $request,
                $reservation,
                $user,
                (int) $data['store_id'],
                (int) $data['address_id'],
                (string) $data['payment_method'],
                trim((string) $request->header('Idempotency-Key', '')),
            );

            return response()->json([
                'id' => (int) $order->getKey(),
                'store_id' => (int) $order->store_id,
                'order_number' => (string) $order->order_number,
                'display_order_number' => DisplayNumber::reference($order->getKey()),
                'status' => (string) $order->status,
            ], $created ? 201 : 200);
        }

        $reservation = $flash->confirm(
            (string) $reservation->getKey(),
            (int) $user->getKey(),
            isset($data['order_id']) ? (int) $data['order_id'] : null,
        );

        return response()->json($this->reservationPayload($reservation));
    }

    public function release(
        Request $request,
        FlashReservation $reservation,
        FlashOfferService $flash,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $reservation = $flash->release(
            (string) $reservation->getKey(),
            (int) $user->getKey(),
            (string) ($data['reason'] ?? 'customer_release'),
        );

        return response()->json($this->reservationPayload($reservation));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @return array<string,mixed> */
    private function offerPayload(
        FlashOffer $offer,
        ?User $user,
        ?int $legacyCustomerId,
        CommercialPolicyService $commercialPolicy,
        CommerceQuoteService $quotes,
        string $policyChannel = 'customer',
    ): array {
        $products = $offer->products;
        $first = $products->first();
        $offerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->whereIn('status', ['active', 'confirmed'])
            ->sum('reserved_base_quantity');
        $customerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->where('user_id', $user?->getKey() ?? 0)
            ->whereIn('status', ['active', 'confirmed'])
            ->sum('reserved_base_quantity');

        $offerRemaining = $offer->total_allocation_base === null
            ? 999999999.0
            : max(0.0, (float) $offer->total_allocation_base - $offerUsed);
        $customerRemaining = $offer->per_customer_limit_base === null
            ? 999999999.0
            : max(0.0, (float) $offer->per_customer_limit_base - $customerUsed);

        $firstProductRemaining = 999999999.0;
        $eligible = $first instanceof FlashOfferProduct;
        $normalPrice = 0.0;
        $flashPrice = $first instanceof FlashOfferProduct ? (float) $first->flash_price : 0.0;

        if ($first instanceof FlashOfferProduct) {
            $productUsed = (float) FlashReservation::query()
                ->where('flash_offer_product_id', $first->getKey())
                ->whereIn('status', ['active', 'confirmed'])
                ->sum('reserved_base_quantity');
            $firstProductRemaining = $first->allocation_base === null
                ? 999999999.0
                : max(0.0, (float) $first->allocation_base - $productUsed);

            $normalPrice = (float) DB::table('store_products')
                ->where('store_id', (int) $offer->store_id)
                ->where('product_id', (int) $first->product_id)
                ->value('price');

            if ($legacyCustomerId !== null) {
                $decision = $commercialPolicy->evaluate(
                    (int) $first->product_id,
                    $legacyCustomerId,
                    $policyChannel,
                    max(0.001, (float) $first->conversion_factor),
                );
                $eligible = (bool) $decision['allowed'];
            }

            $eligible = $eligible
                && min($offerRemaining, $firstProductRemaining) + 0.0001 >= (float) $first->conversion_factor
                && $customerRemaining + 0.0001 >= (float) $first->conversion_factor;
        }

        $remainingAllocation = min($offerRemaining, $firstProductRemaining);
        $availableProducts = $this->availableFlashProducts(
            $offer, $products, $legacyCustomerId, $commercialPolicy,
            $offerRemaining, $customerRemaining, $policyChannel,
        );

        return [
            'id' => (int) $offer->getKey(),
            'store_id' => (int) $offer->store_id,
            'fulfillment_channel' => $policyChannel === 'van' ? 'van' : 'driver',
            'title_ar' => (string) $offer->title_ar,
            'title_en' => (string) $offer->title_en,
            'body_ar' => $offer->body_ar,
            'body_en' => $offer->body_en,
            'title' => ($user->locale ?? 'ar') === 'ar' ? (string) $offer->title_ar : (string) $offer->title_en,
            'body' => ($user->locale ?? 'ar') === 'ar' ? (string) ($offer->body_ar ?? '') : (string) ($offer->body_en ?? ''),
            'currency' => (string) $quotes->directPurchaseTotals((int) $offer->store_id, 0)['currency'],
            'flash_price' => $flashPrice,
            'normal_price' => $normalPrice,
            'remaining_allocation' => (int) floor($remainingAllocation),
            'remaining_customer_limit' => (int) floor($customerRemaining),
            'eligible' => $eligible,
            'server_time' => now()->toAtomString(),
            'status' => (string) $offer->status,
            'starts_at' => $offer->startsAt()->toAtomString(),
            'ends_at' => $offer->endsAt()->toAtomString(),
            'priority' => (int) $offer->priority,
            'reservation_seconds' => (int) $offer->reservation_seconds,
            'popup_frequency' => (string) $offer->popup_frequency,
            'counts_toward_normal_quota' => (bool) $offer->counts_toward_normal_quota,
            'stackable' => (bool) $offer->stackable,
            'selling_units' => $products->map(static fn (FlashOfferProduct $product): array => [
                'id' => (int) $product->getKey(),
                'label' => (string) ($product->selling_unit_code ?: 'Unit'),
                'conversion_factor' => (float) $product->conversion_factor,
                'flash_price' => (float) $product->flash_price,
            ])->values(),
            'products' => $availableProducts,
        ];
    }

    /**
     * The list is product-level, store-scoped and uses live remaining stock.
     * Do not show offers with inactive/unpriced/cross-store products.
     *
     * @param  Collection<int, FlashOfferProduct>  $offerProducts
     * @return list<array<string, mixed>>
     */
    private function availableFlashProducts(
        FlashOffer $offer,
        Collection $offerProducts,
        ?int $legacyCustomerId,
        CommercialPolicyService $commercialPolicy,
        float $offerRemaining,
        float $customerRemaining,
        string $policyChannel = 'customer',
    ): array {
        $storeId = (int) $offer->store_id;
        $storeChannel = app(OperationalTenantScope::class)->storeChannel($storeId);
        $ids = $offerProducts->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        $catalog = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', $storeId);
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', $storeChannel)
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->whereIn('products.id', $ids)
            ->get(['products.id', 'products.name', 'products.sku', 'store_products.price'])
            ->keyBy('id');

        $images = DB::table('product_images')
            ->whereIn('product_id', $ids)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['product_id', 'path'])
            ->unique('product_id')
            ->keyBy('product_id');
        $unitIds = $offerProducts->pluck('selling_unit_id')->filter()->unique();
        $units = DB::table('product_selling_units')
            ->whereIn('id', $unitIds)
            ->where('is_active', true)
            ->get(['id', 'product_id', 'name', 'price'])
            ->keyBy('id');
        $inventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->whereIn('inventories.product_id', $ids)
            ->groupBy('inventories.product_id')
            ->selectRaw('inventories.product_id, SUM(CASE WHEN inventories.quantity > inventories.reserved_quantity THEN inventories.quantity - inventories.reserved_quantity ELSE 0 END) AS available')
            ->pluck('available', 'product_id');
        $used = FlashReservation::query()
            ->whereIn('flash_offer_product_id', $offerProducts->pluck('id'))
            ->whereIn('status', ['active', 'confirmed'])
            ->groupBy('flash_offer_product_id')
            ->selectRaw('flash_offer_product_id, SUM(reserved_base_quantity) AS used_base')
            ->pluck('used_base', 'flash_offer_product_id');

        $result = [];
        foreach ($offerProducts as $entry) {
            $productId = (int) $entry->product_id;
            $item = $catalog->get($productId);
            $unit = $units->get((int) $entry->selling_unit_id);
            $conversion = (float) $entry->conversion_factor;
            $stock = (float) ($inventory->get($productId) ?? 0);
            $allocation = $entry->allocation_base === null
                ? $offerRemaining
                : min($offerRemaining, max(0.0, (float) $entry->allocation_base - (float) ($used->get($entry->getKey()) ?? 0)));
            // Legacy Flash records can have a canonical unit code without
            // selling_unit_id; honor their stored conversion/pricing snapshot.
            if ($item === null || ($entry->selling_unit_id !== null
                && ($unit === null || (int) $unit->product_id !== $productId))
                || $conversion <= 0 || min($stock, $allocation, $customerRemaining) + 0.0001 < $conversion) {
                continue;
            }
            if ($legacyCustomerId !== null) {
                $decision = $commercialPolicy->evaluate($productId, $legacyCustomerId, $policyChannel, $conversion);
                if (! (bool) $decision['allowed']) {
                    continue;
                }
            }
            $path = trim((string) ($images->get($productId)->path ?? ''));
            $result[] = [
                'id' => (int) $entry->getKey(),
                'product_id' => $productId,
                'name' => (string) $item->name,
                'sku' => (string) ($item->sku ?? ''),
                'image_url' => $path === '' ? null : (preg_match('~^https?://~i', $path) ? $path : url('/'.ltrim($path, '/'))),
                'selling_unit_id' => $entry->selling_unit_id === null ? null : (int) $entry->selling_unit_id,
                'selling_unit_code' => (string) $entry->selling_unit_code,
                'selling_unit_name' => (string) ($unit->name ?? $entry->selling_unit_code),
                'conversion_factor' => $conversion,
                // Match FlashCheckoutService's normal_unit_price exactly.
                'normal_price' => $unit?->price === null
                    ? round((float) $item->price, 3) : (float) $unit->price,
                'flash_price' => (float) $entry->flash_price,
                'available_quantity' => (int) floor(min($stock, $allocation, $customerRemaining) / $conversion),
            ];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function reservationPayload(FlashReservation $reservation): array
    {
        return [
            'id' => (string) $reservation->getKey(),
            'offer_id' => (int) $reservation->flash_offer_id,
            'flash_offer_id' => (int) $reservation->flash_offer_id,
            'flash_offer_product_id' => (int) $reservation->flash_offer_product_id,
            'channel' => (string) $reservation->channel,
            'selling_quantity' => (float) $reservation->selling_quantity,
            'reserved_base_quantity' => (float) $reservation->reserved_base_quantity,
            'unit_price' => (float) $reservation->unit_price,
            'status' => (string) $reservation->status,
            'server_time' => now()->toAtomString(),
            'expires_at' => $reservation->expiresAt()->toAtomString(),
            'confirmed_at' => $reservation->confirmedAt()?->toAtomString(),
            'order_id' => $reservation->order_id === null ? null : (int) $reservation->order_id,
        ];
    }
}
