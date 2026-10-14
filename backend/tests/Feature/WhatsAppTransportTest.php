<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\ConnectionHealthRecorder;
use App\Domain\WhatsApp\OutboundExecution;
use App\Domain\WhatsApp\OutboundMessageDispatcher;
use App\Domain\WhatsApp\WebhookIngress;
use App\Domain\WhatsApp\WhatsAppProviderRegistry;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
    }

    private function profile(string $number = '+96555555555'): WhatsAppConnectionProfile
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'Meta transport test',
            'display_phone_number' => $number,
            'normalized_phone_number' => $number,
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-id-1',
            'provider_account_id' => 'waba-test',
            'provider_app_id' => 'app-test',
            'status' => ConnectionProfileStatus::ACTIVE,
            'health_status' => ConnectionHealthStatus::READY,
            'is_primary' => true,
            'receive_only' => false,
            'primary_slot' => 1,
            'configuration_revision' => 1,
            'last_validated_at' => now(),
        ]);
        $profile->credential_secret = 'meta-access-token';
        $profile->webhook_verify_token = 'verify-token';
        $profile->webhook_signing_secret = 'signing-secret';
        $profile->save();

        return $profile->refresh();
    }

    private function enableInternalTest(string $number = '+96555555555'): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => array_fill_keys(array_column(Capability::cases(), 'value'), true),
            'test_numbers' => [$number],
            'revision' => 1,
        ])->save();
    }

    public function test_duplicate_webhook_is_captured_once_and_dispatched_once(): void
    {
        Queue::fake();
        $this->enableInternalTest();
        $profile = $this->profile();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $first = app(WebhookIngress::class)->capture($profile, $payload, $raw);
        $second = app(WebhookIngress::class)->capture($profile, $payload, $raw);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('whats_app_webhook_events')->count());
        Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
    }

    public function test_invalid_webhook_signature_is_rejected_without_persisting_event(): void
    {
        Queue::fake();
        $this->enableInternalTest();
        $profile = $this->profile();
        $raw = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('webhooks.whatsapp.meta.ingest', $profile),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
            ],
            $raw,
        );

        $response->assertUnauthorized();
        $this->assertSame(0, DB::table('whats_app_webhook_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_outbound_dispatch_is_idempotent_and_disabled_policy_never_calls_meta(): void
    {
        Queue::fake();
        $profile = $this->profile();
        $now = now();

        $conversationId = (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => null,
            'external_participant_phone' => '+96555555555',
            'store_id' => null,
            'channel' => 'whatsapp',
            'status' => 'OPEN',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $messageId = (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => null,
            'direction' => 'outbound',
            'content_type' => 'text',
            'actor_type' => 'agent',
            'actor_user_id' => null,
            'body' => 'hello',
            'attachment_metadata' => null,
            'provider_message_ref' => null,
            'delivery_status' => 'pending_transport',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dispatcher = app(OutboundMessageDispatcher::class);
        $first = $dispatcher->queue($messageId);
        $second = $dispatcher->queue($messageId);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('whats_app_outbound_dispatches')->count());
        Queue::assertPushed(SendWhatsAppMessage::class, 1);

        (new SendWhatsAppMessage((int) $first))->handle(
            app(WhatsAppProviderRegistry::class),
            app(OutboundExecution::class),
            app(ConnectionHealthRecorder::class),
        );

        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $first,
            'message_id' => $messageId,
            'connection_profile_id' => $profile->getKey(),
            'idempotency_key' => 'wa-message-'.$messageId,
            'status' => 'blocked_policy',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $messageId,
            'connection_profile_id' => $profile->getKey(),
            'delivery_status' => 'blocked_policy',
        ]);
        Http::assertNothingSent();
    }
}
