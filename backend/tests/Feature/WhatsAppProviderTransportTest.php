<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\MetaCloudWhatsAppProvider;
use App\Domain\WhatsApp\OutboundMessage;
use App\Domain\WhatsApp\OutboundMessageDispatcher;
use App\Domain\WhatsApp\TransportRetryService;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WhatsAppProviderTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_meta_provider_validates_and_sends_with_encrypted_profile_credentials(): void
    {
        $profile = $this->profile();

        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 'phone-meta-1',
                    'display_phone_number' => '+96522220000',
                    'verified_name' => 'FOODEX QA',
                ], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.outbound-1']]], 200);
        });

        $provider = app(MetaCloudWhatsAppProvider::class);
        $validation = $provider->validate($profile);

        $this->assertTrue($validation->valid);
        $this->assertSame(ConnectionHealthStatus::READY, $validation->health);
        $this->assertContains('webhooks', $validation->capabilities);

        $sent = $provider->send($profile, new OutboundMessage(
            recipient: '+96550000111',
            kind: 'text',
            body: 'Hello from FOODEX',
            metadata: [],
            idempotencyKey: 'wa-test-1',
        ));

        $this->assertSame('wamid.outbound-1', $sent->providerMessageId);
        Http::assertSent(function (HttpRequest $request): bool {
            return str_contains($request->url(), '/v26.0/phone-meta-1')
                && $request->hasHeader('Authorization', 'Bearer meta-access-token');
        });
        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/v26.0/phone-meta-1/messages')
                && $request['messaging_product'] === 'whatsapp'
                && $request['type'] === 'text'
                && $request['to'] === '96550000111';
        });

        $raw = DB::table('whats_app_connection_profiles')->where('id', $profile->id)->first();
        $this->assertNotSame('meta-access-token', $raw->credential_secret);
        $this->assertNotSame('verify-token', $raw->webhook_verify_token);
        $this->assertNotSame('signing-secret', $raw->webhook_signing_secret);
        $this->assertArrayNotHasKey('credential_secret', $profile->toArray());
        $this->assertArrayNotHasKey('webhook_verify_token', $profile->toArray());
        $this->assertArrayNotHasKey('webhook_signing_secret', $profile->toArray());
    }

    public function test_webhook_verification_signature_and_duplicate_ingestion_are_fail_closed_and_idempotent(): void
    {
        $storeId = $this->store('B2C', 'WA-WEBHOOK');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $profile = $this->profile();
        $this->enableTransport('+96550000111', inbound: true, outbound: false);

        $verifyUrl = route('webhooks.whatsapp.meta.verify', $profile);
        $this->get($verifyUrl.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc')
            ->assertForbidden();

        $this->get($verifyUrl.'?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSeeText('challenge-123');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '+96522220000',
                            'phone_number_id' => 'phone-meta-1',
                        ],
                        'messages' => [[
                            'from' => '96550000111',
                            'id' => 'wamid.inbound-1',
                            'timestamp' => '1791945600',
                            'type' => 'text',
                            'text' => ['body' => 'Inbound hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $url = route('webhooks.whatsapp.meta.ingest', $profile);

        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad',
        ], $raw)->assertUnauthorized();

        $signature = 'sha256='.hash_hmac('sha256', $raw, 'signing-secret');
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', $url, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ], $raw)->assertOk()->assertJson(['accepted' => true]);
        }

        $this->assertSame(1, DB::table('whats_app_webhook_events')->count());
        $this->assertDatabaseHas('whats_app_webhook_events', [
            'connection_profile_id' => $profile->id,
            'status' => 'processed',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'connection_profile_id' => $profile->id,
            'provider_message_ref' => 'wamid.inbound-1',
            'direction' => 'inbound',
            'content_type' => 'text',
            'body' => 'Inbound hello',
        ]);
        $this->assertSame(
            1,
            DB::table('whats_app_messages')->where('provider_message_ref', 'wamid.inbound-1')->count(),
        );
        $this->assertDatabaseHas('whats_app_conversations', [
            'platform_customer_id' => $customerId,
            'store_id' => $storeId,
        ]);
        $this->assertNotNull($profile->fresh()->last_webhook_at);
    }

    public function test_unknown_inbound_number_is_retained_without_fabricating_a_food_customer(): void
    {
        $profile = $this->profile();
        $this->enableTransport('+96559999999', inbound: true, outbound: false);

        $payload = $this->messageWebhook('96559999999', 'wamid.unknown-1', 'Unknown customer');
        $this->postSignedWebhook($profile, $payload)->assertOk();

        $conversation = DB::table('whats_app_conversations')->first();
        $this->assertNotNull($conversation);
        $this->assertNull($conversation->platform_customer_id);
        $this->assertSame('+96559999999', $conversation->external_participant_phone);
        $this->assertDatabaseHas('whats_app_messages', [
            'conversation_id' => $conversation->id,
            'provider_message_ref' => 'wamid.unknown-1',
        ]);
    }

    public function test_outbound_dispatch_is_one_logical_send_and_duplicate_queue_requests_do_not_resend(): void
    {
        $storeId = $this->store('B2C', 'WA-OUTBOUND');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $profile = $this->profile();
        $this->enableTransport('+96550000111', inbound: false, outbound: true);
        $conversationId = $this->conversation($customerId, $storeId);
        $messageId = $this->outboundMessage($conversationId, 'Queued hello');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.outbound-once']],
            ], 200),
        ]);

        $dispatcher = app(OutboundMessageDispatcher::class);
        $dispatchId = $dispatcher->queue($messageId);
        $this->assertNotNull($dispatchId);

        $sameDispatch = $dispatcher->queue($messageId);
        $this->assertSame($dispatchId, $sameDispatch);
        Http::assertSentCount(1);

        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $dispatchId,
            'message_id' => $messageId,
            'connection_profile_id' => $profile->id,
            'status' => 'sent',
            'attempts' => 1,
            'provider_message_ref' => 'wamid.outbound-once',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $messageId,
            'connection_profile_id' => $profile->id,
            'delivery_status' => 'sent',
            'provider_message_ref' => 'wamid.outbound-once',
        ]);
        $this->assertSame(1, DB::table('whats_app_messages')->where('id', $messageId)->count());
    }

    public function test_provider_outage_degrades_whatsapp_only_and_bounded_retry_does_not_duplicate_message(): void
    {
        $storeId = $this->store('B2C', 'WA-RETRY');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $this->profile();
        $this->enableTransport('+96550000111', inbound: false, outbound: true);
        $messageId = $this->outboundMessage(
            $this->conversation($customerId, $storeId),
            'Retry safely',
        );

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'temporary']], 503),
        ]);

        $dispatchId = app(OutboundMessageDispatcher::class)->queue($messageId);
        $this->assertNotNull($dispatchId);
        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $dispatchId,
            'status' => 'retry_wait',
            'attempts' => 1,
        ]);
        $this->get('/health')->assertOk();

        DB::table('whats_app_outbound_dispatches')->where('id', $dispatchId)->update([
            'next_retry_at' => now()->subSecond(),
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.retry-success']],
            ], 200),
        ]);

        $this->assertSame(1, app(TransportRetryService::class)->dispatchDue());
        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $dispatchId,
            'status' => 'sent',
            'attempts' => 2,
            'provider_message_ref' => 'wamid.retry-success',
        ]);
        $this->assertSame(1, DB::table('whats_app_messages')->where('id', $messageId)->count());
        $this->get('/health')->assertOk();
    }

    public function test_disabled_module_produces_zero_meta_outbound_traffic_even_with_active_profile(): void
    {
        $storeId = $this->store('B2C', 'WA-DISABLED');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $this->profile();
        $messageId = $this->outboundMessage(
            $this->conversation($customerId, $storeId),
            'Must never leave FOOD',
        );

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'must-not-happen']],
            ], 200),
        ]);

        $dispatchId = app(OutboundMessageDispatcher::class)->queue($messageId);
        $this->assertNotNull($dispatchId);
        Http::assertNothingSent();
        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $dispatchId,
            'status' => 'blocked_policy',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $messageId,
            'delivery_status' => 'blocked_policy',
        ]);
    }

    public function test_delivery_status_webhook_updates_exact_profile_message(): void
    {
        $storeId = $this->store('B2C', 'WA-STATUS');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $profile = $this->profile();
        $this->enableTransport('+96550000111', inbound: true, outbound: true);
        $messageId = $this->outboundMessage(
            $this->conversation($customerId, $storeId),
            'Status me',
        );

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.status-1']],
            ], 200),
        ]);
        app(OutboundMessageDispatcher::class)->queue($messageId);

        $statusPayload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['display_phone_number' => '+96522220000'],
                        'statuses' => [[
                            'id' => 'wamid.status-1',
                            'status' => 'read',
                            'timestamp' => '1791945601',
                            'recipient_id' => '96550000111',
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postSignedWebhook($profile, $statusPayload)->assertOk();
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $messageId,
            'connection_profile_id' => $profile->id,
            'provider_message_ref' => 'wamid.status-1',
            'delivery_status' => 'read',
        ]);
    }

    public function test_transport_migration_is_additive_and_round_trip_safe(): void
    {
        foreach (['whats_app_webhook_events', 'whats_app_outbound_dispatches'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumns('whats_app_connection_profiles', [
            'webhook_verify_token',
            'webhook_signing_secret',
        ]));
        $this->assertTrue(Schema::hasColumns('whats_app_messages', [
            'connection_profile_id',
            'content_type',
        ]));

        $migration = require database_path(
            'migrations/2026_10_14_094500_create_whatsapp_transport_foundation.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('whats_app_webhook_events'));
        $this->assertFalse(Schema::hasTable('whats_app_outbound_dispatches'));
        $this->assertFalse(Schema::hasColumn('whats_app_messages', 'connection_profile_id'));
        $this->assertTrue(Schema::hasTable('whats_app_messages'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('whats_app_webhook_events'));
        $this->assertTrue(Schema::hasTable('whats_app_outbound_dispatches'));
        $this->assertTrue(Schema::hasColumn('whats_app_messages', 'connection_profile_id'));
    }

    private function profile(): WhatsAppConnectionProfile
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'Meta main',
            'display_phone_number' => '+96522220000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-meta-1',
            'provider_account_id' => 'waba-meta-1',
            'provider_app_id' => 'app-meta-1',
            'provider_metadata' => ['graph_version' => 'v26.0'],
            'webhook_metadata' => [],
            'validation_metadata' => null,
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

    private function enableTransport(string $number, bool $inbound, bool $outbound): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'inbound' => $inbound,
                'agent_inbox' => true,
                'outbound' => $outbound,
            ],
            'test_numbers' => $outbound ? [$number] : [],
        ])->save();
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $phone): int
    {
        $user = User::factory()->create([
            'name' => 'WhatsApp Customer',
            'email' => 'wa-'.Str::uuid().'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => 'WhatsApp Customer',
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => 'WhatsApp Customer',
            'phone' => $phone,
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conversation(int $customerId, int $storeId): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customerId,
            'external_participant_phone' => null,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'OPEN',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outboundMessage(int $conversationId, string $body): int
    {
        return (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => null,
            'direction' => 'outbound',
            'content_type' => 'text',
            'actor_type' => 'agent',
            'actor_user_id' => null,
            'body' => $body,
            'attachment_metadata' => null,
            'provider_message_ref' => null,
            'delivery_status' => 'pending_transport',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function messageWebhook(string $from, string $id, string $body): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['display_phone_number' => '+96522220000'],
                        'messages' => [[
                            'from' => $from,
                            'id' => $id,
                            'timestamp' => '1791945600',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postSignedWebhook(
        WhatsAppConnectionProfile $profile,
        array $payload,
    ): TestResponse {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'signing-secret');

        return $this->call(
            'POST',
            route('webhooks.whatsapp.meta.ingest', $profile),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $raw,
        );
    }
}
