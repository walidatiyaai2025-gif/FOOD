<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Store;
use App\Services\StoreBusinessProfileService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StoreBusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_backfill_classifies_legacy_b2c_from_restaurant_master_and_records_report_idempotently(): void
    {
        $retail = $this->store('B2C', 'PROFILE-RETAIL');
        $restaurant = $this->store('B2C', 'PROFILE-RESTAURANT');
        $wholesale = $this->store('B2B', 'PROFILE-WHOLESALE');

        DB::table('enterprise_module_entitlements')->insert([
            'module_code' => 'smart_ordering',
            'scope_key' => 'store:'.$restaurant->id,
            'scope_type' => 'store',
            'scope_id' => $restaurant->id,
            'is_enabled' => true,
            'capabilities' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_18_114500_add_b2c_business_profile_to_stores.php');
        $migration->up();
        $migration->up();

        $this->assertSame(StoreBusinessProfile::RETAIL_STORE->value, $retail->fresh()->business_profile);
        $this->assertSame(StoreBusinessProfile::RESTAURANT->value, $restaurant->fresh()->business_profile);
        $this->assertNull($wholesale->fresh()->business_profile);

        $this->assertDatabaseHas('store_business_profile_backfills', [
            'store_id' => $retail->id,
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'classification_reason' => 'legacy_b2c_default_retail',
        ]);
        $this->assertDatabaseHas('store_business_profile_backfills', [
            'store_id' => $restaurant->id,
            'business_profile' => StoreBusinessProfile::RESTAURANT->value,
            'classification_reason' => 'restaurant_saas_master_enabled',
        ]);
        $this->assertSame(2, DB::table('store_business_profile_backfills')->count());
    }

    public function test_resolver_and_scopes_fail_closed_for_invalid_profile_or_channel_combinations(): void
    {
        $service = app(StoreBusinessProfileService::class);
        $retail = $this->store('B2C', 'PROFILE-SCOPE-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $restaurant = $this->store('B2C', 'PROFILE-SCOPE-RESTAURANT', StoreBusinessProfile::RESTAURANT);
        $wholesale = $this->store('B2B', 'PROFILE-SCOPE-WHOLESALE');

        DB::table('stores')->where('id', $wholesale->id)->update([
            'business_profile' => StoreBusinessProfile::RESTAURANT->value,
        ]);

        $this->assertTrue($service->isRetailStore($retail));
        $this->assertTrue($service->isRestaurant($restaurant));
        $this->assertNull($service->resolve($wholesale));

        $this->assertSame([$retail->id], Store::query()->retailStores()->pluck('id')->all());
        $this->assertSame([$restaurant->id], Store::query()->restaurants()->pluck('id')->all());

        DB::table('stores')->where('id', $retail->id)->update(['business_profile' => 'INVALID']);
        $this->assertNull($service->resolve($retail->id));
        $this->assertFalse($service->isRetailStore($retail->id));
    }

    public function test_assignment_validates_profile_and_b2c_channel(): void
    {
        $service = app(StoreBusinessProfileService::class);
        $retail = $this->store('B2C', 'PROFILE-ASSIGN');
        $wholesale = $this->store('B2B', 'PROFILE-ASSIGN-B2B');

        $updated = $service->assign($retail, StoreBusinessProfile::RESTAURANT);

        $this->assertSame(StoreBusinessProfile::RESTAURANT->value, $updated->business_profile);

        try {
            $service->assign($wholesale, StoreBusinessProfile::RETAIL_STORE);
            $this->fail('B2B stores must not accept a B2C business profile.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('business_profile', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        $service->assign($retail, 'INVALID');
    }

    private function store(
        string $type,
        string $code,
        ?StoreBusinessProfile $profile = null,
    ): Store {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'business_profile' => $profile?->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
