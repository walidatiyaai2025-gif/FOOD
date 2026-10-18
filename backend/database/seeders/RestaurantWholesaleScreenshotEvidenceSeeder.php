<?php

namespace Database\Seeders;

use App\Domain\RestaurantSaaS\RestaurantSaasStoreFeatureService;
use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Enums\StoreBusinessProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use App\Services\StoreBusinessProfileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RestaurantWholesaleScreenshotEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('RestaurantWholesaleScreenshotEvidenceSeeder is forbidden in production.');
        }

        $store = Store::query()->where('code', 'FOODEX-DEMO-B2C')->firstOrFail();
        $actor = User::query()->where('email', 'screenshots@foodex.test')->firstOrFail();

        if (! app(SmartOrderingEnterpriseEntitlementBridge::class)->storeEnabled((int) $store->getKey())) {
            app(RestaurantSaasStoreFeatureService::class)->setEnabled($store, true, $actor);
        }

        app(StoreBusinessProfileService::class)->assign($store, StoreBusinessProfile::RESTAURANT);

        $tierId = (int) (
            DB::table('b2b_price_tiers')->where('code', 'GOLD-EVIDENCE')->value('id')
            ?? DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id')
            ?? 0
        );
        if ($tierId < 1) {
            throw new RuntimeException('Restaurant Wholesale screenshot evidence requires a B2B price tier.');
        }

        app(RetailWholesaleAccountService::class)->syncForStore($store, $tierId, $actor);
    }
}
