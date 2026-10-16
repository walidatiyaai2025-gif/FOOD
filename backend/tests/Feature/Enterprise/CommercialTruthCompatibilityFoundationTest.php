<?php

namespace Tests\Feature\Enterprise;

use App\Domain\Pricing\B2bPriceResolver;
use App\Enterprise\Commercial\CommercialContextGuard;
use App\Enterprise\Commercial\CommercialCreditEligibilityPolicy;
use App\Enterprise\Commercial\CommercialEventFactory;
use App\Enterprise\Commercial\CommercialTruthRegistry;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\B2bAccountLedgerService;
use App\Services\CollectionCustodyService;
use App\Services\CommerceQuoteService;
use App\Services\CustomerDomainResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CommercialTruthCompatibilityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_declares_existing_commercial_authorities_without_parallel_ledgers(): void
    {
        $truth = app(CommercialTruthRegistry::class);

        $customer = $truth->authority('customer', 'b2c');
        $order = $truth->authority('order', 'b2b');
        $pricing = $truth->authority('pricing', 'b2b');
        $credit = $truth->authority('credit', 'b2b');
        $collection = $truth->authority('collection', 'b2b');

        $this->assertSame(CustomerDomainResolver::class, $customer['write_authority']);
        $this->assertSame(Order::class, $order['write_authority']);
        $this->assertSame(CommerceQuoteService::class, $pricing['write_authority']);
        $this->assertContains(B2bPriceResolver::class, $pricing['supporting_authorities']);
        $this->assertSame(B2bAccountLedgerService::class, $credit['write_authority']);
        $this->assertSame(CollectionCustodyService::class, $collection['write_authority']);
        $this->assertSame(['b2b_accounts', 'customer_account_ledger_entries'], $credit['storage']);
        $this->assertContains('collection_transactions', $collection['storage']);

        $encoded = json_encode($truth->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('OrderV2', $encoded);
        $this->assertStringNotContainsString('CustomerV2', $encoded);
        $this->assertStringNotContainsString('payment_ledger_v2', $encoded);
        $this->assertSame(1, CommercialTruthRegistry::CONTRACT_VERSION);
    }

    public function test_context_guard_reads_the_canonical_order_and_denies_cross_tenant_or_channel_access(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $otherStoreId, $customerId] = $this->commerceFixture();
        $actor = $this->operationsUser($storeId);

        $ownedOrderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'COMM-OWNED',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignOrderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $otherStoreId,
            'customer_id' => $customerId,
            'order_number' => 'COMM-FOREIGN',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guard = app(CommercialContextGuard::class);
        $order = $guard->order($actor, $ownedOrderId);

        $this->assertSame($ownedOrderId, $order->id);
        $this->assertSame('orders', $order->getTable());

        try {
            $guard->order($actor, $foreignOrderId);
            $this->fail('Expected cross-tenant order lookup to be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $guard->assert($actor, $storeId, 'b2b', 'orders.view');
            $this->fail('Expected channel mismatch to be hidden.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $unprivileged = User::query()->create([
            'name' => 'No commercial access',
            'email' => 'no-commercial-access@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        try {
            $guard->assert($unprivileged, $storeId, 'b2c', 'orders.view');
            $this->fail('Expected RBAC denial.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_versioned_event_handoff_uses_registry_names_and_rejects_invalid_fact_channel_pairs(): void
    {
        $factory = app(CommercialEventFactory::class);

        $event = $factory->make(
            'order',
            'b2c',
            41,
            'order',
            901,
            ['status' => 'confirmed'],
        );

        $this->assertSame('foodex.commercial.order.changed', $event->name);
        $this->assertSame(1, $event->version);
        $this->assertSame(41, $event->storeId);
        $this->assertSame('b2c', $event->channel);
        $this->assertSame(901, $event->aggregateId);
        $this->assertSame(['status' => 'confirmed'], $event->payload);

        $this->expectException(InvalidArgumentException::class);

        $factory->make(
            'collection',
            'b2c',
            41,
            'collection',
            10,
            [],
        );
    }

    public function test_unknown_fact_and_invalid_event_identity_fail_closed(): void
    {
        $truth = app(CommercialTruthRegistry::class);

        try {
            $truth->authority('orders-v2', 'b2c');
            $this->fail('Expected unknown commercial facts to fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        app(CommercialEventFactory::class)->make(
            'order',
            'b2c',
            0,
            'order',
            99,
            [],
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function commerceFixture(): array
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'COMM-1',
            'name' => 'Commercial Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'COMM-2',
            'name' => 'Other Commercial Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Commercial Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$storeId, $otherStoreId, $customerId];
    }

    private function operationsUser(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Commercial Operations',
            'email' => 'commercial-operations@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
