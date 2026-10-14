<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WebhookProcessor
{
    public function __construct(
        private readonly WhatsAppProviderRegistry $providers,
        private readonly ConversationService $conversations,
    ) {}

    public function process(int $eventId): void
    {
        $row = DB::table('whats_app_webhook_events')->where('id', $eventId)->first();
        if ($row === null || $row->status === 'processed' || $row->status === 'failed') {
            return;
        }

        $attempt = ((int) $row->attempts) + 1;
        DB::table('whats_app_webhook_events')->where('id', $eventId)->update([
            'status' => 'processing',
            'attempts' => $attempt,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        try {
            $profile = WhatsAppConnectionProfile::query()->findOrFail((int) $row->connection_profile_id);
            $payload = json_decode((string) $row->payload, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \RuntimeException('Invalid webhook payload.');
            }

            foreach ($this->providers->forProfile($profile)->normalizeWebhook($profile, $payload) as $event) {
                if ($event->kind === 'message') {
                    $this->applyInboundMessage($profile, $event);
                } elseif ($event->kind === 'status') {
                    $this->applyStatus($profile, $event);
                }
            }

            DB::table('whats_app_webhook_events')->where('id', $eventId)->update([
                'status' => 'processed',
                'processed_at' => now(),
                'next_retry_at' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $max = max(1, (int) config('whatsapp.transport.max_attempts', 4));
            $retry = $attempt < $max;
            $delays = (array) config('whatsapp.transport.retry_backoff_seconds', [30, 120, 300]);
            $delay = (int) ($delays[max(0, min($attempt - 1, count($delays) - 1))] ?? 300);

            DB::table('whats_app_webhook_events')->where('id', $eventId)->update([
                'status' => $retry ? 'retry_wait' : 'failed',
                'last_error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 500),
                'next_retry_at' => $retry ? now()->addSeconds(max(1, $delay)) : null,
                'updated_at' => now(),
            ]);
        }
    }

    private function applyInboundMessage(
        WhatsAppConnectionProfile $profile,
        ProviderWebhookEvent $event,
    ): void {
        if ($event->from === null) {
            return;
        }

        $digits = preg_replace('/\D+/', '', $event->from) ?? '';
        $phone = $digits === '' ? null : '+'.$digits;
        if ($phone === null) {
            return;
        }

        $customer = DB::table('platform_customers')
            ->where('is_active', true)
            ->whereIn('phone', [$phone, $digits])
            ->first();

        $customerId = $customer === null ? null : (int) $customer->id;
        $storeId = $customer?->origin_store_id === null ? null : (int) $customer->origin_store_id;

        $conversationId = $this->conversations->findOrCreateForInbound(
            $customerId,
            $storeId,
            'whatsapp',
            $phone,
        );

        $metadata = $event->metadata + [
            'provider_type' => (string) $profile->provider_type,
            'content_type' => $event->contentType ?? 'unknown',
        ];

        $this->conversations->appendInbound(
            $conversationId,
            $event->body,
            $metadata,
            $event->providerMessageId,
            (int) $profile->getKey(),
            $event->occurredAt,
            $event->contentType ?? 'unknown',
        );
    }

    private function applyStatus(
        WhatsAppConnectionProfile $profile,
        ProviderWebhookEvent $event,
    ): void {
        if ($event->providerMessageId === null || $event->deliveryStatus === null) {
            return;
        }

        DB::transaction(function () use ($profile, $event): void {
            $message = DB::table('whats_app_messages')
                ->where('provider_message_ref', $event->providerMessageId)
                ->where('connection_profile_id', $profile->getKey())
                ->first();

            if ($message === null) {
                return;
            }

            DB::table('whats_app_messages')->where('id', $message->id)->update([
                'delivery_status' => mb_substr($event->deliveryStatus, 0, 32),
                'updated_at' => now(),
            ]);

            $dispatch = DB::table('whats_app_outbound_dispatches')
                ->where('message_id', $message->id)
                ->first();

            if ($dispatch !== null && in_array($event->deliveryStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
                DB::table('whats_app_outbound_dispatches')->where('id', $dispatch->id)->update([
                    'status' => $event->deliveryStatus === 'failed' ? 'failed' : 'sent',
                    'last_error' => $event->deliveryStatus === 'failed'
                        ? 'Provider reported message delivery failure.'
                        : $dispatch->last_error,
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
