<?php

use App\Models\Store;
use App\Services\B2cBusinessProfileBackfillService;
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

        if (! Schema::hasTable('b2c_business_profile_backfill_audit')) {
            Schema::create('b2c_business_profile_backfill_audit', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete()->unique();
                $table->string('previous_profile', 32)->nullable();
                $table->string('assigned_profile', 32);
                $table->string('source', 80);
                $table->timestamp('converted_at');
                $table->timestamps();
            });
        }

        app(B2cBusinessProfileBackfillService::class)->run();

        $b2cTypeId = DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($b2cTypeId === null) {
            return;
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

        DB::table('stores')
            ->where('store_type_id', $b2cTypeId)
            ->whereIn('business_profile', Store::businessProfiles())
            ->orderBy('id')
            ->get(['id', 'business_profile'])
            ->each(function ($store) use ($restaurantLookup): void {
                $storeId = (int) $store->id;
                $profile = (string) $store->business_profile;
                $restaurant = $profile === Store::BUSINESS_PROFILE_RESTAURANT
                    && isset($restaurantLookup[$storeId]);

                DB::table('store_business_profile_backfills')->insertOrIgnore([
                    'store_id' => $storeId,
                    'business_profile' => $profile,
                    'classification_reason' => $restaurant
                        ? 'restaurant_saas_master_enabled'
                        : 'legacy_b2c_default_retail',
                    'classified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2c_business_profile_backfill_audit');
        Schema::dropIfExists('store_business_profile_backfills');

        if (Schema::hasColumn('stores', 'business_profile')) {
            Schema::table('stores', function (Blueprint $table): void {
                $table->dropIndex('stores_business_profile_index');
                $table->dropColumn('business_profile');
            });
        }
    }
};
