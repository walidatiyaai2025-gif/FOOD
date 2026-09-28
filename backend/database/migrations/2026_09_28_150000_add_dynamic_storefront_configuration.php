<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('storefront_kind', 32)->default('grocery')->after('name');
            $table->json('storefront_config')->nullable()->after('storefront_kind');
        });

        DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->select('stores.id', 'stores.code', 'stores.name')
            ->orderBy('stores.id')
            ->get()
            ->each(function (object $store): void {
                $key = strtolower((string) $store->code.' '.(string) $store->name);
                $kind = str_contains($key, 'pharm') || str_contains($key, 'صيد') ? 'pharmacy' : 'grocery';
                DB::table('stores')->where('id', $store->id)->update([
                    'storefront_kind' => $kind,
                    'storefront_config' => json_encode([
                        'sections' => ['hero', 'categories', 'recommended_products'],
                        'theme' => $kind,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['storefront_config', 'storefront_kind']);
        });
    }
};
