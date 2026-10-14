<?php

namespace App\Domain\WhatsApp;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class WebhookIngress
{
    public function __construct(
        private readonly RuntimePolicy $policy,
        private readonly ConnectionHealthRecorder $health,
    ) {}

    /** @param array<string, mixed> $payload */
    public function capture(
        WhatsAppConnectionProfile $profile,
        array $payload,
        string $rawPayload,
    ): ?int {
        if (! $this->policy->allowsInbound() || ! $this->acceptsInbound($profile)) {
            return null;
        }

        $hash = hash('sha256', $rawPayload);

        try {
            $eventId = (int) DB::table('whats_app_webhook_events')->insertGetId([
                'connection_profile_id' => $profile->getKey(),
                'event_hash' => $hash,
                'event_type' => isset($payload['object']) ? mb_substr((string) $payload['object'], 0, 64) : null,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'received',
                'attempts' => 0,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = DB::table('whats_app_webhook_events')
                ->where('connection_profile_id', $profile->getKey())
                ->where('event_hash', $hash)
                ->value('id');

            if ($existing === null) {
                throw $exception;
            }

            return (int) $existing;
        }

        $this->health->webhookSucceeded($profile);
        ProcessWhatsAppWebhook::dispatch($eventId)
            ->onQueue((string) config('whatsapp.transport.queue', 'whatsapp'));

        return $eventId;
    }

    private function acceptsInbound(WhatsAppConnectionProfile $profile): bool
    {
        $status = (string) $profile->getRawOriginal('status');

        return $profile->is_primary
            || $profile->receive_only
            || in_array($status, [
                ConnectionProfileStatus::ACTIVE->value,
                ConnectionProfileStatus::RECEIVE_ONLY->value,
            ], true);
    }
}
