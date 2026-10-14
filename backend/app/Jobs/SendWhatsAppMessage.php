<?php

namespace App\Jobs;

use App\Domain\WhatsApp\CampaignSendPolicy;
use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ConnectionHealthRecorder;
use App\Domain\WhatsApp\OutboundExecution;
use App\Domain\WhatsApp\OutboundMessage;
use App\Domain\WhatsApp\OutboundPaused;
use App\Domain\WhatsApp\ProviderTransportException;
use App\Domain\WhatsApp\WhatsAppProviderRegistry;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $dispatchId) {}

    public function handle(
        WhatsAppProviderRegistry $providers,
        OutboundExecution $execution,
        ConnectionHealthRecorder $health,
    ): void {
        $dispatch = DB::transaction(function (): ?object {
            $row = DB::table('whats_app_outbound_dispatches')
                ->where('id', $this->dispatchId)
                ->lockForUpdate()
                ->first();

            if ($row === null || ! in_array($row->status, ['queued', 'retry_wait'], true)) {
                return null;
            }

            DB::table('whats_app_outbound_dispatches')->where('id', $row->id)->update([
                'status' => 'processing',
                'attempts' => ((int) $row->attempts) + 1,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            $row->attempts = ((int) $row->attempts) + 1;
            $row->status = 'processing';

            return $row;
        }, 3);

        if ($dispatch === null) {
            return;
        }

        $profile = WhatsAppConnectionProfile::query()->find($dispatch->connection_profile_id);
        if (! $profile instanceof WhatsAppConnectionProfile) {
            $this->terminalFailure($dispatch, 'Connection profile no longer exists.');

            return;
        }

        $payload = json_decode((string) $dispatch->payload, true);
        $payload = is_array($payload) ? $payload : [];
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        $purpose = Capability::tryFrom((string) ($metadata['purpose'] ?? ''));
        if ($purpose === null || ! $purpose->isOutbound()) {
            $purpose = Capability::OUTBOUND;
        }

        $message = new OutboundMessage(
            recipient: (string) $dispatch->recipient,
            kind: (string) ($payload['kind'] ?? 'text'),
            body: isset($payload['body']) ? (string) $payload['body'] : null,
            metadata: $metadata,
            idempotencyKey: (string) $dispatch->idempotency_key,
        );

        try {
            $result = $execution->run(
                $message->recipient,
                $purpose,
                function () use ($providers, $profile, $message, $metadata, $purpose) {
                    if ($purpose === Capability::CAMPAIGNS) {
                        $recipientId = (int) ($metadata['campaign_recipient_id'] ?? 0);
                        if ($recipientId < 1) {
                            throw new OutboundPaused('whatsapp.campaign_recipient_missing');
                        }

                        app(CampaignSendPolicy::class)->assertRecipientCanSend(
                            $recipientId,
                            $message->recipient,
                        );
                    }

                    return $providers->forProfile($profile)->send($profile, $message);
                },
            );

            DB::transaction(function () use ($dispatch, $result): void {
                DB::table('whats_app_outbound_dispatches')->where('id', $dispatch->id)->update([
                    'status' => 'sent',
                    'provider_message_ref' => $result->providerMessageId,
                    'last_error' => null,
                    'next_retry_at' => null,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('whats_app_messages')->where('id', $dispatch->message_id)->update([
                    'provider_message_ref' => $result->providerMessageId,
                    'delivery_status' => $result->status,
                    'updated_at' => now(),
                ]);
            });

            if ($purpose === Capability::CAMPAIGNS) {
                app(CampaignSendPolicy::class)->markSentByMessage((int) $dispatch->message_id);
            }

            $health->outboundSucceeded($profile);
        } catch (OutboundPaused) {
            DB::table('whats_app_outbound_dispatches')->where('id', $dispatch->id)->update([
                'status' => 'blocked_policy',
                'last_error' => 'WhatsApp outbound is paused by lifecycle policy.',
                'next_retry_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('whats_app_messages')->where('id', $dispatch->message_id)->update([
                'delivery_status' => 'blocked_policy',
                'updated_at' => now(),
            ]);
        } catch (ProviderTransportException $exception) {
            $this->providerFailure($dispatch, $profile, $health, $exception);
        } catch (Throwable) {
            $this->terminalFailure($dispatch, 'Unexpected provider transport failure.');
            $health->providerError($profile, 'Unexpected provider transport failure.');
        }
    }

    private function providerFailure(
        object $dispatch,
        WhatsAppConnectionProfile $profile,
        ConnectionHealthRecorder $health,
        ProviderTransportException $exception,
    ): void {
        $max = max(1, (int) config('whatsapp.transport.max_attempts', 4));
        $retry = $exception->retryable && ! $exception->ambiguous && (int) $dispatch->attempts < $max;
        $delays = (array) config('whatsapp.transport.retry_backoff_seconds', [30, 120, 300]);
        $index = max(0, min(((int) $dispatch->attempts) - 1, count($delays) - 1));
        $delay = (int) ($delays[$index] ?? 300);
        $message = mb_substr($exception->getMessage(), 0, 500);

        DB::table('whats_app_outbound_dispatches')->where('id', $dispatch->id)->update([
            'status' => $retry ? 'retry_wait' : 'failed',
            'last_error' => $message,
            'next_retry_at' => $retry ? now()->addSeconds(max(1, $delay)) : null,
            'updated_at' => now(),
        ]);
        DB::table('whats_app_messages')->where('id', $dispatch->message_id)->update([
            'delivery_status' => $retry ? 'retry_wait' : 'failed',
            'updated_at' => now(),
        ]);

        if (! $retry) {
            app(CampaignSendPolicy::class)->markFailedByMessage((int) $dispatch->message_id);
        }

        $health->providerError($profile, $message);
    }

    private function terminalFailure(object $dispatch, string $message): void
    {
        DB::table('whats_app_outbound_dispatches')->where('id', $dispatch->id)->update([
            'status' => 'failed',
            'last_error' => mb_substr($message, 0, 500),
            'next_retry_at' => null,
            'updated_at' => now(),
        ]);
        DB::table('whats_app_messages')->where('id', $dispatch->message_id)->update([
            'delivery_status' => 'failed',
            'updated_at' => now(),
        ]);
        app(CampaignSendPolicy::class)->markFailedByMessage((int) $dispatch->message_id);
    }
}
