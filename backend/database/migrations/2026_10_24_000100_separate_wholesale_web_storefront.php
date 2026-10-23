<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        Schema::create('wholesale_web_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('hero_title_ar')->nullable();
            $table->string('hero_title_en')->nullable();
            $table->text('hero_subtitle_ar')->nullable();
            $table->text('hero_subtitle_en')->nullable();
            $table->string('hero_cta_ar', 120)->nullable();
            $table->string('hero_cta_en', 120)->nullable();
            $table->string('facebook_url', 500)->nullable();
            $table->string('facebook_title_ar')->nullable();
            $table->string('facebook_title_en')->nullable();
            $table->text('facebook_text_ar')->nullable();
            $table->text('facebook_text_en')->nullable();
            $table->string('meta_title_ar')->nullable();
            $table->string('meta_title_en')->nullable();
            $table->text('meta_description_ar')->nullable();
            $table->text('meta_description_en')->nullable();
            $table->timestamps();
        });

        Schema::create('wholesale_web_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('section_key', 80);
            $table->string('section_type', 60);
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->longText('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'section_key'], 'wholesale_web_section_store_key_uq');
            $table->index(['store_id', 'sort_order'], 'wholesale_web_section_sort_idx');
        });

        Schema::create('wholesale_web_banners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('title');
            $table->string('image_path', 1000);
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_url', 1000)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'is_active', 'sort_order'], 'wholesale_web_banner_active_sort_idx');
            $table->index(['target_type', 'target_id'], 'wholesale_web_banner_target_idx');
        });

        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id')
            ->get(['stores.id']);

        foreach ($stores as $store) {
            $storeId = (int) $store->id;
            $settings = Schema::hasTable('storefront_settings')
                ? DB::table('storefront_settings')->where('store_id', $storeId)->first()
                : null;
            $branding = $this->decodeJson($settings->branding ?? null);

            DB::table('wholesale_web_settings')->updateOrInsert(
                ['store_id' => $storeId],
                [
                    'is_enabled' => filter_var($branding['public_web_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                    'hero_title_ar' => $this->nullable($branding['brand_title_ar'] ?? null),
                    'hero_title_en' => $this->nullable($branding['brand_title_en'] ?? null),
                    'hero_subtitle_ar' => $this->nullable($branding['brand_subtitle_ar'] ?? null),
                    'hero_subtitle_en' => $this->nullable($branding['brand_subtitle_en'] ?? null),
                    'hero_cta_ar' => $this->nullable($branding['hero_cta_ar'] ?? null),
                    'hero_cta_en' => $this->nullable($branding['hero_cta_en'] ?? null),
                    'facebook_url' => $this->nullable($branding['facebook_url'] ?? null),
                    'facebook_title_ar' => $this->nullable($branding['facebook_title_ar'] ?? null),
                    'facebook_title_en' => $this->nullable($branding['facebook_title_en'] ?? null),
                    'facebook_text_ar' => $this->nullable($branding['facebook_text_ar'] ?? null),
                    'facebook_text_en' => $this->nullable($branding['facebook_text_en'] ?? null),
                    'meta_title_ar' => null,
                    'meta_title_en' => null,
                    'meta_description_ar' => null,
                    'meta_description_en' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            if (Schema::hasTable('storefront_sections')) {
                DB::table('storefront_sections')
                    ->where('store_id', $storeId)
                    ->whereIn('section_type', [
                        'hero',
                        'banner_slider',
                        'categories',
                        'benefits',
                        'offers',
                        'featured_products',
                        'best_sellers',
                        'brands',
                        'social',
                        'product_grid',
                        'product_carousel',
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $section) use ($storeId): void {
                        DB::table('wholesale_web_sections')->updateOrInsert(
                            ['store_id' => $storeId, 'section_key' => (string) $section->section_key],
                            [
                                'section_type' => (string) $section->section_type,
                                'title_ar' => $section->title_ar,
                                'title_en' => $section->title_en,
                                'sort_order' => (int) $section->sort_order,
                                'config' => $section->config,
                                'is_active' => (bool) $section->is_active,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        );
                    });
            }

            if (Schema::hasTable('banners')) {
                DB::table('banners')
                    ->where('store_id', $storeId)
                    ->where(function ($query): void {
                        $query->whereNull('target_type')->orWhere('target_type', '!=', 'retail_store');
                    })
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $banner) use ($storeId): void {
                        DB::table('wholesale_web_banners')->insert([
                            'store_id' => $storeId,
                            'title' => (string) $banner->title,
                            'image_path' => (string) $banner->image_path,
                            'target_type' => $banner->target_type,
                            'target_id' => $banner->target_id,
                            'target_url' => $banner->target_url,
                            'sort_order' => (int) $banner->sort_order,
                            'is_active' => (bool) $banner->is_active,
                            'starts_at' => $banner->starts_at,
                            'ends_at' => $banner->ends_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    });
            }

            if ($settings !== null) {
                foreach ([
                    'public_web_enabled',
                    'facebook_url',
                    'facebook_title_ar',
                    'facebook_title_en',
                    'facebook_text_ar',
                    'facebook_text_en',
                ] as $webOnlyKey) {
                    unset($branding[$webOnlyKey]);
                }

                DB::table('storefront_settings')->where('store_id', $storeId)->update([
                    'branding' => $branding === []
                        ? null
                        : json_encode($branding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_web_banners');
        Schema::dropIfExists('wholesale_web_sections');
        Schema::dropIfExists('wholesale_web_settings');
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
};
