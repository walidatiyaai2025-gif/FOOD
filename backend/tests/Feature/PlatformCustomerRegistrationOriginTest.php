<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PlatformCustomer;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class PlatformCustomerRegistrationOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_retail_origin_registration_materializes_source_retail_and_wholesale_with_store_default_tier(): void
    {
        $wholesaleTierId = (int) DB::table('b2b_price_tiers')->where('code', 'WHOLESALE')->value('id');
        $retailStoreId = $this->createStore('B2C', 'RETAIL-A', $wholesaleTierId);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Retail Origin Customer',
            'email' => 'retail-origin@example.test',
            'phone' => '+201000000101',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
            'store_id' => $retailStoreId,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('platform_customer', true);

        $user = User::query()->where('email', 'retail-origin@example.test')->firstOrFail();
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $b2bCustomerId = (int) DB::table('b2b_customers')->where('user_id', $user->id)->value('id');

        $this->assertSame('b2c', $platform->origin_channel);
        $this->assertSame($retailStoreId, (int) $platform->origin_store_id);
        $this->assertSame('customer_app', $platform->registration_source);
        $this->assertNotNull($platform->registered_at);

        $this->assertDatabaseHas('b2c_customers', [
            'user_id' => $user->id,
            'store_id' => $retailStoreId,
            'legacy_customer_id' => $platform->legacy_customer_id,
        ]);

        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $b2bCustomerId,
            'status' => 'active',
            'price_tier_id' => $wholesaleTierId,
        ]);
    }

    public function test_wholesale_origin_registration_creates_no_arbitrary_retail_profile_and_uses_standard_tier(): void
    {
        $wholesaleStoreId = $this->createStore('B2B', 'WHOLESALE-MAIN');
        $this->createStore('B2C', 'RETAIL-A');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wholesale Origin Customer',
            'email' => 'wholesale-origin@example.test',
            'phone' => '+201000000102',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'store_id' => $wholesaleStoreId,
        ])->assertCreated();

        $user = User::query()->where('email', 'wholesale-origin@example.test')->firstOrFail();
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $b2bCustomerId = (int) DB::table('b2b_customers')->where('user_id', $user->id)->value('id');
        $standardTierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $this->assertSame('b2b', $platform->origin_channel);
        $this->assertSame($wholesaleStoreId, (int) $platform->origin_store_id);
        $this->assertDatabaseMissing('b2c_customers', ['user_id' => $user->id]);
        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $b2bCustomerId,
            'status' => 'active',
            'price_tier_id' => $standardTierId,
        ]);
    }

    public function test_retail_origin_without_custom_default_tier_falls_back_to_standard(): void
    {
        $retailStoreId = $this->createStore('B2C', 'RETAIL-STANDARD');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Standard Tier Customer',
            'email' => 'standard-tier@example.test',
            'phone' => '+201000000103',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'store_id' => $retailStoreId,
        ])->assertCreated();

        $user = User::query()->where('email', 'standard-tier@example.test')->firstOrFail();
        $b2bCustomerId = (int) DB::table('b2b_customers')->where('user_id', $user->id)->value('id');
        $standardTierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $b2bCustomerId,
            'price_tier_id' => $standardTierId,
            'status' => 'active',
        ]);
    }

    public function test_later_retail_materialization_does_not_change_registration_origin(): void
    {
        $storeA = $this->createStore('B2C', 'RETAIL-A');
        $storeB = $this->createStore('B2C', 'RETAIL-B');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Cross Retail Customer',
            'email' => 'cross-retail@example.test',
            'phone' => '+201000000104',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'store_id' => $storeA,
        ])->assertCreated();

        $user = User::query()->where('email', 'cross-retail@example.test')->firstOrFail();
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertDatabaseHas('b2c_customers', ['user_id' => $user->id, 'store_id' => $storeA]);
        $this->assertDatabaseMissing('b2c_customers', ['user_id' => $user->id, 'store_id' => $storeB]);

        app(CustomerDomainResolver::class)->b2c($user, $storeB);

        $this->assertDatabaseHas('b2c_customers', ['user_id' => $user->id, 'store_id' => $storeB]);
        $platform->refresh();
        $this->assertSame('b2c', $platform->origin_channel);
        $this->assertSame($storeA, (int) $platform->origin_store_id);

        $this->expectException(LogicException::class);
        $platform->origin_store_id = $storeB;
        $platform->save();
    }

    public function test_origin_migration_replay_backfills_unknown_provenance_and_repairs_wholesale_entitlement(): void
    {
        $user = User::query()->create([
            'name' => 'Migrated Platform Customer',
            'email' => 'migrated-platform@example.test',
            'password' => 'Password123!',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => false,
        ]);

        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'platform',
            'name' => $user->name,
            'phone' => '+201000000105',
            'email' => $user->email,
        ]);

        DB::table('platform_customers')->insert([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacy->id,
            'name' => $user->name,
            'phone' => '+201000000105',
            'email' => $user->email,
            'origin_channel' => null,
            'origin_store_id' => null,
            'registration_source' => 'migration',
            'registered_at' => null,
            'is_active' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $migration = require database_path('migrations/2026_09_29_140000_add_platform_customer_registration_origin.php');
        $migration->up();

        $platform = DB::table('platform_customers')->where('user_id', $user->id)->first();
        $b2bCustomerId = (int) DB::table('b2b_customers')->where('user_id', $user->id)->value('id');
        $standardTierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        $this->assertSame('unknown', $platform->origin_channel);
        $this->assertSame('migration', $platform->registration_source);
        $this->assertNotNull($platform->registered_at);
        $this->assertTrue((bool) User::query()->findOrFail($user->id)->is_platform_customer);
        $this->assertGreaterThan(0, $b2bCustomerId);
        $this->assertDatabaseHas('b2b_accounts', [
            'b2b_customer_id' => $b2bCustomerId,
            'price_tier_id' => $standardTierId,
            'status' => 'active',
        ]);
    }

    private function createStore(
        string $typeCode,
        string $code,
        ?int $defaultWholesaleTierId = null,
    ): int {
        $typeId = (int) DB::table('store_types')->where('code', $typeCode)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'default_customer_wholesale_price_tier_id' => $defaultWholesaleTierId,
            'code' => $code,
            'name' => str_replace('-', ' ', $code),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
