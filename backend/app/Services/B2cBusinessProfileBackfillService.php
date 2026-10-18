<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class B2cBusinessProfileBackfillService
{
    /**
     * @return array{retail:int,restaurant:int,converted:int}
     */
    public function run(): array
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'business_profile')) {
            return ['retail' => 0, 'restaurant' => 0, 'converted' => 0];
        }

        $b2cTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($b2cTypeId === null) {
            return ['retail' => 0, 'restaurant' => 0, 'converted' => 0];
        }

        $restaurantIds = [];
        if (Schema::hasTable('enterprise_module_entitlements')) {
            $restaurantIds = DB::table('enterprise_module_entitlements')
                ->where('module_code', 'smart_ordering')
                ->where('scope_type', 'store')
                ->where('is_enabled', true)
                ->whereNotNull('scope_id')
                ->pluck('scope_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $restaurantLookup = array_fill_keys($restaurantIds, true);
        $stores = DB::table('stores')
            ->where('store_type_id', $b2cTypeId)
            ->orderBy('id')
            ->get(['id', 'business_profile']);

        $retail = 0;
        $restaurant = 0;
        $converted = 0;

        DB::transaction(function () use (
            $stores,
            $restaurantLookup,
            &$retail,
            &$restaurant,
            &$converted,
        ): void {
            foreach ($stores as $store) {
                $storeId = (int) $store->id;
                $isRestaurant = isset($restaurantLookup[$storeId]);
                $target = $isRestaurant
                    ? Store::BUSINESS_PROFILE_RESTAURANT
                    : Store::BUSINESS_PROFILE_RETAIL_STORE;

                $target === Store::BUSINESS_PROFILE_RESTAURANT ? $restaurant++ : $retail++;

                $before = $store->business_profile === null ? null : (string) $store->business_profile;
                if ($before !== $target) {
                    DB::table('stores')->where('id', $storeId)->update([
                        'business_profile' => $target,
                        'updated_at' => now(),
                    ]);
                    $converted++;

                    if (Schema::hasTable('b2c_business_profile_backfill_audit')) {
                        DB::table('b2c_business_profile_backfill_audit')->insertOrIgnore([
                            'store_id' => $storeId,
                            'previous_profile' => $before,
                            'assigned_profile' => $target,
                            'source' => $isRestaurant
                                ? 'restaurant_saas_master_entitlement'
                                : 'b2c_default',
                            'converted_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if (Schema::hasTable('store_business_profile_backfills')) {
                    DB::table('store_business_profile_backfills')->insertOrIgnore([
                        'store_id' => $storeId,
                        'business_profile' => $target,
                        'classification_reason' => $isRestaurant
                            ? 'restaurant_saas_master_enabled'
                            : 'legacy_b2c_default_retail',
                        'classified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        DB::table('stores')
            ->where('store_type_id', '<>', $b2cTypeId)
            ->whereNotNull('business_profile')
            ->update(['business_profile' => null, 'updated_at' => now()]);

        return compact('retail', 'restaurant', 'converted');
    }
}
