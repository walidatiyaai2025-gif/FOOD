<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreFeatureService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreBusinessProfileService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RestaurantSaasRetailStoreEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_saas_is_fail_closed_until_super_admin_enables_store_master(): void
    {
        $superAdmin = $this->globalUser('SUPER_ADMIN', 'restaurant-owner@example.test');
        $store = $this->restaurant($this->store('B2C', 'REST-CLIENT-A'));
        $manager = $this->storeAdmin($store, 'restaurant-manager@example.test');

        $this->assertFalse(app(RestaurantSaasStoreFeatureService::class)->isEnabled($store));
        $this->assertNotContains('smart_ordering', $this->navigationKeys($manager));
        $this->assertNotContains('smart_ordering', $this->navigationKeys($superAdmin));

        $this->actingAs($manager)
            ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->patch(route('admin.retail-stores.restaurant-saas', $store), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue(app(RestaurantSaasStoreFeatureService::class)->isEnabled($store));
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('smart_ordering_sites', ['store_id' => $store->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'restaurant_saas.store_access.changed',
            'auditable_id' => $store->id,
        ]);
        $this->assertContains('smart_ordering', $this->navigationKeys($manager));
        $this->assertNotContains('smart_ordering', $this->navigationKeys($superAdmin));

        $this->actingAs($manager)
            ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
            ->assertOk();
    }

    public function test_disable_preserves_restaurant_state_blocks_routes_and_reenable_restores_same_site(): void
    {
        $superAdmin = $this->globalUser('SUPER_ADMIN', 'restaurant-toggle-owner@example.test');
        $store = $this->restaurant($this->store('B2C', 'REST-CLIENT-STATE'));
        $manager = $this->storeAdmin($store, 'restaurant-toggle-manager@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('admin.retail-stores.restaurant-saas', $store), ['enabled' => '1'])
            ->assertRedirect();

        $site = DB::table('smart_ordering_sites')->where('store_id', $store->id)->first();
        $this->assertNotNull($site);
        app(SmartOrderingSiteService::class)->setEntitlement(
            $manager,
            SmartOrderingSite::query()->findOrFail($site->id),
            Module::PUBLIC_MENU,
            true,
        );

        $this->actingAs($superAdmin)
            ->patch(route('admin.retail-stores.restaurant-saas', $store), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse(app(RestaurantSaasStoreFeatureService::class)->isEnabled($store));
        $this->assertDatabaseHas('smart_ordering_sites', ['id' => $site->id, 'store_id' => $store->id]);
        $this->assertDatabaseHas('smart_ordering_entitlements', [
            'site_id' => $site->id,
            'module' => Module::PUBLIC_MENU->value,
            'enabled' => true,
        ]);
        $this->assertNotContains('smart_ordering', $this->navigationKeys($manager));

        $this->actingAs($manager)
            ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
            ->assertForbidden();

        $this->actingAs($manager)
            ->put(route('admin.smart-ordering.entitlements', $site->id), [
                'modules' => [Module::PUBLIC_MENU->value],
            ])
            ->assertNotFound();

        $this->actingAs($superAdmin)
            ->patch(route('admin.retail-stores.restaurant-saas', $store), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue(app(RestaurantSaasStoreFeatureService::class)->isEnabled($store));
        $this->assertSame(
            (int) $site->id,
            (int) DB::table('smart_ordering_sites')->where('store_id', $store->id)->value('id'),
        );
        $this->assertDatabaseHas('smart_ordering_entitlements', [
            'site_id' => $site->id,
            'module' => Module::PUBLIC_MENU->value,
            'enabled' => true,
        ]);
        $this->assertContains('smart_ordering', $this->navigationKeys($manager));
    }

    public function test_b2b_store_cannot_receive_restaurant_saas_and_cross_store_access_is_denied(): void
    {
        $superAdmin = $this->globalUser('SUPER_ADMIN', 'restaurant-scope-owner@example.test');
        $storeA = $this->restaurant($this->store('B2C', 'REST-SCOPE-A'));
        $storeB = $this->restaurant($this->store('B2C', 'REST-SCOPE-B'));
        $wholesale = $this->store('B2B', 'REST-SCOPE-WHOLESALE');
        $managerA = $this->storeAdmin($storeA, 'restaurant-scope-manager@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('admin.retail-stores.restaurant-saas', $wholesale), ['enabled' => '1'])
            ->assertNotFound();

        foreach ([$storeA, $storeB] as $store) {
            $this->actingAs($superAdmin)
                ->patch(route('admin.retail-stores.restaurant-saas', $store), ['enabled' => '1'])
                ->assertRedirect();
        }

        $this->actingAs($managerA)
            ->get(route('admin.smart-ordering.index', ['store_id' => $storeB->id]))
            ->assertNotFound();

        $this->actingAs($managerA)
            ->get(route('admin.smart-ordering.index', ['store_id' => $storeA->id]))
            ->assertOk();
    }

    private function restaurant(Store $store): Store
    {
        return app(StoreBusinessProfileService::class)->assign($store, StoreBusinessProfile::RESTAURANT);
    }

    /** @return list<string> */
    private function navigationKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = $this->user($email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeAdmin(Store $store, string $email): User
    {
        $user = $this->user($email);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $type, string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
