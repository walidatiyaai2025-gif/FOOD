<?php

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enums\StoreBusinessProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('stores', 'business_profile')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->string('business_profile', 32)
                    ->nullable()
                    ->after('store_type_id')
                    ->index();
            });
        }

        if (! Schema::hasTable('store_business_profile_backfills')) {
            Schema::create('store_business_profile_backfills', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('store_id')->unique();
                $table->string('business_profile', 32)->index();
                $table->string('classification_reason', 80);
                $table->timestamp('classified_at');
                $table->timestamps();
            });
        }

        $b2cTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($b2cTypeId === null) {
            return;
        }

        $restaurantStoreIds = [];
        if (Schema::hasTable('enterprise_module_entitlements')) {
            $restaurantStoreIds = DB::table('enterprise_module_entitlements')
                ->where('module_code', SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE)
                ->where('scope_type', EntitlementScope::STORE)
                ->where('is_enabled', true)
                ->whereNotNull('scope_id')
                ->pluck('scope_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $restaurantStoreIds = array_fill_keys($restaurantStoreIds, true);

        DB::table('stores')
            ->select('id')
            ->where('store_type_id', $b2cTypeId)
            ->whereNull('business_profile')
            ->orderBy('id')
            ->chunkById(200, function ($stores) use ($restaurantStoreIds): void {
                foreach ($stores as $store) {
                    $storeId = (int) $store->id;
                    $restaurant = isset($restaurantStoreIds[$storeId]);
                    $profile = $restaurant
                        ? StoreBusinessProfile::RESTAURANT->value
                        : StoreBusinessProfile::RETAIL_STORE->value;
                    $reason = $restaurant
                        ? 'restaurant_saas_master_enabled'
                        : 'legacy_b2c_default_retail';

                    $updated = DB::table('stores')
                        ->where('id', $storeId)
                        ->whereNull('business_profile')
                        ->update([
                            'business_profile' => $profile,
                            'updated_at' => now(),
                        ]);

                    if ($updated === 1) {
                        DB::table('store_business_profile_backfills')->insertOrIgnore([
                            'store_id' => $storeId,
                            'business_profile' => $profile,
                            'classification_reason' => $reason,
                            'classified_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_business_profile_backfills');

        if (Schema::hasColumn('stores', 'business_profile')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropIndex('stores_business_profile_index');
                $table->dropColumn('business_profile');
            });
        }
    }
};
