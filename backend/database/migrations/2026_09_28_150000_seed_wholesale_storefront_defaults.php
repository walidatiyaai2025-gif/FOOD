<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_settings') || ! Schema::hasTable('storefront_sections')) {
            return;
        }

        $storeIds = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->pluck('stores.id');

        foreach ($storeIds as $storeId) {
            $id = (int) $storeId;

            if (! DB::table('storefront_settings')->where('store_id', $id)->exists()) {
                DB::table('storefront_settings')->insert([
                    'store_id' => $id,
                    'theme_code' => 'wholesale_b2b',
                    'primary_color' => '#5D2A91',
                    'primary_dark_color' => '#35195E',
                    'accent_color' => '#B983F0',
                    'background_color' => '#FBFAFD',
                    'branding' => json_encode([
                        'brand_title_ar' => 'متجر الجملة',
                        'brand_title_en' => 'Wholesale Store',
                        'brand_subtitle_ar' => 'أفضل الأسعار لمتاجر التجزئة',
                        'brand_subtitle_en' => 'Best prices for retailers',
                        'hero_cta_ar' => 'تصفح الكتالوج',
                        'hero_cta_en' => 'Browse catalog',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $defaults = [
                ['hero', 'hero', null, null, 10],
                ['categories', 'categories', 'التصنيفات', 'Categories', 20],
                ['offers', 'offers', 'عروض الجملة', 'Wholesale offers', 30],
                ['featured_products', 'featured_products', 'منتجات مميزة', 'Featured products', 40],
            ];

            foreach ($defaults as [$key, $type, $titleAr, $titleEn, $sort]) {
                DB::table('storefront_sections')->updateOrInsert(
                    ['store_id' => $id, 'section_key' => $key],
                    [
                        'section_type' => $type,
                        'title_ar' => $titleAr,
                        'title_en' => $titleEn,
                        'sort_order' => $sort,
                        'config' => null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // Storefront settings are business configuration. Do not delete them
        // automatically during rollback.
    }
};
