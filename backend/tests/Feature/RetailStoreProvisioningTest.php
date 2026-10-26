<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreFeatureService;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use App\Services\RetailMerchantIdentityService;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RetailStoreProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_super_admin_can_provision_store_and_manager_then_revocation_blocks_access(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner@example.test');
        $tierId = $this->priceTierId();
        $customerTierId = (int) DB::table('b2b_price_tiers')->where('code', 'WHOLESALE')->value('id');

        $response = $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'SHOP-A',
            'name' => 'Shop A',
            'logo' => UploadedFile::fake()->image('shop-a.png', 256, 256),
            'price_tier_id' => $tierId,
            'default_customer_wholesale_price_tier_id' => $customerTierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Shop A Manager',
            'manager_email' => 'manager-a@example.test',
            'manager_password' => 'password123',
        ]);

        $response->assertRedirect(route('admin.retail-stores.index'));
        $storeId = (int) DB::table('stores')->where('code', 'SHOP-A')->value('id');
        $manager = User::query()->where('email', 'manager-a@example.test')->firstOrFail();
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        $this->assertDatabaseHas('user_store_roles', ['user_id' => $manager->id, 'store_id' => $storeId, 'role_id' => $roleId]);
        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'default_customer_wholesale_price_tier_id' => $customerTierId,
        ]);
        $link = DB::table('retail_wholesale_accounts')->where('retail_store_id', $storeId)->first();
        $this->assertNotNull($link);
        $this->assertSame($manager->id, (int) $link->owner_user_id);
        $this->assertDatabaseHas('b2b_customers', [
            'id' => $link->b2b_customer_id,
            'name' => 'Shop A',
        ]);
        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $link->b2b_customer_id,
            'company_name' => 'Shop A',
            'price_tier_id' => $tierId,
            'status' => 'active',
        ]);
        $logoPath = (string) DB::table('stores')->where('id', $storeId)->value('logo_path');
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', $logoPath);
        Storage::disk('public')->assertExists(substr($logoPath, strlen('storage/')));
        $this->assertSame([$storeId], app(TenantContextResolver::class)->retailStoreIds($manager));
        $legacyCustomerId = (int) DB::table('b2b_customers')
            ->where('id', $link->b2b_customer_id)
            ->value('legacy_customer_id');
        $this->assertDatabaseHas('platform_customers', [
            'user_id' => $manager->id,
            'legacy_customer_id' => $legacyCustomerId,
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.retail-stores.index'))
            ->assertOk()
            ->assertSee('manager-a@example.test')
            ->assertSee('Customer App identity')
            ->assertSee('Linked / active')
            ->assertSee('Wholesale purchasing account')
            ->assertSee('No separate Wholesale password.');

        $assignmentId = (int) DB::table('user_store_roles')->where('user_id', $manager->id)->where('store_id', $storeId)->value('id');
        $this->actingAs($admin)->delete(route('admin.retail-stores.roles.remove', [$storeId, $assignmentId]))->assertRedirect();
        $this->assertSame([], app(TenantContextResolver::class)->retailStoreIds($manager));
    }

    public function test_super_admin_can_reassign_primary_owner_without_changing_linked_wholesale_account(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner-reassign@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'OWNER-SWAP',
            'name' => 'Owner Swap',
            'logo' => UploadedFile::fake()->image('owner-swap.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'advertising_enabled' => '1',
            'live_ads_enabled' => '1',
            'coupons_enabled' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Original Owner',
            'manager_email' => 'original-owner@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'OWNER-SWAP')->value('id');
        $oldOwner = User::query()->where('email', 'original-owner@example.test')->firstOrFail();
        $newOwner = $this->user('new-owner@example.test');
        $managerRoleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        $linkBefore = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $storeId)
            ->first();
        $this->assertNotNull($linkBefore);
        $accountBefore = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $linkBefore->b2b_customer_id)
            ->first();
        $this->assertNotNull($accountBefore);

        $this->actingAs($admin)->patch(route('admin.retail-stores.update', $storeId), [
            'code' => 'OWNER-SWAP',
            'name' => 'Owner Swap',
            'price_tier_id' => $tierId,
            'primary_owner_user_id' => $newOwner->id,
            'is_active' => '1',
            'advertising_enabled' => '1',
            'live_ads_enabled' => '1',
            'coupons_enabled' => '1',
        ])->assertRedirect();

        $linkAfter = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $storeId)
            ->first();
        $this->assertNotNull($linkAfter);
        $accountAfter = DB::table('b2b_accounts')
            ->where('b2b_customer_id', $linkAfter->b2b_customer_id)
            ->first();
        $this->assertNotNull($accountAfter);

        $this->assertSame($newOwner->id, (int) $linkAfter->owner_user_id);
        $this->assertSame((int) $linkBefore->b2b_customer_id, (int) $linkAfter->b2b_customer_id);
        $this->assertSame((int) $accountBefore->id, (int) $accountAfter->id);
        $this->assertSame((int) $accountBefore->price_tier_id, (int) $accountAfter->price_tier_id);

        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $oldOwner->id,
            'store_id' => $storeId,
            'role_id' => $managerRoleId,
        ]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $newOwner->id,
            'store_id' => $storeId,
            'role_id' => $managerRoleId,
        ]);

        $merchantIdentity = app(RetailMerchantIdentityService::class);
        $this->assertSame([], $merchantIdentity->retailStoreIds($oldOwner));
        $this->assertSame([$storeId], $merchantIdentity->retailStoreIds($newOwner));
        $this->assertSame([], app(CustomerDomainResolver::class)->entitledRetailStoreIds($oldOwner));
        $this->assertSame([$storeId], app(CustomerDomainResolver::class)->entitledRetailStoreIds($newOwner));

        $legacyCustomerId = (int) DB::table('b2b_customers')
            ->where('id', $linkAfter->b2b_customer_id)
            ->value('legacy_customer_id');
        $newOwnerPlatform = DB::table('platform_customers')
            ->where('user_id', $newOwner->id)
            ->first();
        $this->assertNotNull($newOwnerPlatform);
        $this->assertTrue((bool) $newOwnerPlatform->is_active);
        $this->assertNotSame($legacyCustomerId, (int) $newOwnerPlatform->legacy_customer_id);
        $this->assertDatabaseHas('platform_customers', [
            'user_id' => $oldOwner->id,
            'legacy_customer_id' => $legacyCustomerId,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('b2b_customers', [
            'id' => $linkAfter->b2b_customer_id,
            'email' => $newOwner->email,
        ]);

        $materialized = app(PlatformCustomerService::class)->materializeB2b($newOwner);
        $this->assertNotNull($materialized);
        $this->assertSame((int) $linkAfter->b2b_customer_id, (int) $materialized->id);
        $this->assertSame(
            1,
            DB::table('b2b_customers')
                ->where('id', $linkAfter->b2b_customer_id)
                ->count(),
        );

        $audit = DB::table('audit_logs')
            ->where('event', 'retail_store.owner_reassigned')
            ->where('auditable_id', $storeId)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $before = json_decode((string) $audit->before, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode((string) $audit->after, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($oldOwner->id, (int) $before['owner_user_id']);
        $this->assertSame($newOwner->id, (int) $after['owner_user_id']);
        $this->assertSame((int) $linkBefore->b2b_customer_id, (int) $before['b2b_customer_id']);
        $this->assertSame((int) $linkAfter->b2b_customer_id, (int) $after['b2b_customer_id']);
    }

    public function test_removing_primary_owner_manager_role_clears_owner_link_and_merchant_entitlement(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner-revoke@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'OWNER-REVOKE',
            'name' => 'Owner Revoke',
            'logo' => UploadedFile::fake()->image('owner-revoke.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Revoked Owner',
            'manager_email' => 'revoked-owner@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'OWNER-REVOKE')->value('id');
        $owner = User::query()->where('email', 'revoked-owner@example.test')->firstOrFail();
        $managerRoleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        $linkBefore = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $storeId)
            ->first();
        $this->assertNotNull($linkBefore);

        $assignmentId = (int) DB::table('user_store_roles')
            ->where('user_id', $owner->id)
            ->where('store_id', $storeId)
            ->where('role_id', $managerRoleId)
            ->value('id');

        $this->actingAs($admin)
            ->delete(route('admin.retail-stores.roles.remove', [$storeId, $assignmentId]))
            ->assertRedirect();

        $linkAfter = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $storeId)
            ->first();
        $this->assertNotNull($linkAfter);
        $this->assertNull($linkAfter->owner_user_id);
        $this->assertSame((int) $linkBefore->b2b_customer_id, (int) $linkAfter->b2b_customer_id);
        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $owner->id,
            'store_id' => $storeId,
            'role_id' => $managerRoleId,
        ]);

        $merchantIdentity = app(RetailMerchantIdentityService::class);
        $this->assertSame([], $merchantIdentity->retailStoreIds($owner));
        $this->assertSame([], app(CustomerDomainResolver::class)->entitledRetailStoreIds($owner));

        $audit = DB::table('audit_logs')
            ->where('event', 'retail_store.owner_revoked')
            ->where('auditable_id', $storeId)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $before = json_decode((string) $audit->before, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode((string) $audit->after, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($owner->id, (int) $before['owner_user_id']);
        $this->assertNull($after['owner_user_id']);
        $this->assertSame((int) $linkBefore->b2b_customer_id, (int) $after['b2b_customer_id']);
    }

    public function test_retail_store_name_and_activation_control_linked_wholesale_account(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner-sync@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'SHOP-SYNC',
            'name' => 'Shop Sync',
            'logo' => UploadedFile::fake()->image('shop-sync.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Shop Sync Manager',
            'manager_email' => 'manager-sync@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'SHOP-SYNC')->value('id');
        $b2bCustomerId = (int) DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $storeId)
            ->value('b2b_customer_id');

        $this->actingAs($admin)->patch(route('admin.retail-stores.update', $storeId), [
            'code' => 'SHOP-SYNC',
            'name' => 'Shop Sync Renamed',
            'price_tier_id' => $tierId,
            'is_active' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('b2b_customers', [
            'id' => $b2bCustomerId,
            'name' => 'Shop Sync Renamed',
        ]);
        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $b2bCustomerId,
            'company_name' => 'Shop Sync Renamed',
            'status' => 'suspended',
        ]);
    }

    public function test_retail_manager_wholesale_entitlement_is_tenant_scoped(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner-entitlements@example.test');
        $tierId = $this->priceTierId();

        foreach ([
            ['ENT-A', 'Entitled A', 'manager-ent-a@example.test'],
            ['ENT-B', 'Entitled B', 'manager-ent-b@example.test'],
        ] as [$code, $name, $email]) {
            $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
                'business_profile' => 'RETAIL_STORE',
                'code' => $code,
                'name' => $name,
                'price_tier_id' => $tierId,
                'is_active' => '1',
                'logo' => UploadedFile::fake()->image(strtolower($code).'.png', 256, 256),
                'manager_mode' => 'new',
                'manager_name' => $name.' Manager',
                'manager_email' => $email,
                'manager_password' => 'password123',
            ])->assertRedirect(route('admin.retail-stores.index'));
        }

        $storeA = (int) DB::table('stores')->where('code', 'ENT-A')->value('id');
        $storeB = (int) DB::table('stores')->where('code', 'ENT-B')->value('id');
        $managerA = User::query()->where('email', 'manager-ent-a@example.test')->firstOrFail();
        $resolver = app(CustomerDomainResolver::class);

        $requestA = Request::create('/api/v1/b2b/products', 'GET');
        $requestA->headers->set('X-FOODEX-Retail-Store-ID', (string) $storeA);
        $customerA = $resolver->b2bFromRequest($managerA, $requestA);

        $this->assertSame(
            (int) DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $storeA)
                ->value('b2b_customer_id'),
            (int) $customerA->id,
        );
        $this->assertSame([$storeA], $resolver->entitledRetailStoreIds($managerA));

        $foreign = Request::create('/api/v1/b2b/products', 'GET');
        $foreign->headers->set('X-FOODEX-Retail-Store-ID', (string) $storeB);

        try {
            $resolver->b2bFromRequest($managerA, $foreign);
            $this->fail('Retail manager must not resolve another store wholesale entitlement.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_super_admin_can_open_retail_store_list_with_unlinked_legacy_store(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'legacy-list@example.test');
        $this->retailStore('UNLINKED');

        $this->withoutExceptionHandling();

        $this->actingAs($admin)
            ->get(route('admin.retail-stores.index'))
            ->assertOk()
            ->assertSee('Retail Store Provisioning');
    }

    public function test_retail_store_list_is_newest_first_and_add_store_is_primary_open_accordion(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'accordion-owner@example.test');
        $older = $this->retailStore('OLDER');
        $newer = $this->retailStore('NEWER');

        DB::table('stores')->where('id', $older)->update(['created_at' => now()->subDay()]);
        DB::table('stores')->where('id', $newer)->update(['created_at' => now()]);

        $response = $this->actingAs($admin)
            ->get(route('admin.retail-stores.index'));

        $response
            ->assertOk()
            ->assertSeeInOrder(['Test NEWER', 'Test OLDER'])
            ->assertSee('store-accordion-body', false)
            ->assertSee('data-page-accordion="add-store" open', false)
            ->assertSee('data-page-accordion="stores"', false)
            ->assertSee('data-page-accordion="operations"', false);

        $html = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-store-accordion="'.$newer.'"[^>]*\\sopen(?:="open")?/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-store-accordion="'.$older.'"[^>]*\\sopen(?:="open")?/', $html);
    }

    public function test_retail_profile_creation_never_provisions_restaurant_capabilities(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'retail-profile-owner@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'PROFILE-RETAIL',
            'name' => 'Profile Retail',
            'logo' => UploadedFile::fake()->image('profile-retail.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Retail Profile Owner',
            'manager_email' => 'retail-profile-manager@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'PROFILE-RETAIL')->value('id');
        $this->assertGreaterThan(0, $storeId);
        $this->assertFalse(app(RestaurantSaasStoreFeatureService::class)->isEnabled($storeId));
        $this->assertDatabaseMissing('smart_ordering_sites', ['store_id' => $storeId]);
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'store',
            'scope_id' => $storeId,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('retail_wholesale_accounts', ['retail_store_id' => $storeId]);

        if (Schema::hasColumn('stores', 'business_profile')) {
            $this->assertDatabaseHas('stores', [
                'id' => $storeId,
                'business_profile' => 'RETAIL_STORE',
            ]);
        }
    }

    public function test_restaurant_profile_creation_provisions_workspace_branch_and_wholesale_identity(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'restaurant-profile-owner@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RESTAURANT',
            'code' => 'PROFILE-REST',
            'name' => 'Profile Restaurant',
            'logo' => UploadedFile::fake()->image('profile-restaurant.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Restaurant Profile Owner',
            'manager_email' => 'restaurant-profile-manager@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'PROFILE-REST')->value('id');
        $siteId = (int) DB::table('smart_ordering_sites')->where('store_id', $storeId)->value('id');

        $this->assertGreaterThan(0, $storeId);
        $this->assertGreaterThan(0, $siteId);
        $this->assertTrue(app(RestaurantSaasStoreFeatureService::class)->isEnabled($storeId));
        $this->assertDatabaseHas('retail_wholesale_accounts', ['retail_store_id' => $storeId]);
        $this->assertDatabaseHas('smart_ordering_branches', [
            'site_id' => $siteId,
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => false,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'store',
            'scope_id' => $storeId,
            'is_enabled' => true,
        ]);

        if (Schema::hasColumn('stores', 'business_profile')) {
            $this->assertDatabaseHas('stores', [
                'id' => $storeId,
                'business_profile' => 'RESTAURANT',
            ]);
        }
    }

    public function test_failed_restaurant_provisioning_rolls_back_store_site_wholesale_identity_and_logo(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'restaurant-rollback-owner@example.test');
        $tierId = $this->priceTierId();

        Role::query()->where('code', 'B2C_STORE_ADMIN')->update(['is_active' => false]);

        $before = [
            'wholesale_links' => DB::table('retail_wholesale_accounts')->count(),
            'b2b_customers' => DB::table('b2b_customers')->count(),
            'b2b_accounts' => DB::table('b2b_accounts')->count(),
            'sites' => DB::table('smart_ordering_sites')->count(),
            'branches' => DB::table('smart_ordering_branches')->count(),
            'module_entitlements' => DB::table('enterprise_module_entitlements')->count(),
        ];

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RESTAURANT',
            'code' => 'PROFILE-ROLLBACK',
            'name' => 'Rollback Restaurant',
            'logo' => UploadedFile::fake()->image('rollback-restaurant.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Rollback Owner',
            'manager_email' => 'rollback-profile-manager@example.test',
            'manager_password' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('stores', ['code' => 'PROFILE-ROLLBACK']);
        $this->assertDatabaseMissing('users', ['email' => 'rollback-profile-manager@example.test']);
        $this->assertSame($before['wholesale_links'], DB::table('retail_wholesale_accounts')->count());
        $this->assertSame($before['b2b_customers'], DB::table('b2b_customers')->count());
        $this->assertSame($before['b2b_accounts'], DB::table('b2b_accounts')->count());
        $this->assertSame($before['sites'], DB::table('smart_ordering_sites')->count());
        $this->assertSame($before['branches'], DB::table('smart_ordering_branches')->count());
        $this->assertSame($before['module_entitlements'], DB::table('enterprise_module_entitlements')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_store_create_is_a_gated_popup_wizard_with_final_review(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'wizard-owner@example.test');
        $this->retailStore('WIZARD-PROFILE');

        $response = $this->actingAs($admin)
            ->get(route('admin.retail-stores.index'));

        $response
            ->assertOk()
            ->assertSee('data-open-store-wizard', false)
            ->assertSee('data-store-create-modal', false)
            ->assertSee('data-foodex-store-wizard', false)
            ->assertSee('name="business_profile"', false)
            ->assertSee('value="RETAIL_STORE"', false)
            ->assertSee('value="RESTAURANT"', false)
            ->assertDontSee('data-restaurant-saas-toggle', false)
            ->assertSee('data-business-profile-summary', false)
            ->assertSee('data-wizard-panel="identity"', false)
            ->assertSee('data-wizard-panel="branding"', false)
            ->assertSee('data-wizard-panel="pricing"', false)
            ->assertSee('data-wizard-panel="campaigns"', false)
            ->assertSee('data-wizard-panel="manager"', false)
            ->assertSee('data-wizard-panel="review"', false)
            ->assertSee('data-wizard-final-submit', false)
            ->assertSee('validateStep(from)', false)
            ->assertSee("currentStep !== 'review'", false);
    }

    public function test_invalid_manager_cannot_persist_a_partial_store(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'wizard-validation-owner@example.test');
        $tierId = $this->priceTierId();

        $response = $this->actingAs($admin)->from(route('admin.retail-stores.index'))->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'NO-PARTIAL',
            'name' => 'No Partial Store',
            'logo' => UploadedFile::fake()->image('no-partial.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'advertising_enabled' => '1',
            'live_ads_enabled' => '1',
            'coupons_enabled' => '1',
            'manager_mode' => 'existing',
            'manager_user_id' => '',
        ]);

        $response
            ->assertRedirect(route('admin.retail-stores.index'))
            ->assertSessionHasErrors('manager_user_id');

        $this->assertDatabaseMissing('stores', ['code' => 'NO-PARTIAL']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_non_super_admin_cannot_provision_retail_store(): void
    {
        $b2b = $this->userWithRole('B2B_ADMIN', 'b2b@example.test');

        $this->actingAs($b2b)->get(route('admin.retail-stores.index'))->assertForbidden();
        $this->actingAs($b2b)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'FORBIDDEN',
            'name' => 'Forbidden',
            'manager_mode' => 'existing',
            'manager_user_id' => $b2b->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('stores', ['code' => 'FORBIDDEN']);
    }

    public function test_store_admin_cannot_access_another_store_and_deactivation_revokes_context(): void
    {
        $storeA = $this->retailStore('A');
        $storeB = $this->retailStore('B');
        $manager = $this->user('manager@example.test');
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id, 'store_id' => $storeA, 'role_id' => $roleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolver = app(TenantContextResolver::class);
        $this->assertSame($storeA, $resolver->retail($manager, $storeA)->storeId);

        try {
            $resolver->retail($manager, $storeB);
            $this->fail('Foreign store context must be denied.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        DB::table('stores')->where('id', $storeA)->update(['is_active' => false]);

        try {
            $resolver->retail($manager, $storeA);
            $this->fail('Deactivated store must not resolve.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_super_admin_inspect_entry_is_explicit_and_audited(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'support@example.test');
        $storeId = $this->retailStore('SUPPORT');

        $this->actingAs($admin)->post(route('admin.retail-stores.inspect', $storeId))
            ->assertRedirect(route('admin.b2c.dashboard', ['store_id' => $storeId, 'support_access' => 1]));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $storeId,
        ]);
    }

    private function priceTierId(): int
    {
        return (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
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

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => "TEST-{$code}",
            'name' => "Test {$code}",
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
