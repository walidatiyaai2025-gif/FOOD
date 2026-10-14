<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\MetaCloudWhatsAppProvider;
use App\Domain\WhatsApp\OutboundMessage;
use App\Domain\WhatsApp\OutboundMessageDispatcher;
use App\Domain\WhatsApp\TransportRetryService;
use App\Domain\WhatsApp\WhatsAppCallingService;
use App\Domain\WhatsApp\WhatsAppMediaService;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppCall;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        $unconfirmed = $provider->validate($profile);
        $this->assertFalse($unconfirmed->valid);
        $this->assertSame(ConnectionHealthStatus::WEBHOOK_UNCONFIRMED, $unconfirmed->health);

        $verifyUrl = route('webhooks.whatsapp.meta.verify', $profile);
        $this->get($verifyUrl.'?hub.mode=subscribe&hub.verify_token=verify-token&hub.challenge=ready')
            ->assertOk()
            ->assertSeeText('ready');

        $profile->refresh();
        $this->assertNotNull($profile->last_webhook_at);
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

    public function test_meta_media_upload_produces_provider_media_id_for_message_send(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('whatsapp-media/outbound/1/photo.jpg', 'jpeg-bytes');
        $profile = $this->profile();

        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/v26.0/phone-meta-1/media')) {
                return Http::response(['id' => 'meta-media-1'], 200);
            }

            if (str_ends_with($request->url(), '/v26.0/phone-meta-1/messages')) {
                return Http::response(['messages' => [['id' => 'wamid.media-1']]], 200);
            }

            return Http::response([], 404);
        });

        $metadata = [
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'filename' => 'photo.jpg',
            'local_disk' => 'local',
            'local_path' => 'whatsapp-media/outbound/1/photo.jpg',
        ];
        $metadata['media_id'] = app(WhatsAppMediaService::class)->uploadToMeta($profile, $metadata);

        $sent = app(MetaCloudWhatsAppProvider::class)->send(
            $profile,
            new OutboundMessage(
                recipient: '+96550000111',
                kind: 'media',
                body: 'Product photo',
                metadata: $metadata,
                idempotencyKey: 'wa-media-test-1',
            ),
        );

        $this->assertSame('wamid.media-1', $sent->providerMessageId);
        Http::assertSentCount(2);
        Http::assertSent(function (HttpRequest $request): bool {
            return str_ends_with($request->url(), '/v26.0/phone-meta-1/messages')
                && $request['type'] === 'image'
                && $request['image']['id'] === 'meta-media-1'
                && $request['image']['caption'] === 'Product photo';
        });
    }

    public function test_inbound_meta_attachment_is_downloaded_once_into_private_storage(): void
    {
        Storage::fake('local');
        $profile = $this->profile();
        $storeId = $this->store('B2C', 'WA-MEDIA-IN');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $conversationId = $this->conversation($customerId, $storeId);

        $messageId = (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => $profile->id,
            'direction' => 'inbound',
            'content_type' => 'image',
            'actor_type' => 'customer',
            'actor_user_id' => null,
            'body' => 'Incoming photo',
            'attachment_metadata' => json_encode([
                'media_type' => 'image',
                'id' => 'meta-inbound-media-1',
                'mime_type' => 'image/jpeg',
                'filename' => 'customer-photo.jpg',
            ], JSON_THROW_ON_ERROR),
            'provider_message_ref' => 'wamid.media-inbound-1',
            'delivery_status' => 'received',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/v26.0/meta-inbound-media-1')) {
                return Http::response([
                    'url' => 'https://lookaside.facebook.com/whatsapp/media-1',
                    'mime_type' => 'image/jpeg',
                    'file_size' => 10,
                ], 200);
            }

            if ($request->url() === 'https://lookaside.facebook.com/whatsapp/media-1') {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response([], 404);
        });

        $message = DB::table('whats_app_messages')->where('id', $messageId)->firstOrFail();
        $asset = app(WhatsAppMediaService::class)->materialize($message);

        $this->assertSame('image', $asset['media_type']);
        $this->assertSame('customer-photo.jpg', $asset['filename']);
        Storage::disk('local')->assertExists($asset['path']);

        $persisted = json_decode(
            (string) DB::table('whats_app_messages')->where('id', $messageId)->value('attachment_metadata'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($asset['path'], $persisted['local_path']);

        Http::assertSentCount(2);

        $again = app(WhatsAppMediaService::class)->materialize(
            DB::table('whats_app_messages')->where('id', $messageId)->firstOrFail(),
        );
        $this->assertSame($asset['path'], $again['path']);
        Http::assertSentCount(2);
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
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'temporary']], 503)
                ->push(['messages' => [['id' => 'wamid.retry-success']]], 200),
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

        $this->assertSame(1, app(TransportRetryService::class)->dispatchDue());
        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $dispatchId,
            'status' => 'sent',
            'attempts' => 2,
            'provider_message_ref' => 'wamid.retry-success',
        ]);
        $profile = WhatsAppConnectionProfile::query()->where('is_primary', true)->firstOrFail();
        $this->assertSame(ConnectionProfileStatus::ACTIVE, $profile->status);
        $this->assertSame(ConnectionHealthStatus::READY, $profile->health_status);
        $this->assertNull($profile->last_provider_error);
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

        $statusPayload['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'delivered';
        $statusPayload['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp'] = '1791945599';
        $this->postSignedWebhook($profile, $statusPayload)->assertOk();

        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $messageId,
            'delivery_status' => 'read',
        ]);
    }

    public function test_user_initiated_voice_call_webhook_persists_offer_for_authorized_browser_answer(): void
    {
        $storeId = $this->store('B2C', 'WA-CALL-IN');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $profile = $this->profile();
        $this->enableTransport('+96550000111', inbound: true, outbound: false);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'calls',
                    'value' => [
                        'metadata' => [
                            'display_phone_number' => '+96522220000',
                            'phone_number_id' => 'phone-meta-1',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Voice Customer'],
                            'wa_id' => '96550000111',
                        ]],
                        'calls' => [[
                            'id' => 'wacid.inbound-1',
                            'from' => '96550000111',
                            'to' => '96522220000',
                            'event' => 'connect',
                            'direction' => 'USER_INITIATED',
                            'timestamp' => '1791945600',
                            'session' => [
                                'sdp_type' => 'offer',
                                'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postSignedWebhook($profile, $payload)->assertOk();

        $call = WhatsAppCall::query()->where('provider_call_id', 'wacid.inbound-1')->firstOrFail();
        $this->assertSame('USER_INITIATED', $call->direction);
        $this->assertSame('INCOMING', $call->status);
        $this->assertSame('offer', $call->remote_sdp_type);
        $this->assertStringContainsString('m=audio', (string) $call->remote_sdp);
        $this->assertSame($customerId, (int) $call->platform_customer_id);
        $this->assertNotNull($call->conversation_id);
    }

    public function test_business_initiated_voice_call_checks_permission_and_sends_webrtc_offer(): void
    {
        $storeId = $this->store('B2C', 'WA-CALL-OUT');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $profile = $this->profile();
        $this->enableTransport('+96550000111', inbound: true, outbound: true);
        $conversationId = $this->conversation($customerId, $storeId);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/call_permissions')) {
                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'permission' => ['status' => 'temporary', 'expiration_time' => 1792550400],
                    'actions' => [
                        ['action_name' => 'start_call', 'can_perform_action' => true],
                        ['action_name' => 'send_call_permission_request', 'can_perform_action' => false],
                    ],
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/calls')) {
                return Http::response([
                    'messaging_product' => 'whatsapp',
                    'calls' => [['id' => 'wacid.outbound-1']],
                ], 200);
            }

            return Http::response([], 404);
        });

        $call = app(WhatsAppCallingService::class)->startOutbound(
            $conversationId,
            $admin,
            "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
        );

        $this->assertSame('wacid.outbound-1', $call->provider_call_id);
        $this->assertSame('BUSINESS_INITIATED', $call->direction);
        $this->assertSame('DIALING', $call->status);
        $this->assertSame($profile->id, (int) $call->connection_profile_id);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/v26.0/phone-meta-1/calls')
                && $request['action'] === 'connect'
                && $request['to'] === '96550000111'
                && $request['session']['sdp_type'] === 'offer'
                && str_contains((string) $request['session']['sdp'], 'm=audio');
        });
    }

    public function test_call_permission_request_uses_meta_interactive_permission_message(): void
    {
        $storeId = $this->store('B2C', 'WA-CALL-PERMISSION');
        $customerId = $this->customer($storeId, 'b2c', '+96550000111');
        $this->profile();
        $this->enableTransport('+96550000111', inbound: true, outbound: true);
        $conversationId = $this->conversation($customerId, $storeId);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/call_permissions')) {
                return Http::response([
                    'permission' => ['status' => 'no_permission'],
                    'actions' => [
                        ['action_name' => 'start_call', 'can_perform_action' => false],
                        ['action_name' => 'send_call_permission_request', 'can_perform_action' => true],
                    ],
                ], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($request->url(), '/messages')) {
                return Http::response(['messages' => [['id' => 'wamid.permission-1']]], 200);
            }

            return Http::response([], 404);
        });

        $result = app(WhatsAppCallingService::class)->requestPermission(
            $conversationId,
            $admin,
            'May FOODEX call you about your order?',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('wamid.permission-1', $result['provider_message_id']);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/v26.0/phone-meta-1/messages')
                && $request['type'] === 'interactive'
                && $request['interactive']['type'] === 'call_permission_request'
                && $request['interactive']['action']['name'] === 'call_permission_request';
        });
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
                'calls' => true,
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
