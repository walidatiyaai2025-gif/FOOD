<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

        $assignmentId = (int) DB::table('user_store_roles')->where('user_id', $manager->id)->where('store_id', $storeId)->value('id');
        $this->actingAs($admin)->delete(route('admin.retail-stores.roles.remove', [$storeId, $assignmentId]))->assertRedirect();
        $this->assertSame([], app(TenantContextResolver::class)->retailStoreIds($manager));
    }

    public function test_retail_store_name_and_activation_control_linked_wholesale_account(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'owner-sync@example.test');
        $tierId = $this->priceTierId();

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
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

    public function test_retail_store_list_is_newest_first_and_newest_accordion_is_open(): void
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
            ->assertSee('store-accordion-body', false);

        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-store-accordion="'.$newer.'"\\s+open="open"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-store-accordion="'.$older.'"\\s+open="open"/', $html);
    }

    public function test_non_super_admin_cannot_provision_retail_store(): void
    {
        $b2b = $this->userWithRole('B2B_ADMIN', 'b2b@example.test');

        $this->actingAs($b2b)->get(route('admin.retail-stores.index'))->assertForbidden();
        $this->actingAs($b2b)->post(route('admin.retail-stores.store'), [
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
