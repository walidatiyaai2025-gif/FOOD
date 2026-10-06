<?php

namespace App\Services;

use App\Models\FlashOffer;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class FlashOfferNotificationDispatcher
{
    public function __construct(private readonly PushDeliveryService $push) {}

    public function dispatchDue(int $limit = 100): int
    {
        $offerIds = FlashOffer::query()
            ->whereIn('status', ['scheduled', 'active'])
            ->where('kill_switch', false)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $dispatched = 0;

        foreach ($offerIds as $offerId) {
            $offer = FlashOffer::query()->find($offerId);
            if (! $offer instanceof FlashOffer) {
                continue;
            }

            foreach (['customer', 'van'] as $channel) {
                if (! in_array($channel, (array) $offer->channels, true)) {
                    continue;
                }

                if ($this->dispatchChannel($offerId, $channel)) {
                    $dispatched++;
                }
            }
        }

        return $dispatched;
    }

    private function dispatchChannel(int $offerId, string $channel): bool
    {
        $eventName = 'start_notification_'.$channel;

        /** @var array{notification: Notification, event_id: int}|null $pending */
        $pending = DB::transaction(function () use ($offerId, $channel, $eventName): ?array {
            $offer = FlashOffer::query()->whereKey($offerId)->lockForUpdate()->first();

            if (! $offer instanceof FlashOffer
                || $offer->kill_switch
                || ! in_array((string) $offer->status, ['scheduled', 'active'], true)
                || now()->lt($offer->startsAt())
                || ! now()->lt($offer->endsAt())
                || ! in_array($channel, (array) $offer->channels, true)) {
                return null;
            }

            $event = DB::table('flash_offer_events')
                ->where('flash_offer_id', $offerId)
                ->where('event', $eventName)
                ->lockForUpdate()
                ->first();

            if ($event !== null) {
                $metadata = is_string($event->metadata) ? json_decode($event->metadata, true) : null;
                $notificationId = is_array($metadata) ? (int) ($metadata['notification_id'] ?? 0) : 0;
                $notification = $notificationId > 0 ? Notification::query()->find($notificationId) : null;

                return $notification instanceof Notification
                    ? ['notification' => $notification, 'event_id' => (int) $event->id]
                    : null;
            }

            $isCustomer = $channel === 'customer';
            $notification = Notification::query()->create([
                'channel' => 'push',
                'type' => 'flash_offer.started',
                'dedupe_key' => 'flash-offer:'.$offerId.':start:'.$channel,
                'title' => (string) $offer->title_ar,
                'body' => (string) ($offer->body_ar ?? ''),
                'title_ar' => (string) $offer->title_ar,
                'title_en' => (string) $offer->title_en,
                'body_ar' => (string) ($offer->body_ar ?? ''),
                'body_en' => (string) ($offer->body_en ?? ''),
                'audience' => $isCustomer ? 'customer' : 'van',
                'app' => $isCustomer ? 'customer' : 'van',
                'target_channel' => $isCustomer ? 'b2c' : 'b2b',
                'store_id' => (int) $offer->store_id,
                'created_by' => $offer->created_by,
                'status' => 'published',
                'published_at' => now(),
                'data' => [
                    'flash_offer_id' => $offerId,
                    'store_id' => (int) $offer->store_id,
                    'deep_link' => '/offers/flash/'.$offerId,
                    'popup' => $isCustomer,
                    'popup_frequency' => $isCustomer ? (string) $offer->popup_frequency : 'never',
                    'server_time' => now()->toAtomString(),
                    'ends_at' => $offer->endsAt()->toAtomString(),
                ],
            ]);

            $eventId = (int) DB::table('flash_offer_events')->insertGetId([
                'flash_offer_id' => $offerId,
                'flash_reservation_id' => null,
                'user_id' => null,
                'event' => $eventName,
                'channel' => $channel,
                'metadata' => json_encode([
                    'notification_id' => (int) $notification->getKey(),
                    'delivery_state' => 'pending',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['notification' => $notification, 'event_id' => $eventId];
        }, 3);

        if ($pending === null) {
            return false;
        }

        try {
            $this->push->dispatchNotification($pending['notification'], true);
        } catch (Throwable $exception) {
            throw new RuntimeException('Flash offer push delivery failed transiently.', 0, $exception);
        }

        DB::table('flash_offer_events')->where('id', $pending['event_id'])->update([
            'metadata' => json_encode([
                'notification_id' => (int) $pending['notification']->getKey(),
                'delivery_state' => 'dispatched',
                'dispatched_at' => now()->toAtomString(),
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        return true;
    }
}
