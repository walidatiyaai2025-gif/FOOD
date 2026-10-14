<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\CampaignReplyRecorder;
use App\Domain\WhatsApp\CustomerTimelineService;
use App\Domain\WhatsApp\SupervisorAnalyticsService;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppSupervisorAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_supervisor_route_is_inert_when_agent_inbox_capability_is_disabled(): void
    {
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Disabled Supervisor');
        $this->store('B2B', 'WA-SUP-DISABLED');

        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-supervisor.index'))
            ->assertNotFound();

        $this->assertSame('DISABLED', DB::table('whats_app_module_states')->value('lifecycle'));
    }

    public function test_sla_metrics_are_derived_from_durable_conversation_and_message_events(): void
    {
        $store = $this->store('B2B', 'WA-SUP-SLA');
        $customer = $this->customer($store, 'b2b', 'SLA Customer');
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'SLA Supervisor');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'SLA Agent');

        $firstInbound = now()->subMinutes(20);
        $firstResponse = now()->subMinutes(15);
        $secondInbound = now()->subMinutes(10);
        $secondResponse = now()->subMinutes(2);
        $conversation = $this->conversation($customer->id, $store, $agent->id, [
            'status' => 'RESOLVED',
            'first_inbound_at' => $firstInbound,
            'first_agent_response_at' => $firstResponse,
            'last_inbound_at' => $secondInbound,
            'last_outbound_at' => $secondResponse,
            'resolved_at' => now()->subMinutes(5),
        ]);

        $this->message($conversation, 'inbound', 'First customer message', $firstInbound);
        $this->message($conversation, 'outbound', 'First agent response', $firstResponse, 'agent', $agent->id);
        $this->message($conversation, 'inbound', 'Second customer message', $secondInbound);
        $this->message($conversation, 'outbound', 'Second agent response', $secondResponse, 'agent', $agent->id);

        $metrics = app(SupervisorAnalyticsService::class)->dashboard($supervisor, [
            'store_id' => $store,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(1, $metrics['summary']['total']);
        $this->assertSame(1, $metrics['summary']['resolved']);
        $this->assertSame(1, $metrics['sla']['first_response']['samples']);
        $this->assertSame(5.0, $metrics['sla']['first_response']['average_minutes']);
        $this->assertSame(2, $metrics['sla']['response']['samples']);
        $this->assertSame(6.5, $metrics['sla']['response']['average_minutes']);
        $this->assertSame(1, $metrics['sla']['resolution']['samples']);
        $this->assertSame(15.0, $metrics['sla']['resolution']['average_minutes']);
    }

    public function test_campaign_reply_creates_attributed_warm_lead_and_follow_up_without_removing_w6_record(): void
    {
        $store = $this->store('B2B', 'WA-SUP-LEAD');
        $customer = $this->customer($store, 'b2b', 'Campaign Lead');
        $agent = $this->globalUser('CUSTOMER_SERVICE_AGENT', 'Campaign Agent');
        $conversation = $this->conversation($customer->id, $store, $agent->id);

        DB::table('whats_app_customer_assignments')->insert([
            'platform_customer_id' => $customer->id,
            'primary_agent_id' => $agent->id,
            'backup_agent_id' => null,
            'store_id' => $store,
            'channel' => 'whatsapp',
            'source' => 'manual',
            'assigned_by' => null,
            'assigned_at' => now(),
            'effective_from' => now(),
            'effective_until' => null,
            'is_active' => true,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $template = (int) DB::table('whats_app_templates')->insertGetId([
            'name' => 'Lead Template',
            'provider_name' => 'lead_template',
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'approved',
            'body_preview' => 'Interested?',
            'components' => null,
            'is_active' => true,
            'last_synced_at' => now(),
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $campaign = (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => 'Lead Campaign',
            'template_id' => $template,
            'status' => 'running',
            'segment' => json_encode([]),
            'test_mode' => true,
            'test_allowlist' => json_encode([]),
            'batch_size' => 50,
            'per_minute_limit' => 100,
            'max_recipients' => 1000,
            'scheduled_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'created_by' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        $recipient = (int) DB::table('whats_app_campaign_recipients')->insertGetId([
            'campaign_id' => $campaign,
            'platform_customer_id' => $customer->id,
            'phone' => (string) $customer->phone,
            'snapshot' => json_encode(['name' => $customer->name]),
            'eligibility_status' => 'eligible',
            'suppression_reason' => null,
            'send_status' => 'sent',
            'message_id' => null,
            'queued_at' => now()->subMinutes(10),
            'sent_at' => now()->subMinutes(9),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);

        app(CampaignReplyRecorder::class)->record(
            $customer->id,
            $conversation,
            'Yes, please contact me',
        );

        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'id' => $recipient,
        ]);
        $this->assertNotNull(
            DB::table('whats_app_campaign_recipients')->where('id', $recipient)->value('replied_at'),
        );
        $this->assertDatabaseHas('whats_app_campaign_followups', [
            'campaign_recipient_id' => $recipient,
            'conversation_id' => $conversation,
            'assigned_agent_id' => $agent->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('whats_app_leads', [
            'platform_customer_id' => $customer->id,
            'conversation_id' => $conversation,
            'assigned_agent_id' => $agent->id,
            'temperature' => 'warm',
            'status' => 'open',
            'source_type' => 'campaign',
            'source_id' => (string) $campaign,
        ]);
        $this->assertDatabaseHas('whats_app_follow_ups', [
            'platform_customer_id' => $customer->id,
            'conversation_id' => $conversation,
            'assigned_agent_id' => $agent->id,
            'status' => 'open',
            'source_type' => 'campaign_recipient',
            'source_id' => (string) $recipient,
        ]);
        $this->assertDatabaseHas('whats_app_crm_events', [
            'event_type' => 'lead_created',
            'platform_customer_id' => $customer->id,
            'source_type' => 'campaign',
            'source_id' => (string) $campaign,
        ]);
    }

    public function test_unified_timeline_combines_whatsapp_and_authorized_food_commercial_events(): void
    {
        $store = $this->store('B2B', 'WA-SUP-TIMELINE');
        $customer = $this->customer($store, 'b2b', 'Timeline Customer');
        $admin = $this->globalUser('SUPER_ADMIN', 'Timeline Admin');
        $conversation = $this->conversation($customer->id, $store);
        $this->message($conversation, 'inbound', 'Timeline WhatsApp message', now()->subMinutes(20));

        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $customer->legacy_customer_id,
            'order_number' => 'WA-SUP-ORDER-001',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 21.5,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 21.5,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);
        $invoice = (int) DB::table('invoices')->insertGetId([
            'order_id' => $order,
            'store_id' => $store,
            'customer_id' => $customer->legacy_customer_id,
            'invoice_number' => 'WA-SUP-INV-001',
            'channel' => 'b2b',
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 21.5,
            'issued_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'order_id' => $order,
            'invoice_id' => $invoice,
            'provider' => 'knet',
            'status' => 'paid',
            'amount' => 21.5,
            'currency' => 'KWD',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now(),
        ]);

        $timeline = app(CustomerTimelineService::class)->forCustomer(
            $admin,
            $customer->id,
            [$store],
        );

        $this->assertTrue($timeline->contains(fn (array $event): bool => $event['kind'] === 'message' && $event['detail'] === 'Timeline WhatsApp message'));
        $this->assertTrue($timeline->contains(fn (array $event): bool => $event['kind'] === 'order' && str_contains((string) $event['title'], 'WA-SUP-ORDER-001')));
        $this->assertTrue($timeline->contains(fn (array $event): bool => $event['kind'] === 'invoice' && str_contains((string) $event['title'], 'WA-SUP-INV-001')));
        $this->assertTrue($timeline->contains(fn (array $event): bool => $event['kind'] === 'payment' && str_contains((string) $event['title'], 'knet')));
    }

    public function test_supervisor_filters_cannot_cross_authorized_store_scope(): void
    {
        $b2b = $this->store('B2B', 'WA-SUP-B2B');
        $b2c = $this->store('B2C', 'WA-SUP-B2C');
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Scoped Supervisor');
        $this->enableInbox();

        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-supervisor.index', ['store_id' => $b2b]))
            ->assertOk();

        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-supervisor.index', ['store_id' => $b2c]))
            ->assertNotFound();
    }

    public function test_supervisor_dashboard_renders_arabic_rtl_and_english_ltr(): void
    {
        $this->store('B2B', 'WA-SUP-LOCALE');
        $supervisor = $this->globalUser('CUSTOMER_SERVICE_SUPERVISOR', 'Locale Supervisor');
        $this->enableInbox();

        $supervisor->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-supervisor.index'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تحليلات المشرف وإدارة العملاء');

        $supervisor->forceFill(['locale' => 'en'])->save();
        $this->actingAs($supervisor)
            ->get(route('admin.whatsapp-supervisor.index'))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('Supervisor Analytics & CRM');
    }

    public function test_supervisor_crm_migration_is_additive_and_rolls_back_deterministically(): void
    {
        $tables = [
            'whats_app_leads',
            'whats_app_follow_ups',
            'whats_app_conversation_outcomes',
            'whats_app_crm_events',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $migration = require database_path(
            'migrations/2026_10_14_130000_create_whatsapp_supervisor_crm_foundation.php',
        );
        $migration->down();

        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('whats_app_conversations'));
        $this->assertTrue(Schema::hasTable('orders'));

        $migration->up();

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    private function enableInbox(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => true,
            'capabilities' => ['agent_inbox' => true],
            'test_numbers' => [],
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

    private function customer(int $storeId, string $channel, string $name): PlatformCustomer
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformCustomer::query()->create([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    private function globalUser(string $roleCode, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function conversation(
        int $platformCustomerId,
        int $storeId,
        ?int $agentId = null,
        array $overrides = [],
    ): int {
        $now = now();

        return (int) DB::table('whats_app_conversations')->insertGetId(array_merge([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $platformCustomerId,
            'external_participant_phone' => null,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => $agentId === null ? 'WAITING_FOR_AGENT' : 'OPEN',
            'primary_agent_id' => $agentId,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => $agentId === null ? $now : null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => null,
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function message(
        int $conversationId,
        string $direction,
        string $body,
        mixed $occurredAt,
        string $actorType = 'customer',
        ?int $actorUserId = null,
    ): int {
        return (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => null,
            'direction' => $direction,
            'content_type' => 'text',
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'body' => $body,
            'attachment_metadata' => null,
            'provider_message_ref' => null,
            'delivery_status' => $direction === 'inbound' ? 'received' : 'sent',
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
