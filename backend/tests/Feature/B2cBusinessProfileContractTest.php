<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Services\B2cBusinessProfileBackfillService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class B2cBusinessProfileContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2c_store_defaults_to_retail_and_accepts_restaurant_profile(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $retail = Store::query()->create([
            'store_type_id' => $b2cType,
            'code' => 'PROFILE-RETAIL',
            'name' => 'Profile Retail',
            'is_active' => true,
        ]);
        $restaurant = Store::query()->create([
            'store_type_id' => $b2cType,
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'PROFILE-RESTAURANT',
            'name' => 'Profile Restaurant',
            'is_active' => true,
        ]);

        $this->assertTrue($retail->fresh()->isRetailStoreProfile());
        $this->assertTrue($restaurant->fresh()->isRestaurantProfile());
    }

    public function test_invalid_profile_and_non_b2c_profile_fail_closed(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');

        try {
            Store::query()->create([
                'store_type_id' => $b2cType,
                'business_profile' => 'MIXED',
                'code' => 'PROFILE-INVALID',
                'name' => 'Invalid Profile',
                'is_active' => true,
            ]);
            $this->fail('Invalid profile should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        Store::query()->create([
            'store_type_id' => $b2bType,
            'business_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'code' => 'WHOLESALE-PROFILE',
            'name' => 'Wholesale Profile',
            'is_active' => true,
        ]);
    }

    public function test_backfill_uses_restaurant_master_entitlement_and_is_idempotent(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'business_profile' => null,
            'code' => 'LEGACY-RETAIL',
            'name' => 'Legacy Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $restaurantId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'business_profile' => null,
            'code' => 'LEGACY-RESTAURANT',
            'name' => 'Legacy Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('enterprise_module_entitlements')->insert([
            'module_code' => 'smart_ordering',
            'scope_key' => 'store:'.$restaurantId,
            'scope_type' => 'store',
            'scope_id' => $restaurantId,
            'is_enabled' => true,
            'capabilities' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = app(B2cBusinessProfileBackfillService::class)->run();
        $second = app(B2cBusinessProfileBackfillService::class)->run();

        $this->assertSame(Store::BUSINESS_PROFILE_RETAIL_STORE, DB::table('stores')->where('id', $retailId)->value('business_profile'));
        $this->assertSame(Store::BUSINESS_PROFILE_RESTAURANT, DB::table('stores')->where('id', $restaurantId)->value('business_profile'));
        $this->assertSame(2, $first['converted']);
        $this->assertSame(0, $second['converted']);
        $this->assertDatabaseHas('b2c_business_profile_backfill_audit', [
            'store_id' => $restaurantId,
            'assigned_profile' => Store::BUSINESS_PROFILE_RESTAURANT,
            'source' => 'restaurant_saas_master_entitlement',
        ]);
    }
}
