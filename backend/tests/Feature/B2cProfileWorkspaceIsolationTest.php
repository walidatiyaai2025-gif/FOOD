<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2cProfileWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_and_restaurant_users_receive_separate_sidebar_domains(): void
    {
        $retail = $this->store('NAV-RETAIL', Store::BUSINESS_PROFILE_RETAIL_STORE);
        $restaurant = $this->store('NAV-RESTAURANT', Store::BUSINESS_PROFILE_RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);

        $retailUser = $this->storeAdmin('nav-retail@example.test', $retail);
        $restaurantUser = $this->storeAdmin('nav-restaurant@example.test', $restaurant);

        $retailItems = collect(app(AdminNavigation::class)->groupsFor($retailUser))
            ->flatMap(fn (array $group) => $group['children'])->pluck('key');
        $restaurantItems = collect(app(AdminNavigation::class)->groupsFor($restaurantUser))
            ->flatMap(fn (array $group) => $group['children'])->pluck('key');

        $this->assertTrue($retailItems->contains('b2c_dashboard'));
        $this->assertFalse($retailItems->contains('smart_ordering'));

        $this->assertTrue($restaurantItems->contains('smart_ordering'));
        $this->assertFalse($restaurantItems->contains(fn (string $key): bool => str_starts_with($key, 'b2c_')));
    }

    public function test_direct_cross_profile_workspace_urls_fail_closed(): void
    {
        $retail = $this->store('ROUTE-RETAIL', Store::BUSINESS_PROFILE_RETAIL_STORE);
        $restaurant = $this->store('ROUTE-RESTAURANT', Store::BUSINESS_PROFILE_RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);

        $retailUser = $this->storeAdmin('route-retail@example.test', $retail);
        $restaurantUser = $this->storeAdmin('route-restaurant@example.test', $restaurant);

        $this->actingAs($restaurantUser)
            ->get(route('admin.b2c.dashboard', ['store_id' => $restaurant]))
            ->assertNotFound();

        $this->actingAs($retailUser)
            ->get(route('admin.smart-ordering.index', ['store_id' => $retail]))
            ->assertStatus(403);

        $this->actingAs($retailUser)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $retail]))
            ->assertOk();
    }

    public function test_super_admin_inspect_routes_restaurant_to_restaurant_workspace(): void
    {
        $restaurant = $this->store('INSPECT-RESTAURANT', Store::BUSINESS_PROFILE_RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);

        $admin = User::query()->create([
            'name' => 'Inspect Admin',
            'email' => 'inspect-profile@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($admin)
            ->post(route('admin.retail-stores.inspect', $restaurant))
            ->assertRedirect(route('admin.smart-ordering.index', [
                'store_id' => $restaurant,
                'support_access' => 1,
            ]));
    }

    private function store(string $code, string $profile): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => $profile,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ])->getKey();
    }

    private function storeAdmin(string $email, int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Profile Store Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
