<?php

namespace App\Services;

use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\FlashReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class FlashOfferService
{
    /** @return Collection<int, FlashOffer> */
    public function activeOffers(int $storeId, string $channel): Collection
    {
        $this->expireDue();

        return FlashOffer::query()
            ->with('products')
            ->where('store_id', $storeId)
            ->whereIn('status', ['scheduled', 'active', 'sold_out'])
            ->where('kill_switch', false)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (FlashOffer $offer): bool => in_array($channel, (array) $offer->channels, true))
            ->values();
    }

    public function reserve(
        int $userId,
        int $offerProductId,
        float $sellingQuantity,
        string $channel,
        string $idempotencyKey,
    ): FlashReservation {
        if ($sellingQuantity <= 0) {
            throw new HttpException(422, 'Selling quantity must be greater than zero.');
        }

        if (trim($idempotencyKey) === '') {
            throw new HttpException(422, 'Idempotency key is required.');
        }

        return DB::transaction(function () use ($userId, $offerProductId, $sellingQuantity, $channel, $idempotencyKey): FlashReservation {
            $existing = FlashReservation::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof FlashReservation) {
                return $existing;
            }

            $offerProduct = FlashOfferProduct::query()
                ->whereKey($offerProductId)
                ->lockForUpdate()
                ->first();

            if (! $offerProduct instanceof FlashOfferProduct) {
                throw new HttpException(404, 'Flash offer product was not found.');
            }

            $offer = FlashOffer::query()
                ->whereKey($offerProduct->flash_offer_id)
                ->lockForUpdate()
                ->first();

            if (! $offer instanceof FlashOffer) {
                throw new HttpException(404, 'Flash offer was not found.');
            }

            $this->assertReservable($offer, $channel);

            $baseQuantity = round($sellingQuantity * (float) $offerProduct->conversion_factor, 3);
            if ($baseQuantity <= 0) {
                throw new HttpException(422, 'Resolved base quantity must be greater than zero.');
            }

            $this->assertRetryPolicy($offer, $userId);
            $this->assertAllocation($offer, $offerProduct, $userId, $baseQuantity);

            $inventoryAllocations = $this->reserveInventory(
                (int) $offer->store_id,
                (int) $offerProduct->product_id,
                $baseQuantity,
            );

            $reservation = FlashReservation::query()->create([
                'id' => (string) Str::uuid(),
                'flash_offer_id' => $offer->getKey(),
                'flash_offer_product_id' => $offerProduct->getKey(),
                'user_id' => $userId,
                'channel' => $channel,
                'selling_quantity' => round($sellingQuantity, 3),
                'reserved_base_quantity' => $baseQuantity,
                'unit_price' => (float) $offerProduct->flash_price,
                'status' => 'active',
                'idempotency_key' => $idempotencyKey,
                'inventory_allocations' => $inventoryAllocations,
                'expires_at' => now()->addSeconds(max(30, (int) $offer->reservation_seconds)),
            ]);

            $this->event($offer, $reservation, $userId, 'reservation_created', $channel, [
                'base_quantity' => $baseQuantity,
                'selling_quantity' => round($sellingQuantity, 3),
                'price' => (float) $offerProduct->flash_price,
            ]);

            return $reservation;
        }, 3);
    }

    public function confirm(string $reservationId, int $userId, ?int $orderId = null): FlashReservation
    {
        return DB::transaction(function () use ($reservationId, $userId, $orderId): FlashReservation {
            $reservation = FlashReservation::query()->whereKey($reservationId)->lockForUpdate()->first();

            if (! $reservation instanceof FlashReservation || (int) $reservation->user_id !== $userId) {
                throw new HttpException(404, 'Flash reservation was not found.');
            }

            if ($reservation->status === 'confirmed') {
                return $reservation;
            }

            if ($reservation->status !== 'active') {
                throw new HttpException(409, 'Flash reservation is not active.');
            }

            if ($reservation->expiresAt()->isPast()) {
                $this->releaseLocked($reservation, 'expired');
                throw new HttpException(409, 'FLASH_RESERVATION_EXPIRED');
            }

            $reservation->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'order_id' => $orderId,
            ]);

            $offer = FlashOffer::query()->find((int) $reservation->flash_offer_id);
            if ($offer instanceof FlashOffer) {
                $this->event($offer, $reservation, $userId, 'reservation_confirmed', (string) $reservation->channel, [
                    'order_id' => $orderId,
                ]);
            }

            $reservation->refresh();

            return $reservation;
        }, 3);
    }

    public function release(string $reservationId, int $userId, string $reason = 'customer_release'): FlashReservation
    {
        return DB::transaction(function () use ($reservationId, $userId, $reason): FlashReservation {
            $reservation = FlashReservation::query()->whereKey($reservationId)->lockForUpdate()->first();

            if (! $reservation instanceof FlashReservation || (int) $reservation->user_id !== $userId) {
                throw new HttpException(404, 'Flash reservation was not found.');
            }

            if (in_array($reservation->status, ['released', 'expired'], true)) {
                return $reservation;
            }

            if ($reservation->status === 'confirmed' && $reservation->order_id !== null) {
                throw new HttpException(409, 'Confirmed reservation is already attached to an order.');
            }

            $this->releaseLocked($reservation, 'released', $reason);

            return $reservation->fresh();
        }, 3);
    }

    public function expireDue(int $limit = 250): int
    {
        $ids = FlashReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $changed = DB::transaction(function () use ($id): bool {
                $reservation = FlashReservation::query()->whereKey($id)->lockForUpdate()->first();
                if (! $reservation instanceof FlashReservation
                    || $reservation->status !== 'active'
                    || $reservation->expiresAt()->isFuture()) {
                    return false;
                }

                $this->releaseLocked($reservation, 'expired');

                return true;
            }, 3);

            if ($changed) {
                $expired++;
            }
        }

        FlashOffer::query()
            ->whereIn('status', ['scheduled', 'active', 'paused', 'sold_out'])
            ->where('ends_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);

        FlashOffer::query()
            ->where('status', 'scheduled')
            ->where('kill_switch', false)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->update(['status' => 'active', 'updated_at' => now()]);

        return $expired;
    }

    private function assertReservable(FlashOffer $offer, string $channel): void
    {
        if ($offer->kill_switch) {
            throw new HttpException(409, 'FLASH_NOT_ACTIVE');
        }

        if (! in_array($channel, (array) $offer->channels, true)) {
            throw new HttpException(403, 'CHANNEL_NOT_ALLOWED');
        }

        if (! in_array($offer->status, ['scheduled', 'active'], true)
            || $offer->startsAt()->isFuture()
            || ! $offer->endsAt()->isFuture()) {
            throw new HttpException(409, 'FLASH_NOT_ACTIVE');
        }
    }

    private function assertRetryPolicy(FlashOffer $offer, int $userId): void
    {
        $cooldown = max(0, (int) $offer->cooldown_seconds);
        $retryCount = max(0, (int) $offer->retry_count);
        if ($cooldown === 0 || $retryCount === 0) {
            return;
        }

        $recentFailures = FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->where('user_id', $userId)
            ->whereIn('status', ['released', 'expired'])
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->count();

        if ($recentFailures >= $retryCount) {
            throw new HttpException(429, 'Flash reservation retry cooldown is active.');
        }
    }

    private function assertAllocation(
        FlashOffer $offer,
        FlashOfferProduct $product,
        int $userId,
        float $requestedBase,
    ): void {
        $activeStates = ['active', 'confirmed'];

        $customerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->where('user_id', $userId)
            ->whereIn('status', $activeStates)
            ->sum('reserved_base_quantity');

        if ($offer->per_customer_limit_base !== null
            && $customerUsed + $requestedBase > (float) $offer->per_customer_limit_base + 0.0001) {
            throw new HttpException(409, 'FLASH_CUSTOMER_LIMIT_REACHED');
        }

        $offerUsed = (float) FlashReservation::query()
            ->where('flash_offer_id', $offer->getKey())
            ->whereIn('status', $activeStates)
            ->sum('reserved_base_quantity');

        if ($offer->total_allocation_base !== null
            && $offerUsed + $requestedBase > (float) $offer->total_allocation_base + 0.0001) {
            throw new HttpException(409, 'FLASH_SOLD_OUT');
        }

        $productUsed = (float) FlashReservation::query()
            ->where('flash_offer_product_id', $product->getKey())
            ->whereIn('status', $activeStates)
            ->sum('reserved_base_quantity');

        if ($product->allocation_base !== null
            && $productUsed + $requestedBase > (float) $product->allocation_base + 0.0001) {
            throw new HttpException(409, 'FLASH_SOLD_OUT');
        }
    }

    /** @return array<int, array{inventory_id:int,quantity:float}> */
    private function reserveInventory(int $storeId, int $productId, float $baseQuantity): array
    {
        $rows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->orderBy('inventories.id')
            ->lockForUpdate()
            ->get([
                'inventories.id',
                'inventories.quantity',
                'inventories.reserved_quantity',
            ]);

        $available = (float) $rows->sum(
            static fn (object $row): float => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
        );

        if ($available + 0.0001 < $baseQuantity) {
            throw new HttpException(409, 'INSUFFICIENT_STOCK');
        }

        $remaining = $baseQuantity;
        $allocations = [];

        foreach ($rows as $row) {
            if ($remaining <= 0.0001) {
                break;
            }

            $rowAvailable = max(0.0, (float) $row->quantity - (float) $row->reserved_quantity);
            if ($rowAvailable <= 0) {
                continue;
            }

            $take = round(min($remaining, $rowAvailable), 3);
            DB::table('inventories')->where('id', $row->id)->update([
                'reserved_quantity' => DB::raw('reserved_quantity + '.sprintf('%.3F', $take)),
                'updated_at' => now(),
            ]);
            $allocations[] = ['inventory_id' => (int) $row->id, 'quantity' => $take];
            $remaining = round($remaining - $take, 3);
        }

        return $allocations;
    }

    private function releaseLocked(
        FlashReservation $reservation,
        string $status,
        string $reason = 'timeout',
    ): void {
        foreach ($reservation->inventoryAllocations() as $allocation) {
            $inventoryId = (int) $allocation['inventory_id'];
            $quantity = (float) $allocation['quantity'];
            if ($inventoryId <= 0 || $quantity <= 0) {
                continue;
            }

            $row = DB::table('inventories')->where('id', $inventoryId)->lockForUpdate()->first();
            if ($row === null) {
                continue;
            }

            $newReserved = max(0.0, round((float) $row->reserved_quantity - $quantity, 3));
            DB::table('inventories')->where('id', $inventoryId)->update([
                'reserved_quantity' => $newReserved,
                'updated_at' => now(),
            ]);
        }

        $reservation->update([
            'status' => $status,
            'released_at' => now(),
        ]);

        $offer = FlashOffer::query()->find((int) $reservation->flash_offer_id);
        if ($offer instanceof FlashOffer) {
            $this->event($offer, $reservation, (int) $reservation->user_id, 'reservation_'.$status, (string) $reservation->channel, [
                'reason' => $reason,
            ]);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function event(
        FlashOffer $offer,
        ?FlashReservation $reservation,
        ?int $userId,
        string $event,
        ?string $channel,
        array $metadata = [],
    ): void {
        DB::table('flash_offer_events')->insert([
            'flash_offer_id' => $offer->getKey(),
            'flash_reservation_id' => $reservation?->getKey(),
            'user_id' => $userId,
            'event' => $event,
            'channel' => $channel,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
