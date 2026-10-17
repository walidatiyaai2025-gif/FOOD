<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\CampaignSendPolicy;
use App\Domain\WhatsApp\CampaignService;
use App\Domain\WhatsApp\MarketingConsentService;
use App\Domain\WhatsApp\MessagingOperationsOverviewService;
use App\Domain\WhatsApp\OutboundPaused;
use App\Domain\WhatsApp\TemplateRegistryService;
use App\Http\Middleware\EnsureWhatsAppCapability;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WhatsAppCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_opt_out_suppresses_a_previously_snapshotted_recipient_at_final_send_boundary(): void
    {
        $customerId = $this->customer($this->store('B2C', 'WA-CMP-OPT'), 'b2c', '+96550000111');
        $this->enableCampaigns(['+96550000111']);

        $consent = app(MarketingConsentService::class);
        $consent->record($customerId, MarketingConsentService::GRANTED, 'admin_verified');

        $campaignId = $this->campaign(
            $this->template(),
            ['customer_ids' => [$customerId]],
            ['+96550000111'],
        );

        $snapshot = app(CampaignService::class)->snapshot($campaignId);
        $this->assertSame(1, $snapshot['eligible']);
        $recipient = DB::table('whats_app_campaign_recipients')->where('campaign_id', $campaignId)->firstOrFail();

        DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
            'status' => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $consent->record($customerId, MarketingConsentService::OPTED_OUT, 'inbound_keyword');

        try {
            app(CampaignSendPolicy::class)->assertRecipientCanSend((int) $recipient->id, '+96550000111');
            $this->fail('Opted-out recipient passed campaign send boundary.');
        } catch (OutboundPaused) {
            $this->assertDatabaseHas('whats_app_campaign_recipients', [
                'id' => $recipient->id,
                'eligibility_status' => 'suppressed',
                'suppression_reason' => 'opted_out',
                'send_status' => 'suppressed',
            ]);
        }

        $this->assertSame('opted_out', $consent->state($customerId));
        $this->assertSame(2, DB::table('whats_app_marketing_consents')->where('platform_customer_id', $customerId)->count());
    }

    public function test_internal_test_requires_both_campaign_allowlist_and_runtime_allowlist(): void
    {
        $storeId = $this->store('B2C', 'WA-CMP-LIST');
        $first = $this->customer($storeId, 'b2c', '+96550000111');
        $second = $this->customer($storeId, 'b2c', '+96550000222');
        $this->enableCampaigns(['+96550000111']);

        $consent = app(MarketingConsentService::class);
        $consent->record($first, MarketingConsentService::GRANTED, 'admin_verified');
        $consent->record($second, MarketingConsentService::GRANTED, 'admin_verified');

        $campaignId = $this->campaign(
            $this->template(),
            ['customer_ids' => [$first, $second]],
            ['+96550000111', '+96550000222'],
        );

        $result = app(CampaignService::class)->snapshot($campaignId);
        $this->assertSame(1, $result['eligible']);
        $this->assertSame(1, $result['suppressed']);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'campaign_id' => $campaignId,
            'platform_customer_id' => $second,
            'suppression_reason' => 'runtime_policy',
        ]);

        $this->enableCampaigns(['+96550000111', '+96550000222']);

        $secondCampaign = $this->campaign(
            $this->template('campaign_offer_two'),
            ['customer_ids' => [$first, $second]],
            ['+96550000111'],
        );
        $result = app(CampaignService::class)->snapshot($secondCampaign);
        $this->assertSame(1, $result['eligible']);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'campaign_id' => $secondCampaign,
            'platform_customer_id' => $second,
            'suppression_reason' => 'test_allowlist',
        ]);
    }

    public function test_campaign_dashboard_renders_in_current_admin_shell_and_marketing_navigation(): void
    {
        $this->enableCampaigns(['+96550000111']);

        $admin = User::factory()->create([
            'name' => 'Campaign Admin',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-campaigns.index'))
            ->assertOk()
            ->assertSee('WhatsApp Campaigns')
            ->assertSee('foodex-admin-layout', false);

        $marketing = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'marketing');

        $this->assertIsArray($marketing);
        $this->assertContains(
            'whatsapp_campaigns',
            array_column($marketing['children'], 'key'),
        );
    }

    public function test_campaign_capability_is_fail_closed_for_route_and_scheduler(): void
    {
        Route::middleware(EnsureWhatsAppCapability::class.':campaigns')
            ->get('/_acceptance/whatsapp/campaigns', fn () => 'ok');

        $this->enableCampaigns(['+96550000111'], false);
        $this->get('/_acceptance/whatsapp/campaigns')->assertNotFound();
        $this->assertSame(0, app(CampaignService::class)->dispatchDue());

        $this->enableCampaigns(['+96550000111'], true);
        $this->get('/_acceptance/whatsapp/campaigns')->assertOk()->assertSeeText('ok');
    }

    public function test_pause_blocks_queued_unsent_transport_and_returns_recipient_to_pending(): void
    {
        $customerId = $this->customer($this->store('B2C', 'WA-CMP-PAUSE'), 'b2c', '+96550000111');
        $this->enableCampaigns(['+96550000111']);
        app(MarketingConsentService::class)->record($customerId, MarketingConsentService::GRANTED, 'admin_verified');

        $campaignId = $this->campaign(
            $this->template(),
            ['customer_ids' => [$customerId]],
            ['+96550000111'],
        );
        app(CampaignService::class)->snapshot($campaignId);
        DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
            'status' => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $conversationId = $this->conversation($customerId);
        $messageId = (int) DB::table('whats_app_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'connection_profile_id' => null,
            'direction' => 'outbound',
            'content_type' => 'template',
            'actor_type' => 'system',
            'actor_user_id' => null,
            'body' => 'Queued campaign',
            'attachment_metadata' => json_encode(['purpose' => 'campaigns']),
            'provider_message_ref' => null,
            'delivery_status' => 'queued',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recipientId = (int) DB::table('whats_app_campaign_recipients')
            ->where('campaign_id', $campaignId)
            ->value('id');
        DB::table('whats_app_campaign_recipients')->where('id', $recipientId)->update([
            'message_id' => $messageId,
            'send_status' => 'queued',
            'queued_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('whats_app_outbound_dispatches')->insert([
            'message_id' => $messageId,
            'connection_profile_id' => null,
            'idempotency_key' => 'campaign-pause-'.$messageId,
            'recipient' => '+96550000111',
            'payload' => json_encode(['kind' => 'template', 'metadata' => ['purpose' => 'campaigns']]),
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CampaignService::class)->pause($campaignId);

        $this->assertDatabaseHas('whats_app_campaigns', ['id' => $campaignId, 'status' => 'paused']);
        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'message_id' => $messageId,
            'status' => 'blocked_policy',
        ]);
        $this->assertDatabaseHas('whats_app_campaign_recipients', [
            'id' => $recipientId,
            'send_status' => 'pending',
            'message_id' => null,
        ]);
    }

    public function test_unified_messaging_overview_uses_authoritative_whatsapp_delivery_signals(): void
    {
        $storeId = $this->store('B2C', 'WA-OPS');
        $customerId = $this->customer($storeId, 'b2c', '+96550000333');
        $this->enableCampaigns(['+96550000333']);

        app(MarketingConsentService::class)->record(
            $customerId,
            MarketingConsentService::GRANTED,
            'admin_verified',
        );

        $campaignId = $this->campaign(
            $this->template('operations_overview'),
            ['customer_ids' => [$customerId]],
            ['+96550000333'],
        );
        app(CampaignService::class)->snapshot($campaignId);
        DB::table('whats_app_campaigns')->where('id', $campaignId)->update([
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        DB::table('whats_app_campaign_recipients')->where('campaign_id', $campaignId)->update([
            'send_status' => 'sent',
            'sent_at' => now()->subMinutes(40),
            'delivered_at' => now()->subMinutes(35),
            'read_at' => now()->subMinutes(30),
            'replied_at' => now()->subMinutes(20),
            'updated_at' => now(),
        ]);
        $this->conversation($customerId);

        DB::table('notification_campaigns')->insert([
            'name' => 'Push campaign is not WhatsApp delivery truth',
            'type' => 'promotion',
            'title_ar' => 'عرض',
            'title_en' => 'Offer',
            'body_ar' => 'رسالة',
            'body_en' => 'Message',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'delivery_channel' => 'push',
            'store_id' => $storeId,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::factory()->create([
            'name' => 'Messaging Operations Admin',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $overview = app(MessagingOperationsOverviewService::class)->overview($admin);

        $this->assertSame(1, $overview['metrics']['campaigns']);
        $this->assertSame(1, $overview['metrics']['recipients']);
        $this->assertSame(1, $overview['metrics']['sent']);
        $this->assertSame(1, $overview['metrics']['delivered']);
        $this->assertSame(1, $overview['metrics']['read']);
        $this->assertSame(1, $overview['metrics']['replied']);
        $this->assertSame(1, $overview['inbox_unresolved']);
        $this->assertTrue($overview['notification_campaigns']['visible']);
        $this->assertSame(1, $overview['notification_campaigns']['total']);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-campaigns.index'))
            ->assertOk()
            ->assertSee('Messaging operations overview')
            ->assertSee('provider-confirmed only')
            ->assertSee('foodex-module-overview', false);
    }

    public function test_campaign_lifecycle_mutations_write_audit_events(): void
    {
        $storeId = $this->store('B2C', 'WA-AUDIT');
        $customerId = $this->customer($storeId, 'b2c', '+96550000444');
        $this->enableCampaigns(['+96550000444']);
        app(MarketingConsentService::class)->record(
            $customerId,
            MarketingConsentService::GRANTED,
            'admin_verified',
        );

        $campaignId = $this->campaign(
            $this->template('audit_operations'),
            ['customer_ids' => [$customerId]],
            ['+96550000444'],
        );

        $admin = User::factory()->create([
            'name' => 'Campaign Audit Admin',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-campaigns.snapshot', ['campaign' => $campaignId]))
            ->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.campaign.snapshot_rebuilt']);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-campaigns.schedule', ['campaign' => $campaignId]))
            ->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.campaign.scheduled']);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-campaigns.pause', ['campaign' => $campaignId]))
            ->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.campaign.paused']);

        $this->actingAs($admin)
            ->post(route('admin.whatsapp-campaigns.cancel', ['campaign' => $campaignId]))
            ->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.campaign.cancelled']);
    }

    public function test_campaign_schema_is_additive_and_round_trip_safe(): void
    {
        foreach ([
            'whats_app_templates',
            'whats_app_marketing_consents',
            'whats_app_marketing_preferences',
            'whats_app_campaigns',
            'whats_app_campaign_recipients',
            'whats_app_campaign_followups',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $migration = require database_path(
            'migrations/2026_10_14_111500_create_whatsapp_campaign_foundation.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasTable('whats_app_campaigns'));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('platform_customers'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('whats_app_campaigns'));
    }

    private function enableCampaigns(array $testNumbers, bool $campaigns = true): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'outbound' => true,
                'campaigns' => $campaigns,
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => $testNumbers,
        ])->save();
    }

    private function template(string $providerName = 'campaign_offer'): int
    {
        return app(TemplateRegistryService::class)->upsert([
            'name' => 'Campaign Offer',
            'provider_name' => $providerName,
            'locale' => 'en',
            'category' => 'marketing',
            'provider_status' => 'approved',
            'body_preview' => 'Hello from FOODEX',
            'is_active' => true,
        ]);
    }

    private function campaign(int $templateId, array $segment, array $allowlist): int
    {
        return (int) DB::table('whats_app_campaigns')->insertGetId([
            'name' => 'Campaign '.Str::random(6),
            'template_id' => $templateId,
            'status' => 'draft',
            'segment' => json_encode($segment, JSON_THROW_ON_ERROR),
            'test_mode' => true,
            'test_allowlist' => json_encode($allowlist, JSON_THROW_ON_ERROR),
            'batch_size' => 10,
            'per_minute_limit' => 10,
            'max_recipients' => 100,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'name' => 'Campaign Customer',
            'email' => 'campaign-'.Str::uuid().'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => 'Campaign Customer',
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => 'Campaign Customer',
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

    private function conversation(int $customerId): int
    {
        $storeId = DB::table('platform_customers')->where('id', $customerId)->value('origin_store_id');

        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $customerId,
            'external_participant_phone' => null,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_CUSTOMER',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'waiting_since' => null,
            'first_inbound_at' => null,
            'first_agent_response_at' => null,
            'last_inbound_at' => null,
            'last_outbound_at' => now(),
            'resolved_at' => null,
            'reopened_at' => null,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
