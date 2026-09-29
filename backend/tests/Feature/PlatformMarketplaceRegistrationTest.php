<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomerDomainResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformMarketplaceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        DB::table('b2b_price_tiers')->insert([
            'code' => 'STANDARD',
            'name' => 'Standard',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_marketplace_exposes_main_wholesale_and_retail_banners(): void
    {
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $wholesaleId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bType,
            'code' => 'WHOLESALE-MAIN',
            'name' => 'Main Wholesale',
            'is_active' => true,
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);
        $retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'RETAIL-A',
            'name' => 'Retail A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('banners')->insert([
            'store_id' => $retailId,
            'title' => 'Retail banner',
            'image_path' => 'storage/banners/retail-a.webp',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/marketplace');

        $response
            ->assertOk()
            ->assertJsonPath('main_wholesale_store.id', $wholesaleId)
            ->assertJsonPath('main_wholesale_store.channel', 'b2b')
            ->assertJsonPath('retail_stores.0.id', $retailId)
            ->assertJsonPath('retail_stores.0.banner_title', 'Retail banner');
    }

    public function test_registration_creates_one_platform_user_and_active_wholesale_customer_account(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Platform Customer',
            'email' => 'platform-customer@example.test',
            'phone' => '+201000000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $user = User::query()->where('email', 'platform-customer@example.test')->firstOrFail();
        $customer = DB::table('b2b_customers')->where('user_id', $user->id)->first();

        $this->assertNotNull($customer);
        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $customer->id,
            'status' => 'active',
            'price_tier_id' => DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id'),
        ]);
    }

    public function test_registered_customer_materializes_retail_domain_per_selected_store(): void
    {
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'RETAIL-A',
            'name' => 'Retail A',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'RETAIL-B',
            'name' => 'Retail B',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cross Store Customer',
            'email' => 'cross-store@example.test',
            'phone' => '+201000000002',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $user = User::query()->where('email', 'cross-store@example.test')->firstOrFail();
        $resolver = app(CustomerDomainResolver::class);

        $customerA = $resolver->b2c($user, $storeA);
        $customerB = $resolver->b2c($user, $storeB);

        $this->assertNotSame($customerA->id, $customerB->id);
        $this->assertSame($user->id, $customerA->user_id);
        $this->assertSame($user->id, $customerB->user_id);
        $this->assertDatabaseHas('b2c_customers', ['user_id' => $user->id, 'store_id' => $storeA]);
        $this->assertDatabaseHas('b2c_customers', ['user_id' => $user->id, 'store_id' => $storeB]);
    }
}
