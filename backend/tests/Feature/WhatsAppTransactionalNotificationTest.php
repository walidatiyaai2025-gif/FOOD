<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\Capability;
use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\TransactionalNotificationEngine;
use App\Jobs\ProcessWhatsAppTransactionalEvent;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WhatsAppTransactionalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_module_audits_skip_and_never_queues_outbound(): void
    {
        Queue::fake();
        [$order] = $this->order('+96550000111', 'ar');

        DB::table('whats_app_transactional_preferences')
            ->where('event_name', 'order.created')
            ->update(['enabled' => true]);

        app(TransactionalNotificationEngine::class)
            ->forOrder($order, 'order.created', 'order-created:'.$order->id);

        $eventId = DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.created')
            ->where('store_id', $order->store_id)
            ->value('id');

        $this->assertNotNull($eventId);
        $this->assertDatabaseHas('whats_app_transactional_events', [
            'id' => $eventId,
            'status' => 'skipped_policy_blocked',
            'skipped_reason' => 'policy_blocked',
            'locale' => 'ar',
        ]);
        Queue::assertNotPushed(ProcessWhatsAppTransactionalEvent::class);
        Queue::assertNotPushed(SendWhatsAppMessage::class);
    }

    public function test_enabled_event_projects_one_template_message_and_replay_is_idempotent(): void
    {
        Queue::fake();
        [$order] = $this->order('+96550000111', 'en');
        $this->profile();
        $this->enableTransactional('+96550000111');

        DB::table('whats_app_transactional_preferences')
            ->where('event_name', 'order.created')
            ->update([
                'enabled' => true,
                'template_name_en' => 'foodex_order_created_en_v1',
            ]);

        $engine = app(TransactionalNotificationEngine::class);
        $engine->forOrder($order, 'order.created', 'stable-event-key');
        $engine->forOrder($order, 'order.created', 'stable-event-key');

        $this->assertSame(1, DB::table('whats_app_transactional_events')->count());
        Queue::assertPushed(ProcessWhatsAppTransactionalEvent::class, 1);

        $eventId = (int) DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.created')
            ->value('id');
        $this->assertGreaterThan(0, $eventId);

        $engine->process($eventId);

        $event = DB::table('whats_app_transactional_events')->where('id', $eventId)->first();
        $this->assertNotNull($event->message_id);
        $this->assertNotNull($event->dispatch_id);
        $this->assertSame('queued', (string) $event->status);

        $message = DB::table('whats_app_messages')->where('id', $event->message_id)->first();
        $metadata = json_decode((string) $message->attachment_metadata, true);
        $this->assertSame('template', $message->content_type);
        $this->assertSame('transactional', $metadata['purpose']);
        $this->assertSame('foodex_order_created_en_v1', $metadata['template_name']);
        $this->assertSame('en_US', $metadata['language']);

        $this->assertDatabaseHas('whats_app_outbound_dispatches', [
            'id' => $event->dispatch_id,
            'message_id' => $event->message_id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
    }

    public function test_store_event_channel_toggle_overrides_global_default(): void
    {
        Queue::fake();
        [$order] = $this->order('+96550000111', 'ar');
        $this->profile();
        $this->enableTransactional('+96550000111');

        DB::table('whats_app_transactional_preferences')->insert([
            'store_id' => (int) $order->store_id,
            'customer_channel' => 'b2c',
            'delivery_channel' => 'whatsapp',
            'event_name' => 'order.delivered',
            'enabled' => true,
            'template_name_ar' => 'foodex_delivered_ar_store',
            'template_name_en' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalNotificationEngine::class)->forOrder(
            $order,
            'order.delivered',
            'delivered:'.$order->id,
        );

        $eventId = DB::table('whats_app_transactional_events')
            ->where('event_name', 'order.delivered')
            ->where('store_id', $order->store_id)
            ->value('id');

        $this->assertNotNull($eventId);
        $this->assertDatabaseHas('whats_app_transactional_events', [
            'id' => $eventId,
            'status' => 'queued',
            'locale' => 'ar',
            'template_name' => 'foodex_delivered_ar_store',
        ]);
    }

    public function test_status_mapping_is_deterministic(): void
    {
        $this->assertSame('order.confirmed', TransactionalNotificationEngine::orderEventForStatus('CONFIRMED'));
        $this->assertSame('order.preparing', TransactionalNotificationEngine::orderEventForStatus('preparing'));
        $this->assertSame('order.dispatched', TransactionalNotificationEngine::orderEventForStatus('out_for_delivery'));
        $this->assertSame('order.delivered', TransactionalNotificationEngine::orderEventForStatus('delivered'));
        $this->assertSame('order.cancelled', TransactionalNotificationEngine::orderEventForStatus('cancelled'));
        $this->assertSame('payment.received', TransactionalNotificationEngine::paymentEventForStatus('paid'));
        $this->assertSame('payment.failed', TransactionalNotificationEngine::paymentEventForStatus('failed'));
        $this->assertSame('refund.processed', TransactionalNotificationEngine::paymentEventForStatus('refunded'));
    }

    /** @return array{0:Order,1:int} */
    private function order(string $phone, string $locale): array
    {
        $storeTypeId = DB::table('store_types')->whereRaw('LOWER(code) = ?', ['b2c'])->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'name' => 'WA TX Store',
            'code' => 'WA-TX-'.uniqid(),
            'store_type_id' => $storeTypeId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'name' => 'Transactional Customer',
            'email' => uniqid().'@example.test',
            'locale' => $locale,
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
        $customerId = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacy->id,
            'user_id' => $user->id,
            'store_id' => $storeId,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('platform_customers')->insert([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacy->id,
            'name' => $user->name,
            'phone' => $phone,
            'email' => $user->email,
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'registration_source' => 'test',
            'registered_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = new Order;
        $order->forceFill([
            'store_id' => $storeId,
            'customer_id' => $legacy->id,
            'b2c_customer_id' => $customerId,
            'channel' => 'b2c',
            'order_number' => 'TX-'.uniqid(),
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 1,
            'tax_total' => 0,
            'grand_total' => 11,
        ])->save();

        return [$order->fresh(), (int) $user->id];
    }

    private function profile(): void
    {
        $profile = new WhatsAppConnectionProfile;
        $profile->forceFill([
            'name' => 'Meta transactional',
            'display_phone_number' => '+96522220000',
            'normalized_phone_number' => '+96522220000',
            'provider_type' => 'meta_cloud',
            'provider_phone_number_id' => 'phone-tx',
            'provider_account_id' => 'waba-tx',
            'provider_app_id' => 'app-tx',
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

    private function enableTransactional(string $number): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                Capability::OUTBOUND->value => true,
                Capability::TRANSACTIONAL->value => true,
                Capability::INBOUND->value => false,
                Capability::AGENT_INBOX->value => false,
                Capability::CAMPAIGNS->value => false,
                Capability::AUTOMATION->value => false,
            ],
            'test_numbers' => [$number],
            'revision' => 2,
        ])->save();
    }
}
