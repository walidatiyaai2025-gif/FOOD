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

        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->get(['stores.id']);

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            $settings = DB::table('storefront_settings')->where('store_id', $storeId)->first();

            if ($settings !== null) {
                $branding = [];
                if (is_string($settings->branding ?? null) && trim((string) $settings->branding) !== '') {
                    $decoded = json_decode((string) $settings->branding, true);
                    $branding = is_array($decoded) ? $decoded : [];
                } elseif (is_array($settings->branding ?? null)) {
                    $branding = $settings->branding;
                }

                if (! array_key_exists('public_web_enabled', $branding)) {
                    $branding['public_web_enabled'] = false;
                }

                $updates = [
                    'branding' => json_encode($branding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ];

                if (($settings->primary_color ?? null) === '#5D2A91') {
                    $updates['primary_color'] = '#00452F';
                }
                if (($settings->primary_dark_color ?? null) === '#35195E') {
                    $updates['primary_dark_color'] = '#003C2A';
                }
                if (($settings->accent_color ?? null) === '#B983F0') {
                    $updates['accent_color'] = '#9BE252';
                }
                if (($settings->background_color ?? null) === '#FBFAFD') {
                    $updates['background_color'] = '#EDF7F1';
                }

                DB::table('storefront_settings')->where('store_id', $storeId)->update($updates);
            }

            foreach ([
                ['benefits', 'benefits', 'مزايا فوديكس', 'Why FOODEX', 15],
                ['brands', 'brands', 'العلامات التجارية', 'Brands', 35],
                ['social_facebook', 'social', 'تابعنا على فيسبوك', 'Follow us on Facebook', 60],
            ] as [$key, $type, $titleAr, $titleEn, $sortOrder]) {
                if (DB::table('storefront_sections')->where('store_id', $storeId)->where('section_key', $key)->exists()) {
                    continue;
                }

                DB::table('storefront_sections')->insert([
                    'store_id' => $storeId,
                    'section_key' => $key,
                    'section_type' => $type,
                    'title_ar' => $titleAr,
                    'title_en' => $titleEn,
                    'sort_order' => $sortOrder,
                    'config' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Storefront branding and section composition are business configuration.
        // Do not delete or revert them automatically during rollback.
    }
};
