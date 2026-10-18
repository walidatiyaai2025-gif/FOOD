<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
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
        $retail = $this->store('NAV-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('NAV-RESTAURANT', StoreBusinessProfile::RESTAURANT);
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
        $this->assertFalse($restaurantItems->contains(
            fn (string $key): bool => str_starts_with($key, 'b2c_'),
        ));
    }

    public function test_direct_cross_profile_workspace_urls_fail_closed(): void
    {
        $retail = $this->store('ROUTE-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('ROUTE-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);

        $retailUser = $this->storeAdmin('route-retail@example.test', $retail);
        $restaurantUser = $this->storeAdmin('route-restaurant@example.test', $restaurant);

        $this->actingAs($restaurantUser)
            ->get(route('admin.b2c.dashboard', ['store_id' => $restaurant]))
            ->assertNotFound();

        $this->actingAs($retailUser)
            ->get(route('admin.smart-ordering.index', ['store_id' => $retail]))
            ->assertForbidden();

        $this->actingAs($retailUser)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $retail]))
            ->assertOk();
    }

    private function store(string $code, StoreBusinessProfile $profile): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => $profile->value,
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
