<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\ConversationService;
use App\Domain\WhatsApp\InboundMessageAlertService;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppInboundAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_persisted_inbound_message_creates_one_durable_alert_for_assigned_agent(): void
    {
        $this->enableWhatsApp();
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Popup Agent');
        $conversation = $this->conversation($agent->id, null, '+96550000011');

        $message = app(ConversationService::class)->appendInbound(
            $conversation,
            'Please update my delivery address',
            [],
            null,
            null,
            now(),
            'text',
        );

        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $message,
            'conversation_id' => $conversation,
            'direction' => 'inbound',
            'delivery_status' => 'received',
        ]);
        $this->assertDatabaseHas('whats_app_inbound_alerts', [
            'message_id' => $message,
            'conversation_id' => $conversation,
            'recipient_user_id' => $agent->id,
            'state' => 'pending',
        ]);

        app(InboundMessageAlertService::class)->recordForPersistedMessage($message);
        app(InboundMessageAlertService::class)->recordForPersistedMessage($message);

        $this->assertSame(
            1,
            DB::table('whats_app_inbound_alerts')
                ->where('message_id', $message)
                ->where('recipient_user_id', $agent->id)
                ->count(),
        );
    }

    public function test_assigned_agent_can_poll_safe_preview_and_unrelated_user_cannot_retrieve_it(): void
    {
        $this->enableWhatsApp();
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Popup Agent');
        $other = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Other Agent');
        $conversation = $this->conversation($agent->id, null, '+96550000012');

        app(ConversationService::class)->appendInbound(
            $conversation,
            '<b>Hello</b>   from customer',
            [],
            null,
            null,
            now(),
            'text',
        );

        $response = $this->actingAs($agent)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.conversation_id', $conversation)
            ->assertJsonPath('data.0.customer_phone', '+96550000012')
            ->assertJsonPath('data.0.preview', 'Hello from customer');

        $this->assertStringContainsString(
            'conversation='.$conversation,
            (string) $response->json('data.0.url'),
        );

        $this->actingAs($other)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_dismiss_and_open_acknowledge_only_dashboard_alert_state(): void
    {
        $this->enableWhatsApp();
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Ack Agent');
        $conversation = $this->conversation($agent->id, null, '+96550000013');
        $message = app(ConversationService::class)->appendInbound(
            $conversation,
            'First alert',
            [],
            null,
            null,
            now(),
            'text',
        );
        $alert = (int) DB::table('whats_app_inbound_alerts')
            ->where('message_id', $message)
            ->value('id');

        $this->actingAs($agent)
            ->patchJson(route('admin.whatsapp-inbound-alerts.dismiss', $alert))
            ->assertOk();

        $this->assertDatabaseHas('whats_app_inbound_alerts', [
            'id' => $alert,
            'state' => 'dismissed',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $message,
            'delivery_status' => 'received',
        ]);
        $this->assertDatabaseHas('whats_app_conversations', [
            'id' => $conversation,
            'status' => 'WAITING_FOR_AGENT',
        ]);
        $this->actingAs($agent)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $message2 = app(ConversationService::class)->appendInbound(
            $conversation,
            'Second alert',
            [],
            null,
            null,
            now()->addSecond(),
            'text',
        );
        $alert2 = (int) DB::table('whats_app_inbound_alerts')
            ->where('message_id', $message2)
            ->value('id');

        $this->actingAs($agent)
            ->patchJson(route('admin.whatsapp-inbound-alerts.open', $alert2))
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversation);

        $this->assertDatabaseHas('whats_app_inbound_alerts', [
            'id' => $alert2,
            'state' => 'opened',
        ]);
        $this->assertDatabaseHas('whats_app_messages', [
            'id' => $message2,
            'delivery_status' => 'received',
        ]);
    }

    public function test_reassignment_before_delivery_suppresses_stale_recipient(): void
    {
        $this->enableWhatsApp();
        $first = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'First Agent');
        $second = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Second Agent');
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Supervisor');
        $conversation = $this->conversation($first->id, null, '+96550000014');

        $message = app(ConversationService::class)->appendInbound(
            $conversation,
            'Do not leak after reassignment',
            [],
            null,
            null,
            now(),
            'text',
        );
        $alert = (int) DB::table('whats_app_inbound_alerts')
            ->where('message_id', $message)
            ->value('id');

        foreach ([$first, $second] as $user) {
            DB::table('whats_app_agent_profiles')->insert([
                'user_id' => $user->id,
                'status' => 'ONLINE',
                'capacity' => 20,
                'accepts_new' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(ConversationService::class)->assign(
            $conversation,
            $supervisor,
            $second->id,
            1,
            'handoff',
        );

        $this->actingAs($first)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('whats_app_inbound_alerts', [
            'id' => $alert,
            'state' => 'suppressed',
        ]);
    }

    public function test_wrong_store_recipient_is_rechecked_and_suppressed(): void
    {
        $this->enableWhatsApp();
        $storeA = $this->store('B2C', 'WA-POP-A');
        $storeB = $this->store('B2C', 'WA-POP-B');
        $wrongStoreUser = $this->storeUser(
            'RETAIL_CUSTOMER_SUPPORT',
            $storeB,
            'Wrong Store',
        );
        $conversation = $this->conversation(null, $storeA, '+96550000015');

        $message = (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversation,
            'direction' => 'inbound',
            'content_type' => 'text',
            'actor_type' => 'customer',
            'body' => 'Protected store message',
            'delivery_status' => 'received',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $alert = (int) DB::table('whats_app_inbound_alerts')->insertGetId([
            'message_id' => $message,
            'conversation_id' => $conversation,
            'recipient_user_id' => $wrongStoreUser->id,
            'state' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($wrongStoreUser)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('whats_app_inbound_alerts', [
            'id' => $alert,
            'state' => 'suppressed',
        ]);
    }

    public function test_media_preview_is_localized_and_multiple_messages_are_chronological(): void
    {
        $this->enableWhatsApp();
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Media Agent');
        $conversation = $this->conversation($agent->id, null, '+96550000016');

        app(ConversationService::class)->appendInbound(
            $conversation,
            null,
            ['provider_media_id' => 'opaque'],
            null,
            null,
            now()->subSecond(),
            'image',
        );
        app(ConversationService::class)->appendInbound(
            $conversation,
            'Later text',
            [],
            null,
            null,
            now(),
            'text',
        );

        $this->actingAs($agent)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.preview', 'Photo received')
            ->assertJsonPath('data.1.preview', 'Later text');

        $agent->forceFill(['locale' => 'ar'])->save();
        DB::table('whats_app_inbound_alerts')
            ->where('recipient_user_id', $agent->id)
            ->delete();

        app(ConversationService::class)->appendInbound(
            $conversation,
            null,
            ['provider_media_id' => 'opaque-2'],
            null,
            null,
            now()->addSecond(),
            'audio',
        );

        $this->actingAs($agent)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertOk()
            ->assertJsonPath('data.0.preview', 'تم استلام رسالة صوتية');
    }

    public function test_disabled_lifecycle_creates_no_actionable_alert(): void
    {
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Disabled Agent');
        $conversation = $this->conversation($agent->id, null, '+96550000017');

        app(ConversationService::class)->appendInbound(
            $conversation,
            'Persisted by direct domain call while disabled',
            [],
            null,
            null,
            now(),
            'text',
        );

        $this->assertSame(0, DB::table('whats_app_inbound_alerts')->count());

        $this->actingAs($agent)
            ->getJson(route('admin.whatsapp-inbound-alerts.feed'))
            ->assertNotFound();
    }

    public function test_alert_migration_is_additive_and_rolls_back_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('whats_app_inbound_alerts'));

        $migration = require database_path(
            'migrations/2026_10_14_123000_create_whatsapp_inbound_alerts_table.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('whats_app_inbound_alerts'));
        $this->assertTrue(Schema::hasTable('whats_app_messages'));
        $this->assertTrue(Schema::hasTable('orders'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('whats_app_inbound_alerts'));
    }

    private function enableWhatsApp(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => [
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => [],
        ])->save();
    }

    private function globalUser(string $roleCode, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', $roleCode)->firstOrFail(),
        );

        return $user;
    }

    private function storeUser(string $roleCode, int $storeId, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')
            ->where('code', $channel)
            ->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conversation(?int $agentId, ?int $storeId, string $phone): int
    {
        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => null,
            'external_participant_phone' => $phone,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => $agentId === null ? 'WAITING_FOR_AGENT' : 'OPEN',
            'primary_agent_id' => $agentId,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => $agentId === null ? now() : null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
