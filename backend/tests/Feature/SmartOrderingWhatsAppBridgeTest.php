<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Events\SmartOrderingOrderCreated;
use App\Domain\SmartOrdering\Events\SmartOrderingOrderStatusChanged;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\WhatsApp\SmartOrderingConversationContextService;
use App\Domain\SmartOrdering\WhatsApp\SmartOrderingWhatsAppBridge;
use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Jobs\ProcessWhatsAppTransactionalEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SmartOrderingOrderContext;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SmartOrderingWhatsAppBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_created_order_links_to_existing_crm_and_notification_replay_is_idempotent(): void
    {
        Queue::fake();
        $bundle = $this->bundle('A', '+96550010101');
        $this->enableWhatsApp([$bundle['phone']]);
        $this->profile();
        $this->enableEvent('order.created');

        $bridge = app(SmartOrderingWhatsAppBridge::class);
        $event = new SmartOrderingOrderCreated(
            (int) $bundle['order']->id,
            (string) $bundle['context']->public_reference,
            (int) $bundle['branch']->id,
            'pickup',
        );

        $bridge->handleCreated($event);
        $bridge->handleCreated($event);

        $this->assertDatabaseCount('whats_app_smart_ordering_links', 1);
        $this->assertSame(1, DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.created')
            ->count());
        Queue::assertPushed(ProcessWhatsAppTransactionalEvent::class, 1);

        $link = DB::table('whats_app_smart_ordering_links')->first();
        $this->assertNotNull($link);
        $this->assertNotNull($link->conversation_id);
        $snapshot = json_decode((string) $link->snapshot, true);
        $this->assertSame((string) $bundle['order']->order_number, $snapshot['order_number']);
        $this->assertSame('pickup', $snapshot['fulfillment_mode']);
        $this->assertSame(12.750, (float) $snapshot['totals']['grand_total']);
        $this->assertArrayNotHasKey('customer_id', $snapshot);
        $this->assertStringNotContainsString($bundle['phone'], (string) $link->snapshot);

        $crm = app(SmartOrderingConversationContextService::class)->forConversation(
            (int) $link->conversation_id,
            [(int) $bundle['store_id']],
        );
        $this->assertCount(1, $crm['orders']);
        $this->assertSame((int) $bundle['order']->id, $crm['orders'][0]['order_id']);
        $this->assertStringContainsString('/admin/operations/orders', $crm['orders'][0]['order_url']);
    }

    public function test_smart_ordering_runtime_and_entitlement_gate_whatsapp_without_losing_order(): void
    {
        Queue::fake();
        $bundle = $this->bundle('B', '+96550020202');
        $this->enableWhatsApp([$bundle['phone']]);
        $this->profile();
        $this->enableEvent('order.created');

        DB::table('smart_ordering_entitlements')
            ->where('site_id', $bundle['site_id'])
            ->where('module', 'whatsapp')
            ->update(['enabled' => false]);

        app(SmartOrderingWhatsAppBridge::class)->handleCreated(new SmartOrderingOrderCreated(
            (int) $bundle['order']->id,
            (string) $bundle['context']->public_reference,
            (int) $bundle['branch']->id,
            'pickup',
        ));

        $this->assertDatabaseHas('orders', ['id' => $bundle['order']->id]);
        $this->assertDatabaseHas('whats_app_smart_ordering_links', [
            'order_id' => $bundle['order']->id,
            'last_event_status' => 'skipped_smart_ordering_runtime',
        ]);
        $this->assertDatabaseCount('whats_app_transactional_events', 0);
        Queue::assertNotPushed(ProcessWhatsAppTransactionalEvent::class);
    }

    public function test_missing_provider_and_rejected_template_are_observable_without_order_corruption(): void
    {
        Queue::fake();
        $bundle = $this->bundle('C', '+96550030303');
        $this->enableWhatsApp([$bundle['phone']]);
        $this->enableEvent('order.created');

        $bridge = app(SmartOrderingWhatsAppBridge::class);
        $bridge->handleCreated(new SmartOrderingOrderCreated(
            (int) $bundle['order']->id,
            (string) $bundle['context']->public_reference,
            (int) $bundle['branch']->id,
            'pickup',
        ));

        $this->assertDatabaseHas('whats_app_transactional_events', [
            'event_name' => 'order.created',
            'status' => 'skipped_transport_unconfigured',
            'skipped_reason' => 'transport_unconfigured',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $bundle['order']->id]);

        DB::table('whats_app_transactional_events')->delete();
        DB::table('whats_app_smart_ordering_links')->update([
            'last_event_name' => null,
            'last_event_status' => null,
            'last_transactional_event_id' => null,
        ]);
        $this->profile();
        DB::table('whats_app_templates')->insert([
            'name' => 'Rejected Smart Ordering template',
            'provider_name' => 'foodex_order_created_en',
            'locale' => 'en',
            'category' => 'utility',
            'provider_status' => 'rejected',
            'body_preview' => 'Rejected',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bridge->handleCreated(new SmartOrderingOrderCreated(
            (int) $bundle['order']->id,
            (string) $bundle['context']->public_reference,
            (int) $bundle['branch']->id,
            'pickup',
        ));

        $this->assertDatabaseHas('whats_app_transactional_events', [
            'event_name' => 'order.created',
            'status' => 'skipped_template_unavailable',
            'skipped_reason' => 'template_unavailable',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $bundle['order']->id]);
        Queue::assertNotPushed(ProcessWhatsAppTransactionalEvent::class);
    }

    public function test_status_and_scheduled_reminder_events_are_deduplicated(): void
    {
        Queue::fake();
        $bundle = $this->bundle('D', '+96550040404');
        $this->enableWhatsApp([$bundle['phone']]);
        $this->profile();
        $this->enableEvent('order.confirmed');
        $this->enableEvent('order.scheduled_reminder');

        $bundle['context']->update(['lifecycle_status' => 'CONFIRMED']);
        $status = new SmartOrderingOrderStatusChanged(
            (int) $bundle['order']->id,
            (int) $bundle['context']->id,
            (string) $bundle['context']->public_reference,
            'NEW',
            'CONFIRMED',
            'pickup',
            'status-confirmed',
        );

        $bridge = app(SmartOrderingWhatsAppBridge::class);
        $bridge->handleStatusChanged($status);
        $bridge->handleStatusChanged($status);
        $bridge->scheduledReminder($bundle['context']->fresh());
        $bridge->scheduledReminder($bundle['context']->fresh());

        $this->assertSame(1, DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.confirmed')
            ->count());
        $this->assertSame(1, DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.scheduled_reminder')
            ->count());
        Queue::assertPushed(ProcessWhatsAppTransactionalEvent::class, 2);
    }

    public function test_crm_context_never_crosses_restaurant_tenants(): void
    {
        Queue::fake();
        $one = $this->bundle('E1', '+96550050501');
        $two = $this->bundle('E2', '+96550050502');
        $this->enableWhatsApp([$one['phone'], $two['phone']]);
        $this->profile();
        $this->enableEvent('order.created');

        $bridge = app(SmartOrderingWhatsAppBridge::class);
        foreach ([$one, $two] as $bundle) {
            $bridge->handleCreated(new SmartOrderingOrderCreated(
                (int) $bundle['order']->id,
                (string) $bundle['context']->public_reference,
                (int) $bundle['branch']->id,
                'pickup',
            ));
        }

        $conversationOne = (int) DB::table('whats_app_smart_ordering_links')
            ->where('order_id', $one['order']->id)
            ->value('conversation_id');

        $crm = app(SmartOrderingConversationContextService::class)->forConversation(
            $conversationOne,
            [(int) $one['store_id'], (int) $two['store_id']],
        );

        $this->assertCount(1, $crm['orders']);
        $this->assertSame((int) $one['order']->id, $crm['orders'][0]['order_id']);
        $this->assertNotSame((int) $two['order']->id, $crm['orders'][0]['order_id']);
    }

    /**
     * @return array{
     *   store_id:int,site_id:int,phone:string,order:Order,context:SmartOrderingOrderContext,
     *   branch:SmartOrderingBranch,platform_customer_id:int
     * }
     */
    private function bundle(string $suffix, string $phone): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'name' => 'Smart WA '.$suffix,
            'code' => 'SO-WA-'.$suffix.'-'.Str::lower(Str::random(5)),
            'store_type_id' => $storeTypeId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableRestaurantSaasForTestStore($storeId);

        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'so-wa-'.strtolower($suffix).'-'.Str::lower(Str::random(5)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('smart_ordering_entitlements')->insert([
            'site_id' => $siteId,
            'branch_id' => null,
            'scope_key' => 'site',
            'module' => 'whatsapp',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'main',
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Main Branch',
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'supports_dine_in' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 10,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'whatsapp_routing_identity' => '+96522220000',
        ]);

        $user = User::factory()->create([
            'name' => 'Smart WA Customer '.$suffix,
            'email' => 'smart-wa-'.strtolower($suffix).'-'.Str::lower(Str::random(5)).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
        ]);
        $b2cId = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacy->id,
            'user_id' => $user->id,
            'store_id' => $storeId,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $platformId = (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacy->id,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'registration_source' => 'smart_ordering_test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->id,
            'b2c_customer_id' => $b2cId,
            'order_number' => 'SO-WA-'.$suffix.'-'.Str::upper(Str::random(6)),
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 12.000,
            'discount_total' => 0,
            'delivery_total' => 0.750,
            'tax_total' => 0,
            'grand_total' => 12.750,
            'customer_note' => 'No onions',
        ]);

        $context = SmartOrderingOrderContext::query()->create([
            'order_id' => $order->id,
            'public_reference' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'fulfillment_mode' => 'pickup',
            'scheduled_for' => now()->addHour(),
            'subtotal' => 12.000,
            'discount_total' => 0,
            'delivery_total' => 0.750,
            'service_charge_total' => 0,
            'tax_total' => 0,
            'grand_total' => 12.750,
            'lifecycle_status' => 'NEW',
            'idempotency_key_hash' => hash('sha256', 'idempotency-'.$suffix),
            'request_hash' => hash('sha256', 'request-'.$suffix),
            'notification_status' => 'pending',
        ]);

        return [
            'store_id' => $storeId,
            'site_id' => $siteId,
            'phone' => $phone,
            'order' => $order,
            'context' => $context,
            'branch' => $branch,
            'platform_customer_id' => $platformId,
        ];
    }

    /** @param list<string> $numbers */
    private function enableWhatsApp(array $numbers): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::OUTBOUND->value => true,
                Capability::TRANSACTIONAL->value => true,
                Capability::INBOUND->value => true,
                Capability::AGENT_INBOX->value => true,
                Capability::CAMPAIGNS->value => false,
                Capability::AUTOMATION->value => true,
            ],
            'test_numbers' => $numbers,
            'revision' => 2,
        ])->save();
    }

    private function enableEvent(string $event): void
    {
        DB::table('whats_app_transactional_preferences')
            ->where('event_name', $event)
            ->whereIn('customer_channel', ['b2c', '*'])
            ->update(['enabled' => true]);
    }

    private function profile(): void
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'Meta Smart Ordering',
            'display_phone_number' => '+96522220000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-so',
            'provider_account_id' => 'waba-so',
            'provider_app_id' => 'app-so',
            'status' => ConnectionProfileStatus::ACTIVE,
            'health_status' => ConnectionHealthStatus::READY,
            'is_primary' => true,
            'receive_only' => false,
            'primary_slot' => 1,
            'configuration_revision' => 1,
            'last_validated_at' => now(),
        ]);
        $profile->credential_secret = 'token';
        $profile->webhook_verify_token = 'verify';
        $profile->webhook_signing_secret = 'sign';
        $profile->save();
    }
}
