<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class B2cBusinessProfileProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_create_wizard_requires_an_explicit_business_type_and_removes_generic_restaurant_toggle(): void
    {
        $admin = $this->superAdmin('profile-wizard@example.test');

        $this->actingAs($admin)
            ->get(route('admin.retail-stores.index'))
            ->assertOk()
            ->assertSee('name="business_profile"', false)
            ->assertSee('value="RETAIL_STORE"', false)
            ->assertSee('value="RESTAURANT"', false)
            ->assertSee('Wholesale Purchases')
            ->assertDontSee('data-restaurant-saas-toggle', false);
    }

    public function test_retail_and_restaurant_provision_into_distinct_authoritative_profiles(): void
    {
        $admin = $this->superAdmin('profile-provisioning@example.test');
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => Store::BUSINESS_PROFILE_RETAIL_STORE,
            'code' => 'PROFILE-RETAIL-CREATE',
            'name' => 'Profile Retail Create',
            'logo' => UploadedFile::fake()->image('retail.png', 128, 128),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Retail Owner',
            'manager_email' => 'profile-retail-owner@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $retailId = (int) DB::table('stores')->where('code', 'PROFILE-RETAIL-CREATE')->value('id');
        $this->assertDatabaseHas('stores', [
            'id' => $retailId,
            'business_profile' => Store::BUSINESS_PROFILE_RETAIL_STORE,
        ]);
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'store',
            'scope_id' => $retailId,
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'PROFILE-RESTAURANT-CREATE',
            'name' => 'Profile Restaurant Create',
            'logo' => UploadedFile::fake()->image('restaurant.png', 128, 128),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Restaurant Owner',
            'manager_email' => 'profile-restaurant-owner@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $restaurantId = (int) DB::table('stores')->where('code', 'PROFILE-RESTAURANT-CREATE')->value('id');
        $this->assertDatabaseHas('stores', [
            'id' => $restaurantId,
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_type' => 'store',
            'scope_id' => $restaurantId,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('restaurant_location_profiles', ['store_id' => $restaurantId]);
        $this->assertDatabaseHas('smart_ordering_sites', ['store_id' => $restaurantId]);
        $this->assertDatabaseHas('retail_wholesale_accounts', ['retail_store_id' => $restaurantId]);
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Profile Super Admin',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
