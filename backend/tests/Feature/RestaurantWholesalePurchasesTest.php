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

class RestaurantWholesalePurchasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_restaurant_owner_gets_one_wholesale_buyer_area_on_existing_account_infrastructure(): void
    {
        $admin = $this->superAdmin();
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $this->actingAs($admin)->post(route('admin.retail-stores.store'), [
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'RESTAURANT-BUYER',
            'name' => 'Restaurant Buyer',
            'logo' => UploadedFile::fake()->image('restaurant-buyer.png', 128, 128),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Restaurant Buyer Owner',
            'manager_email' => 'restaurant-buyer@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'RESTAURANT-BUYER')->value('id');
        $owner = User::query()->where('email', 'restaurant-buyer@example.test')->firstOrFail();
        $owner->forceFill(['locale' => 'en'])->save();
        $link = DB::table('retail_wholesale_accounts')->where('retail_store_id', $storeId)->first();

        $this->assertNotNull($link);
        $this->assertSame($owner->id, (int) $link->owner_user_id);
        $this->assertDatabaseHas('b2b_accounts', ['b2b_customer_id' => $link->b2b_customer_id]);

        $this->actingAs($owner)
            ->get(route('admin.b2c.module', ['module' => 'wholesale_purchases', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('Wholesale Purchases')
            ->assertSee('Wholesale Catalog')
            ->assertSee('Purchase Cart')
            ->assertSee('Purchase Orders')
            ->assertSee('Supplier Invoices')
            ->assertSee('Supply / Delivery Status')
            ->assertSee('data-wholesale-catalog-summary', false)
            ->assertSee('data-wholesale-cart-summary', false);
    }

    public function test_retail_profile_cannot_open_restaurant_wholesale_buyer_area(): void
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $type,
            'business_profile' => Store::BUSINESS_PROFILE_RETAIL_STORE,
            'code' => 'NO-RESTAURANT-BUYER',
            'name' => 'No Restaurant Buyer',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Retail User',
            'email' => 'retail-no-buyer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'wholesale_purchases', 'store_id' => $store->id]))
            ->assertNotFound();
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Restaurant Buyer Super Admin',
            'email' => 'restaurant-buyer-super@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
