<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RestaurantSaaSControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_retail_store_is_mapped_without_duplicate_identity_records(): void
    {
        $storeId = $this->retailStore('RS-CP-A');
        $admin = $this->storeAdmin($storeId);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$storeId)
            ->assertOk()
            ->assertJsonPath('provisioned', false)
            ->assertJsonPath('location.id', $storeId)
            ->assertJsonPath('location.code', 'RS-CP-A')
            ->assertJsonPath('account', null)
            ->assertJsonPath('brand', null);

        $response = $this->postJson('/api/v1/admin/restaurant-saas/locations/'.$storeId.'/provision')
            ->assertOk()
            ->assertJsonPath('provisioned', true)
            ->assertJsonPath('account.code', 'foodex_platform')
            ->assertJsonPath('organization.code', 'foodex_platform')
            ->assertJsonPath('brand.code', 'default_company')
            ->assertJsonPath('location.id', $storeId)
            ->assertJsonPath('location.lifecycle_state', 'DRAFT')
            ->assertJsonPath('entitlement_scope', 'store:'.$storeId);

        $this->assertIsString($response->json('smart_ordering_site.id'));
        $this->assertDatabaseCount('restaurant_location_profiles', 1);
        $this->assertDatabaseCount('enterprise_store_scopes', 1);
        $this->assertDatabaseCount('smart_ordering_sites', 1);
        $this->assertDatabaseHas('stores', ['id' => $storeId, 'code' => 'RS-CP-A', 'name' => 'RS-CP-A']);

        $this->postJson('/api/v1/admin/restaurant-saas/locations/'.$storeId.'/provision')
            ->assertOk()
            ->assertJsonPath('provisioned', true);

        $this->assertDatabaseCount('restaurant_location_profiles', 1);
        $this->assertDatabaseCount('enterprise_store_scopes', 1);
        $this->assertDatabaseCount('smart_ordering_sites', 1);
    }

    public function test_location_profile_is_additive_validated_and_audited(): void
    {
        $storeId = $this->retailStore('RS-CP-B');
        $admin = $this->storeAdmin($storeId);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/restaurant-saas/locations/'.$storeId.'/provision')
            ->assertOk();

        $this->patchJson('/api/v1/admin/restaurant-saas/locations/'.$storeId, [
            'lifecycle_state' => 'ACTIVE',
            'timezone' => 'Asia/Kuwait',
            'currency' => 'KWD',
            'country_code' => 'KW',
            'business_name' => 'Control Plane Cafe',
            'legal_name' => 'Control Plane Cafe LLC',
            'tax_registration_number' => 'KW-TAX-100',
        ])
            ->assertOk()
            ->assertJsonPath('location.lifecycle_state', 'ACTIVE')
            ->assertJsonPath('location.timezone', 'Asia/Kuwait')
            ->assertJsonPath('location.currency', 'KWD')
            ->assertJsonPath('location.country_code', 'KW')
            ->assertJsonPath('location.business_name', 'Control Plane Cafe');

        $this->assertDatabaseHas('restaurant_location_profiles', [
            'store_id' => $storeId,
            'lifecycle_state' => 'ACTIVE',
            'timezone' => 'Asia/Kuwait',
            'currency' => 'KWD',
            'country_code' => 'KW',
        ]);
        $this->assertDatabaseHas('stores', ['id' => $storeId, 'code' => 'RS-CP-B', 'name' => 'RS-CP-B']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.location.profile_updated',
            'store_id' => $storeId,
            'user_id' => $admin->id,
        ]);

        $this->patchJson('/api/v1/admin/restaurant-saas/locations/'.$storeId, [
            'timezone' => 'Not/AZone',
            'currency' => 'kwd',
            'country_code' => 'kw',
        ])->assertUnprocessable();
    }

    public function test_update_requires_explicit_provisioning(): void
    {
        $storeId = $this->retailStore('RS-CP-C');
        Sanctum::actingAs($this->storeAdmin($storeId));

        $this->patchJson('/api/v1/admin/restaurant-saas/locations/'.$storeId, [
            'lifecycle_state' => 'ACTIVE',
        ])->assertStatus(409);

        $this->assertDatabaseMissing('restaurant_location_profiles', ['store_id' => $storeId]);
        $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $storeId]);
        $this->assertDatabaseMissing('smart_ordering_sites', ['store_id' => $storeId]);
    }

    public function test_store_scope_blocks_cross_tenant_read_provision_and_update(): void
    {
        $storeA = $this->retailStore('RS-CP-D-A');
        $storeB = $this->retailStore('RS-CP-D-B');
        $adminA = $this->storeAdmin($storeA);
        $adminB = $this->storeAdmin($storeB);

        Sanctum::actingAs($adminB);
        $this->postJson('/api/v1/admin/restaurant-saas/locations/'.$storeB.'/provision')->assertOk();

        Sanctum::actingAs($adminA);
        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$storeB)->assertForbidden();
        $this->postJson('/api/v1/admin/restaurant-saas/locations/'.$storeB.'/provision')->assertForbidden();
        $this->patchJson('/api/v1/admin/restaurant-saas/locations/'.$storeB, [
            'business_name' => 'Cross Tenant Mutation',
        ])->assertForbidden();

        $this->assertDatabaseMissing('restaurant_location_profiles', [
            'store_id' => $storeB,
            'business_name' => 'Cross Tenant Mutation',
        ]);
    }

    public function test_inactive_or_non_retail_store_is_not_a_restaurant_location(): void
    {
        $retailStore = $this->retailStore('RS-CP-E');
        $admin = $this->storeAdmin($retailStore);
        DB::table('stores')->where('id', $retailStore)->update(['is_active' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$retailStore)->assertNotFound();

        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $wholesaleStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $wholesaleType,
            'code' => 'RS-CP-WHOLESALE',
            'name' => 'RS-CP-WHOLESALE',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/restaurant-saas/locations/'.$wholesaleStore)->assertNotFound();
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        return $storeId;
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Control Plane Admin',
            'email' => 'restaurant-cp-'.$storeId.'-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
