<?php

namespace Tests\Feature\Enterprise;

use App\Domain\WhatsApp\MarketingConsentService;
use App\Enterprise\Commercial\WhatsAppCommercialChannelBridge;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class WhatsAppCommercialChannelBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Http::preventStrayRequests();
    }

    public function test_reference_composes_canonical_customer_order_and_rejects_cross_scope_records(): void
    {
        $storeA = $this->store('B2C', 'WA-COMM-A');
        $storeB = $this->store('B2C', 'WA-COMM-B');
        [$platformCustomerId, $legacyCustomerId] = $this->customer($storeA, '+96550000111');
        $conversationId = $this->conversation($platformCustomerId, $storeA);
        $orderId = $this->order($legacyCustomerId, $storeA, 'WA-COMM-ORDER-A');
        $foreignOrderId = $this->order($legacyCustomerId, $storeB, 'WA-COMM-ORDER-B');
        $actor = $this->superAdmin();

        $reference = app(WhatsAppCommercialChannelBridge::class)
            ->reference($actor, $conversationId, $orderId);

        $this->assertSame(1, $reference['contract_version']);
        $this->assertSame('whatsapp', $reference['medium']);
        $this->assertSame($storeA, $reference['store_id']);
        $this->assertSame('b2c', $reference['customer_channel']);
        $this->assertSame($platformCustomerId, $reference['customer']['platform_customer_id']);
        $this->assertSame($legacyCustomerId, $reference['customer']['legacy_customer_id']);
        $this->assertSame($conversationId, $reference['conversation']['id']);
        $this->assertStringContainsString(
            'conversation='.$conversationId,
            $reference['conversation']['deep_link'],
        );
        $this->assertSame($orderId, $reference['order']['id']);

        try {
            app(WhatsAppCommercialChannelBridge::class)
                ->reference($actor, $conversationId, $foreignOrderId);
            $this->fail('Expected a foreign-store order reference to be hidden.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $unprivileged = User::factory()->create(['is_active' => true]);

        $this->expectException(NotFoundHttpException::class);
        app(WhatsAppCommercialChannelBridge::class)
            ->reference($unprivileged, $conversationId, $orderId);
    }

    public function test_event_handoff_is_versioned_retry_stable_and_provider_free(): void
    {
        $storeId = $this->store('B2C', 'WA-COMM-EVENT');
        [$platformCustomerId, $legacyCustomerId] = $this->customer($storeId, '+96550000112');
        $conversationId = $this->conversation($platformCustomerId, $storeId);
        $orderId = $this->order($legacyCustomerId, $storeId, 'WA-COMM-EVENT-1');
        $actor = $this->superAdmin();
        $bridge = app(WhatsAppCommercialChannelBridge::class);

        $first = $bridge->event(
            $actor,
            $conversationId,
            'order',
            $orderId,
            ['status' => 'confirmed'],
        );
        $second = $bridge->event(
            $actor,
            $conversationId,
            'order',
            $orderId,
            ['status' => 'confirmed'],
        );

        $this->assertSame($first['idempotency_key'], $second['idempotency_key']);
        $this->assertSame('foodex.commercial.order.changed', $first['event']->name);
        $this->assertSame(1, $first['event']->version);
        $this->assertSame($storeId, $first['event']->storeId);
        $this->assertSame('b2c', $first['event']->channel);
        $this->assertSame('order', $first['event']->aggregateType);
        $this->assertSame($orderId, $first['event']->aggregateId);
        $this->assertSame(
            $first['idempotency_key'],
            $first['event']->payload['idempotency_key'],
        );
        $this->assertSame(0, DB::table('whats_app_messages')->count());
        $this->assertSame(0, DB::table('whats_app_transactional_events')->count());
        Http::assertNothingSent();
    }

    public function test_consent_and_channel_eligibility_delegate_to_existing_whatsapp_authorities(): void
    {
        $phone = '+96550000113';
        $storeId = $this->store('B2C', 'WA-COMM-CONSENT');
        [$platformCustomerId] = $this->customer($storeId, $phone);
        $conversationId = $this->conversation($platformCustomerId, $storeId);
        $actor = $this->superAdmin();

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'inbound' => true,
                'agent_inbox' => true,
                'transactional' => true,
                'campaigns' => false,
                'automation' => false,
                'outbound' => true,
                'calls' => false,
            ],
            'test_numbers' => [$phone],
            'revision' => 10,
        ])->save();

        $consent = app(MarketingConsentService::class);
        $consent->record(
            $platformCustomerId,
            MarketingConsentService::GRANTED,
            'commercial-channel-test',
        );

        $bridge = app(WhatsAppCommercialChannelBridge::class);
        $eligible = $bridge->eligibility($actor, $conversationId);

        $this->assertSame(MarketingConsentService::GRANTED, $eligible['consent_state']);
        $this->assertTrue($eligible['marketing_eligible']);
        $this->assertTrue($eligible['agent_inbox_eligible']);
        $this->assertTrue($eligible['transactional_eligible']);

        $consent->record(
            $platformCustomerId,
            MarketingConsentService::OPTED_OUT,
            'commercial-channel-test',
        );

        $optedOut = $bridge->eligibility($actor, $conversationId);
        $this->assertSame(MarketingConsentService::OPTED_OUT, $optedOut['consent_state']);
        $this->assertFalse($optedOut['marketing_eligible']);
        $this->assertTrue($optedOut['transactional_eligible']);

        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'DISABLED',
            'emergency_stop' => true,
        ])->save();

        $disabled = $bridge->eligibility($actor, $conversationId);
        $this->assertFalse($disabled['agent_inbox_eligible']);
        $this->assertFalse($disabled['transactional_eligible']);

        $reference = $bridge->reference($actor, $conversationId);
        $this->assertSame($conversationId, $reference['conversation']['id']);
        Http::assertNothingSent();
    }

    public function test_recent_conversation_references_are_bounded_and_store_scoped(): void
    {
        $storeA = $this->store('B2C', 'WA-COMM-LIST-A');
        $storeB = $this->store('B2C', 'WA-COMM-LIST-B');
        [$platformCustomerId] = $this->customer($storeA, '+96550000114');

        foreach (range(1, 6) as $offset) {
            $this->conversation($platformCustomerId, $storeA, now()->addSeconds($offset));
        }
        $this->conversation($platformCustomerId, $storeB, now()->addMinute());

        $rows = app(WhatsAppCommercialChannelBridge::class)
            ->recentConversationReferences(
                $this->superAdmin(),
                $platformCustomerId,
                $storeA,
                3,
            );

        $this->assertCount(3, $rows);
        $this->assertSame(
            count($rows),
            count(array_unique(array_column($rows, 'id'))),
        );
        foreach ($rows as $row) {
            $this->assertStringContainsString(
                'conversation='.$row['id'],
                $row['deep_link'],
            );
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        return $user;
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', $channel)
            ->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function customer(int $storeId, string $phone): array
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_platform_customer' => true,
        ]);
        $legacyCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => 'WhatsApp Commercial Customer',
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $platformCustomerId = (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyCustomerId,
            'name' => 'WhatsApp Commercial Customer',
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

        return [$platformCustomerId, $legacyCustomerId];
    }

    private function conversation(
        int $platformCustomerId,
        int $storeId,
        mixed $updatedAt = null,
    ): int {
        $updatedAt ??= now();

        return (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => $platformCustomerId,
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_CUSTOMER',
            'primary_agent_id' => null,
            'priority' => 0,
            'unread_count' => 0,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function order(int $legacyCustomerId, int $storeId, string $number): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'confirmed',
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
