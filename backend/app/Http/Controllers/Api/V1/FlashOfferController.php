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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        User $user,
        ?int $legacyCustomerId,
        CommercialPolicyService $commercialPolicy,
        CommerceQuoteService $quotes,
    ): array {
        $products = $offer->products;
        $first = $products->first();
        $offerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->whereIn('status', ['active', 'confirmed'])
            ->sum('reserved_base_quantity');
        $customerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->where('user_id', $user->getKey())
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
                    'customer',
                    max(0.001, (float) $first->conversion_factor),
                );
                $eligible = (bool) $decision['allowed'];
            }

            $eligible = $eligible
                && min($offerRemaining, $firstProductRemaining) + 0.0001 >= (float) $first->conversion_factor
                && $customerRemaining + 0.0001 >= (float) $first->conversion_factor;
        }

        $remainingAllocation = min($offerRemaining, $firstProductRemaining);

        return [
            'id' => (int) $offer->getKey(),
            'store_id' => (int) $offer->store_id,
            'title_ar' => (string) $offer->title_ar,
            'title_en' => (string) $offer->title_en,
            'body_ar' => $offer->body_ar,
            'body_en' => $offer->body_en,
            'title' => $user->locale === 'ar' ? (string) $offer->title_ar : (string) $offer->title_en,
            'body' => $user->locale === 'ar' ? (string) ($offer->body_ar ?? '') : (string) ($offer->body_en ?? ''),
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
            'products' => $products->map(static fn (FlashOfferProduct $product): array => [
                'id' => (int) $product->getKey(),
                'product_id' => (int) $product->product_id,
                'selling_unit_id' => $product->selling_unit_id === null ? null : (int) $product->selling_unit_id,
                'selling_unit_code' => $product->selling_unit_code,
                'conversion_factor' => (float) $product->conversion_factor,
                'flash_price' => (float) $product->flash_price,
                'allocation_base' => $product->allocation_base === null ? null : (float) $product->allocation_base,
            ])->values(),
        ];
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
