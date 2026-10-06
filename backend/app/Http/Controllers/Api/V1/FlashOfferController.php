<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\FlashReservation;
use App\Models\User;
use App\Services\FlashOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FlashOfferController extends Controller
{
    public function index(Request $request, FlashOfferService $flash): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'channel' => ['required', Rule::in(['customer', 'van', 'admin'])],
        ]);

        $offers = $flash->activeOffers((int) $data['store_id'], (string) $data['channel']);

        return response()->json([
            'server_time' => now()->toAtomString(),
            'data' => $offers->map(fn (FlashOffer $offer): array => $this->offerPayload($offer))->values(),
        ]);
    }

    public function reserve(
        Request $request,
        FlashOfferProduct $offerProduct,
        FlashOfferService $flash,
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'channel' => ['required', Rule::in(['customer', 'van', 'admin'])],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        $reservation = $flash->reserve(
            (int) $user->getKey(),
            (int) $offerProduct->getKey(),
            (float) $data['quantity'],
            (string) $data['channel'],
            (string) $data['idempotency_key'],
        );

        return response()->json($this->reservationPayload($reservation), 201);
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
    ): JsonResponse {
        $user = $this->user($request);
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'min:1'],
        ]);

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
    private function offerPayload(FlashOffer $offer): array
    {
        return [
            'id' => (int) $offer->getKey(),
            'store_id' => (int) $offer->store_id,
            'title_ar' => (string) $offer->title_ar,
            'title_en' => (string) $offer->title_en,
            'body_ar' => $offer->body_ar,
            'body_en' => $offer->body_en,
            'status' => (string) $offer->status,
            'starts_at' => $offer->startsAt()->toAtomString(),
            'ends_at' => $offer->endsAt()->toAtomString(),
            'priority' => (int) $offer->priority,
            'reservation_seconds' => (int) $offer->reservation_seconds,
            'popup_frequency' => (string) $offer->popup_frequency,
            'counts_toward_normal_quota' => (bool) $offer->counts_toward_normal_quota,
            'stackable' => (bool) $offer->stackable,
            'products' => $offer->products->map(static fn (FlashOfferProduct $product): array => [
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
