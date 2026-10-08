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

    public function test_retail_store_provisioning_reuses_owner_user_and_linked_wholesale_account_without_duplication(): void
    {
        $manager = $this->user('canonical-owner@example.test');
        $store = $this->retailStore('CANONICAL-OWNER');
        $this->assignManager($manager, $store);

        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        $service = app(RetailWholesaleAccountService::class);
        $customer = $service->ensureForStore($store, $tierId, $manager);
        $account = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->first();

        $this->assertNotNull($account);
        $this->assertDatabaseHas('retail_wholesale_accounts', [
            'retail_store_id' => $store->id,
            'b2b_customer_id' => $customer->id,
            'owner_user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('b2b_customers', [
            'id' => $customer->id,
            'legacy_customer_id' => $customer->legacy_customer_id,
            'user_id' => null,
            'email' => $manager->email,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->legacy_customer_id,
            'type' => 'b2b',
            'email' => $manager->email,
        ]);
        $this->assertDatabaseHas('platform_customers', [
            'user_id' => $manager->id,
            'legacy_customer_id' => $customer->legacy_customer_id,
            'origin_channel' => 'b2c',
            'origin_store_id' => $store->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $manager->id,
            'is_platform_customer' => true,
        ]);

        $materialized = app(PlatformCustomerService::class)->materializeB2b($manager);
        $this->assertNotNull($materialized);
        $this->assertSame($customer->id, $materialized->id);

        $again = $service->ensureForStore($store, $tierId, $manager);
        $accountAgain = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->first();

        $this->assertSame($customer->id, $again->id);
        $this->assertSame((int) $account->id, (int) $accountAgain->id);
        $this->assertSame((int) $account->price_tier_id, (int) $accountAgain->price_tier_id);
        $this->assertSame(
            1,
            DB::table('platform_customers')->where('user_id', $manager->id)->count(),
        );
        $this->assertSame(
            1,
            DB::table('b2b_customers')
                ->where('legacy_customer_id', $customer->legacy_customer_id)
                ->count(),
        );

        $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.platform_customer', true)
            ->assertJsonPath('user.retail_merchant', true)
            ->assertJsonPath('user.b2b_customer_ids.0', $customer->id)
            ->assertJsonPath('user.retail_wholesale_accounts.0.retail_store_id', $store->id)
            ->assertJsonPath('user.retail_wholesale_accounts.0.b2b_customer_id', $customer->id);
    }

    public function test_canonical_identity_backfill_is_idempotent_and_preserves_existing_account_history_identity(): void
    {
        $manager = $this->user('backfill-owner@example.test');
        $store = $this->retailStore('BACKFILL-OWNER');
        $this->assignManager($manager, $store);

        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        $customer = app(RetailWholesaleAccountService::class)
            ->ensureForStore($store, $tierId, null);

        $accountBefore = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->id)
            ->first();

        $this->assertNotNull($accountBefore);
        $this->assertDatabaseHas('retail_wholesale_accounts', [
            'retail_store_id' => $store->id,
            'b2b_customer_id' => $customer->id,
            'owner_user_id' => null,
        ]);
        $this->assertSame(
            0,
            DB::table('platform_customers')->where('user_id', $manager->id)->count(),
        );

        $migration = require database_path(
            'migrations/2026_10_03_083000_reconcile_retail_merchant_canonical_identity.php',
        );
        $migration->up();
        $migration->up();

        $accountAfter = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->id)
            ->first();

        $this->assertNotNull($accountAfter);
        $this->assertSame((int) $accountBefore->id, (int) $accountAfter->id);
        $this->assertSame(
            (int) $accountBefore->price_tier_id,
            (int) $accountAfter->price_tier_id,
        );
        $this->assertDatabaseHas('retail_wholesale_accounts', [
            'retail_store_id' => $store->id,
            'b2b_customer_id' => $customer->id,
            'owner_user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('b2b_customers', [
            'id' => $customer->id,
            'legacy_customer_id' => $customer->legacy_customer_id,
            'email' => $manager->email,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->legacy_customer_id,
            'email' => $manager->email,
        ]);
        $this->assertDatabaseHas('platform_customers', [
            'user_id' => $manager->id,
            'legacy_customer_id' => $customer->legacy_customer_id,
            'origin_channel' => 'b2c',
            'origin_store_id' => $store->id,
            'registration_source' => 'migration',
            'is_active' => true,
        ]);
        $this->assertSame(
            1,
            DB::table('platform_customers')->where('user_id', $manager->id)->count(),
        );
        $this->assertSame(
            1,
            DB::table('b2b_customers')
                ->where('legacy_customer_id', $customer->legacy_customer_id)
                ->count(),
        );

        $materialized = app(PlatformCustomerService::class)->materializeB2b($manager);
        $this->assertNotNull($materialized);
        $this->assertSame($customer->id, $materialized->id);

        $this->postJson('/api/v1/auth/login', [
            'email' => $manager->email,
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.platform_customer', true)
            ->assertJsonPath('user.retail_merchant', true)
            ->assertJsonPath('user.b2b_customer_ids.0', $customer->id);
    }

    public function test_retail_manager_can_keep_wholesale_purchase_context_without_owner_finance_access(): void
    {
        $owner = $this->user('finance-owner-merchant@example.test');
        [$store, $b2bCustomerId] = $this->managedStore($owner, 'OWNER-FINANCE', true);

        $manager = $this->user('finance-manager-staff@example.test');
        $this->assignManager($manager, $store);

        $request = Request::create('/api/v1/b2b/products', 'GET');
        $request->headers->set('X-FOODEX-Retail-Store-ID', (string) $store->id);

        $this->assertSame(
            $b2bCustomerId,
            (int) app(CustomerDomainResolver::class)
                ->b2bFromRequest($manager, $request)
                ->getKey(),
        );

        Sanctum::actingAs($manager);

        $this->withHeader('X-FOODEX-Retail-Store-ID', (string) $store->id)
            ->getJson('/api/v1/b2b/account-summary')
            ->assertForbidden();

        $this->withHeader('X-FOODEX-Retail-Store-ID', (string) $store->id)
            ->getJson('/api/v1/b2b/dashboard')
            ->assertForbidden();

        Sanctum::actingAs($owner);

        $this->withHeader('X-FOODEX-Retail-Store-ID', (string) $store->id)
            ->getJson('/api/v1/b2b/account-summary')
            ->assertOk();

        $this->withHeader('X-FOODEX-Retail-Store-ID', (string) $store->id)
            ->getJson('/api/v1/b2b/dashboard')
            ->assertOk()
            ->assertJsonPath('customer.id', $b2bCustomerId);
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
                'Retail merchants cannot purchase from a Retail Store they own or manage.',
                $exception->getMessage(),
            );

            return;
        }

        $this->fail('Owned/managed Retail Store must reject B2C customer commerce for its merchant account.');
    }
}
