<?php

namespace App\Domain\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Support\Facades\DB;

final class OutboundMessageDispatcher
{
    public function __construct(private readonly RuntimePolicy $policy) {}

    public function queue(int $messageId): ?int
    {
        return DB::transaction(function () use ($messageId): ?int {
            $message = DB::table('whats_app_messages')
                ->where('id', $messageId)
                ->where('direction', 'outbound')
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return null;
            }

            $existing = DB::table('whats_app_outbound_dispatches')
                ->where('message_id', $messageId)
                ->first();
            if ($existing !== null) {
                return (int) $existing->id;
            }

            $conversation = DB::table('whats_app_conversations')->where('id', $message->conversation_id)->first();
            if ($conversation === null) {
                return null;
            }

            $recipient = $this->recipient($conversation);
            if ($recipient === null) {
                DB::table('whats_app_messages')->where('id', $messageId)->update([
                    'delivery_status' => 'blocked_recipient',
                    'updated_at' => now(),
                ]);

                return null;
            }

            $profile = WhatsAppConnectionProfile::query()
                ->where('is_primary', true)
                ->where('status', ConnectionProfileStatus::ACTIVE->value)
                ->first();

            if (! $profile instanceof WhatsAppConnectionProfile) {
                DB::table('whats_app_messages')->where('id', $messageId)->update([
                    'delivery_status' => 'blocked_no_profile',
                    'updated_at' => now(),
                ]);

                return null;
            }

            $metadata = $this->decodeMetadata($message->attachment_metadata);
            $kind = isset($metadata['template_name'])
                ? 'template'
                : (isset($metadata['media_type']) ? 'media' : 'text');
            $payload = [
                'kind' => $kind,
                'body' => $message->body,
                'metadata' => $metadata,
            ];

            $purpose = $this->purpose($metadata);
            $allowed = $this->policy->allowsOutbound($recipient, $purpose);
            $deliveryStatus = $allowed ? 'queued' : 'blocked_policy';

            $dispatchId = (int) DB::table('whats_app_outbound_dispatches')->insertGetId([
                'message_id' => $messageId,
                'connection_profile_id' => $profile->getKey(),
                'idempotency_key' => 'wa-message-'.$messageId,
                'recipient' => $recipient,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $deliveryStatus,
                'attempts' => 0,
                'next_retry_at' => $allowed ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('whats_app_messages')->where('id', $messageId)->update([
                'connection_profile_id' => $profile->getKey(),
                'delivery_status' => $deliveryStatus,
                'updated_at' => now(),
            ]);

            if ($allowed) {
                DB::afterCommit(static function () use ($dispatchId): void {
                    SendWhatsAppMessage::dispatch($dispatchId)
                        ->onQueue((string) config('whatsapp.transport.queue', 'whatsapp'));
                });
            }

            return $dispatchId;
        }, 3);
    }

    private function recipient(object $conversation): ?string
    {
        $phone = $conversation->external_participant_phone ?? null;

        if ($conversation->platform_customer_id !== null) {
            $phone = DB::table('platform_customers')
                ->where('id', $conversation->platform_customer_id)
                ->value('phone') ?? $phone;
        }

        if (! is_string($phone) && ! is_numeric($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? null : '+'.$digits;
    }

    /** @param array<string, mixed> $metadata */
    private function purpose(array $metadata): Capability
    {
        return match (strtolower(trim((string) ($metadata['purpose'] ?? '')))) {
            'transactional' => Capability::TRANSACTIONAL,
            'campaigns' => Capability::CAMPAIGNS,
            'automation' => Capability::AUTOMATION,
            default => Capability::OUTBOUND,
        };
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
