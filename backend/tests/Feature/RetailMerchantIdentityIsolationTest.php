<?php

namespace Tests\Feature;

use App\Exceptions\SelfStorePurchaseNotAllowed;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use App\Services\RetailMerchantIdentityService;
use App\Services\RetailWholesaleAccountService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetailMerchantIdentityIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_dashboard_retail_manager_resolves_authoritative_wholesale_identity_for_multiple_stores(): void
    {
        $manager = $this->user('merchant-multi@example.test');
        [$storeA, $b2bCustomerA] = $this->managedStore($manager, 'MERCHANT-A', true);
        [$storeB, $b2bCustomerB] = $this->managedStore($manager, 'MERCHANT-B', false);

        $identity = app(RetailMerchantIdentityService::class)->identityPayload($manager);

        $this->assertTrue($identity['retail_merchant']);
        $this->assertSame([$storeA->id], $identity['owned_retail_store_ids']);
        $this->assertSame([$storeA->id, $storeB->id], $identity['managed_retail_store_ids']);
        $this->assertSame([$storeA->id, $storeB->id], $identity['retail_store_ids']);
        $this->assertSame([$b2bCustomerA, $b2bCustomerB], $identity['b2b_customer_ids']);
        $this->assertSame([
            ['retail_store_id' => $storeA->id, 'b2b_customer_id' => $b2bCustomerA],
            ['retail_store_id' => $storeB->id, 'b2b_customer_id' => $b2bCustomerB],
        ], $identity['retail_wholesale_accounts']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.retail_merchant', true)
            ->assertJsonPath('user.owned_retail_store_ids', [$storeA->id])
            ->assertJsonPath('user.managed_retail_store_ids', [$storeA->id, $storeB->id])
            ->assertJsonPath('user.retail_store_ids', [$storeA->id, $storeB->id])
            ->assertJsonPath('user.retail_wholesale_accounts.0.retail_store_id', $storeA->id)
            ->assertJsonPath('user.retail_wholesale_accounts.0.b2b_customer_id', $b2bCustomerA)
            ->assertJsonPath('user.retail_wholesale_accounts.1.retail_store_id', $storeB->id)
            ->assertJsonPath('user.retail_wholesale_accounts.1.b2b_customer_id', $b2bCustomerB);

        $this->assertNotEmpty($login->json('token'));

        $resolver = app(CustomerDomainResolver::class);
        $this->assertSame([$storeA->id, $storeB->id], $resolver->entitledRetailStoreIds($manager));

        foreach ([[$storeA, $b2bCustomerA], [$storeB, $b2bCustomerB]] as [$store, $expectedCustomerId]) {
            $request = Request::create('/api/v1/b2b/products', 'GET');
            $request->headers->set('X-FOODEX-Retail-Store-ID', (string) $store->id);

            $this->assertSame(
                $expectedCustomerId,
                (int) $resolver->b2bFromRequest($manager, $request)->getKey(),
            );
        }
    }

    public function test_self_store_guard_blocks_existing_customer_cart_profile_and_checkout_paths(): void
    {
        $store = $this->retailStore('SELF-STORE');
        $user = $this->platformCustomer('self-store@example.test');

        // Materialize a historical B2C projection before this account becomes the
        // Retail merchant. The ownership guard must still make it unusable later.
        $projection = app(PlatformCustomerService::class)->materializeB2c($user, $store->id);
        $this->assertNotNull($projection);

        $this->assignManager($user, $store);

        $this->assertSelfStoreForbidden(
            fn () => app(CustomerDomainResolver::class)->b2c($user, $store->id),
        );
        $this->assertSelfStoreForbidden(
            fn () => app(PlatformCustomerService::class)->materializeB2c($user, $store->id),
        );
        $this->assertSelfStoreForbidden(
            fn () => app(B2cCustomerService::class)->create($store->id, [
                'name' => $user->name,
                'email' => $user->email,
            ], $user),
        );

        $guestCart = $this->getJson('/api/v1/cart?store='.$store->id)->assertOk();
        $guestToken = (string) $guestCart->headers->get('X-Guest-Token');
        $this->assertNotSame('', $guestToken);

        Sanctum::actingAs($user);

        $this->withHeader('X-Guest-Token', $guestToken)
            ->getJson('/api/v1/cart?store='.$store->id)
            ->assertForbidden()
            ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE);

        $this->assertDatabaseHas('carts', [
            'store_id' => $store->id,
            'guest_token' => $guestToken,
            'channel' => 'b2c',
        ]);

        $this->withHeader('X-Guest-Token', '')
            ->getJson('/api/v1/cart?store='.$store->id)
            ->assertForbidden()
            ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $store->id,
            'product_id' => 1,
            'quantity' => 1,
        ])->assertForbidden()
            ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE);

        $this->getJson('/api/v1/profile?store_id='.$store->id)
            ->assertForbidden()
            ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE);

        $this->withHeader('Idempotency-Key', 'self-store-checkout-0001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $store->id,
                'address_id' => 1,
            ])->assertForbidden()
            ->assertJsonPath('code', SelfStorePurchaseNotAllowed::ERROR_CODE);

        $this->assertDatabaseHas('b2c_customers', [
            'id' => $projection->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_owned_store_block_is_multi_store_scoped_and_platform_customer_identity_is_not_duplicated(): void
    {
        $user = $this->platformCustomer('merchant-customer@example.test');
        $foreignA = $this->retailStore('FOREIGN-A');
        $foreignB = $this->retailStore('FOREIGN-B');

        $membershipA = app(PlatformCustomerService::class)->materializeB2c($user, $foreignA->id);
        $membershipAAgain = app(PlatformCustomerService::class)->materializeB2c($user, $foreignA->id);
        $membershipB = app(PlatformCustomerService::class)->materializeB2c($user, $foreignB->id);

        $this->assertSame($membershipA->id, $membershipAAgain->id);
        $this->assertSame($membershipA->legacy_customer_id, $membershipB->legacy_customer_id);
        $this->assertSame(1, DB::table('platform_customers')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('users')->where('email', $user->email)->count());

        [$ownedA] = $this->managedStore($user, 'OWNED-A');
        [$ownedB] = $this->managedStore($user, 'OWNED-B');

        $identity = app(RetailMerchantIdentityService::class);
        $this->assertSame([$ownedA->id, $ownedB->id], $identity->ownedRetailStoreIds($user));
        $this->assertSame([$ownedA->id, $ownedB->id], $identity->managedRetailStoreIds($user));
        $this->assertSame([$ownedA->id, $ownedB->id], $identity->retailStoreIds($user));

        $this->assertSelfStoreForbidden(
            fn () => app(CustomerDomainResolver::class)->b2c($user, $ownedA->id),
        );
        $this->assertSelfStoreForbidden(
            fn () => app(CustomerDomainResolver::class)->b2c($user, $ownedB->id),
        );

        $this->assertSame(
            $membershipA->id,
            app(CustomerDomainResolver::class)->b2c($user, $foreignA->id)->id,
        );
        $this->assertSame(
            $membershipB->id,
            app(CustomerDomainResolver::class)->b2c($user, $foreignB->id)->id,
        );
    }

    /** @return array{0:Store,1:int} */
    private function managedStore(User $manager, string $code, bool $owner = true): array
    {
        $store = $this->retailStore($code);
        $this->assignManager($manager, $store);

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $customer = app(RetailWholesaleAccountService::class)->ensureForStore($store, $tierId, $owner ? $manager : null);

        return [$store, (int) $customer->getKey()];
    }

    private function assignManager(User $user, Store $store): void
    {
        $roleId = (int) Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('is_active', true)
            ->value('id');

        DB::table('user_store_roles')->updateOrInsert(
            [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'role_id' => $roleId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => 'Store '.$code,
            'is_active' => true,
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function platformCustomer(string $email): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => $email,
            'email' => $email,
            'phone' => '+96550000000',
            'password' => 'password123',
            'locale' => 'en',
        ]);
    }

    private function assertSelfStoreForbidden(callable $callback): void
    {
        try {
            $callback();
        } catch (SelfStorePurchaseNotAllowed $exception) {
            $this->assertGreaterThan(0, $exception->storeId);
            $this->assertSame(
                SelfStorePurchaseNotAllowed::ERROR_CODE,
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('Owned/managed Retail Store must reject B2C customer commerce for its merchant account.');
    }
}
