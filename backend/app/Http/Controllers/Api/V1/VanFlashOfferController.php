<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\FlashReservation;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\FlashOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class VanFlashOfferController extends Controller
{
    public function reserve(
        Request $request,
        string $type,
        int $customer,
        FlashOfferProduct $offerProduct,
        FlashOfferService $flash,
    ): JsonResponse {
        $actor = $this->actor($request);
        $storeId = $this->assertCustomerScope($request, $actor, $type, $customer);
        $customerUserId = $this->customerUserId($type, $customer);

        $offer = FlashOffer::query()->find((int) $offerProduct->flash_offer_id);
        abort_unless($offer instanceof FlashOffer, 404);
        abort_unless((int) $offer->store_id === $storeId, 404);

        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        $reservation = $flash->reserve(
            $customerUserId,
            (int) $offerProduct->getKey(),
            (float) $data['quantity'],
            'van',
            (string) $data['idempotency_key'],
        );

        return response()->json($this->reservationPayload($reservation), 201);
    }

    public function show(
        Request $request,
        string $type,
        int $customer,
        FlashReservation $reservation,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCustomerScope($request, $actor, $type, $customer);
        $customerUserId = $this->customerUserId($type, $customer);
        abort_unless((int) $reservation->user_id === $customerUserId, 404);

        return response()->json($this->reservationPayload($reservation));
    }

    public function confirm(
        Request $request,
        string $type,
        int $customer,
        FlashReservation $reservation,
        FlashOfferService $flash,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCustomerScope($request, $actor, $type, $customer);
        $customerUserId = $this->customerUserId($type, $customer);
        abort_unless((int) $reservation->user_id === $customerUserId, 404);

        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $updated = $flash->confirm(
            (string) $reservation->getKey(),
            $customerUserId,
            isset($data['order_id']) ? (int) $data['order_id'] : null,
        );

        return response()->json($this->reservationPayload($updated));
    }

    public function release(
        Request $request,
        string $type,
        int $customer,
        FlashReservation $reservation,
        FlashOfferService $flash,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCustomerScope($request, $actor, $type, $customer);
        $customerUserId = $this->customerUserId($type, $customer);
        abort_unless((int) $reservation->user_id === $customerUserId, 404);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $updated = $flash->release(
            (string) $reservation->getKey(),
            $customerUserId,
            (string) ($data['reason'] ?? 'van_release'),
        );

        return response()->json($this->reservationPayload($updated));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function assertCustomerScope(
        Request $request,
        User $actor,
        string $type,
        int $customer,
    ): int {
        abort_unless(in_array($type, ['b2b', 'b2c'], true), 404);

        $storeIds = VanVisit::query()
            ->where('actor_user_id', $actor->getKey())
            ->where('customer_type', $type)
            ->where('customer_id', $customer)
            ->whereNotNull('store_id')
            ->distinct()
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        abort_if($storeIds->isEmpty(), 404);

        $requested = $request->input('store_id');
        if ($requested === null) {
            if ($storeIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'store_id' => ['Store is required when the customer is in more than one assigned Van scope.'],
                ]);
            }

            return (int) $storeIds->first();
        }

        $storeId = (int) $requested;
        abort_unless($storeIds->contains($storeId), 404);

        return $storeId;
    }

    private function customerUserId(string $type, int $customer): int
    {
        $userId = match ($type) {
            'b2b' => B2bCustomer::query()->whereKey($customer)->value('user_id'),
            'b2c' => B2cCustomer::query()->whereKey($customer)->value('user_id'),
            default => null,
        };

        if ($userId === null) {
            throw ValidationException::withMessages([
                'customer_id' => ['The selected customer must have a platform identity before a Flash reservation can be created.'],
            ]);
        }

        return (int) $userId;
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
