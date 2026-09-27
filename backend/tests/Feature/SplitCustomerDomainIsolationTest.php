<?php

namespace Tests\Feature;

use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SplitCustomerDomainIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2c_customer_cannot_materialize_or_open_foreign_store_domain(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeA = $this->store('B2C', 'ISO-A');
        $storeB = $this->store('B2C', 'ISO-B');

        $user = $this->user('iso-a@example.test');
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => 'Store A Customer',
            'email' => 'shared-contact@example.test',
        ]);
        $customerA = B2cCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'store_id' => $storeA,
            'user_id' => $user->id,
            'name' => 'Store A Customer',
            'email' => 'shared-contact@example.test',
        ]);

        app(B2cCustomerService::class)->create($storeB, [
            'name' => 'Independent Store B Customer',
            'email' => 'shared-contact@example.test',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile?store='.$storeA)
            ->assertOk()
            ->assertJsonPath('customer.id', $customerA->id)
            ->assertJsonPath('customer.store_id', $storeA);

        $this->getJson('/api/v1/profile?store='.$storeB)->assertNotFound();

        $this->assertDatabaseMissing('b2c_customers', [
            'user_id' => $user->id,
            'store_id' => $storeB,
        ]);
        $this->assertSame(
            2,
            B2cCustomer::query()->where('email', 'shared-contact@example.test')->count(),
        );
    }

    public function test_b2c_order_ids_are_store_scoped_and_foreign_id_tampering_is_hidden(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeA = $this->store('B2C', 'ORDER-A');
        $storeB = $this->store('B2C', 'ORDER-B');

        [$userA, $customerA] = $this->retailCustomer($storeA, 'order-a@example.test');
        [, $customerB] = $this->retailCustomer($storeB, 'order-b@example.test');

        $orderA = $this->order($storeA, 'b2c', $customerA->legacy_customer_id, null, $customerA->id, 'ISO-ORDER-A');
        $orderB = $this->order($storeB, 'b2c', $customerB->legacy_customer_id, null, $customerB->id, 'ISO-ORDER-B');

        Sanctum::actingAs($userA);

        $this->getJson('/api/v1/orders?store='.$storeA)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderA);

        $this->getJson('/api/v1/orders/'.$orderB.'?store='.$storeA)->assertNotFound();
        $this->getJson('/api/v1/orders?store='.$storeB)->assertNotFound();
    }

    public function test_b2b_and_b2c_customer_domains_cannot_cross_channels(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $retailStore = $this->store('B2C', 'CHANNEL-B2C');
        $wholesaleStore = $this->store('B2B', 'CHANNEL-B2B');

        [$retailUser, $retailCustomer] = $this->retailCustomer($retailStore, 'retail-channel@example.test');

        $wholesaleUser = $this->user('wholesale-channel@example.test');
        $wholesaleCustomer = app(B2bCustomerService::class)->create([
            'name' => 'Wholesale Customer',
            'email' => $wholesaleUser->email,
        ], $wholesaleUser);

        $b2bOrder = $this->order(
            $wholesaleStore,
            'b2b',
            $wholesaleCustomer->legacy_customer_id,
            $wholesaleCustomer->id,
            null,
            'ISO-B2B-ORDER',
        );
        $this->order(
            $retailStore,
            'b2c',
            $retailCustomer->legacy_customer_id,
            null,
            $retailCustomer->id,
            'ISO-B2C-ORDER',
        );

        Sanctum::actingAs($retailUser);
        $this->getJson('/api/v1/b2b/orders')->assertForbidden();

        Sanctum::actingAs($wholesaleUser);
        $this->getJson('/api/v1/b2b/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b2bOrder);
        $this->getJson('/api/v1/orders?store='.$retailStore)->assertNotFound();
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: B2cCustomer} */
    private function retailCustomer(int $storeId, string $email): array
    {
        $user = $this->user($email);
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Retail Customer',
            'email' => $email,
        ], $user);

        return [$user, $customer];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Isolation User',
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function order(
        int $storeId,
        string $channel,
        int $legacyCustomerId,
        ?int $b2bCustomerId,
        ?int $b2cCustomerId,
        string $number,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'b2c_customer_id' => $b2cCustomerId,
            'order_number' => $number,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
