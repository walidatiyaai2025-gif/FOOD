<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('store_service_zones')) {
            Schema::create('store_service_zones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('country_code', 2)->default('EG');
                $table->string('city')->nullable();
                $table->string('area')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['country_code', 'city', 'area', 'is_active'], 'store_service_zone_lookup');
                $table->unique(['store_id', 'country_code', 'city', 'area'], 'store_service_zone_unique');
            });
        }

        if (!Schema::hasTable('storefront_settings')) {
            Schema::create('storefront_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('theme_code', 40)->default('retail_grocery');
                $table->string('primary_color', 9)->nullable();
                $table->string('primary_dark_color', 9)->nullable();
                $table->string('accent_color', 9)->nullable();
                $table->string('background_color', 9)->nullable();
                $table->string('header_address')->nullable();
                $table->json('branding')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('storefront_sections')) {
            Schema::create('storefront_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('section_key', 80);
                $table->string('section_type', 40);
                $table->string('title_ar')->nullable();
                $table->string('title_en')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('config')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['store_id', 'section_key']);
                $table->index(['store_id', 'is_active', 'sort_order']);
            });
        }

        if (!Schema::hasTable('retail_wholesale_product_mappings')) {
            Schema::create('retail_wholesale_product_mappings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('retail_store_id')->constrained('stores')->cascadeOnDelete();
                $table->unsignedBigInteger('source_wholesale_product_id');
                $table->unsignedBigInteger('retail_product_id');
                $table->foreign('source_wholesale_product_id', 'rwp_map_source_fk')
                    ->references('id')->on('products')->restrictOnDelete();
                $table->foreign('retail_product_id', 'rwp_map_target_fk')
                    ->references('id')->on('products')->restrictOnDelete();
                $table->decimal('quantity_conversion_factor', 14, 3)->default(1);
                $table->unsignedBigInteger('mapped_by_user_id')->nullable();
                $table->foreign('mapped_by_user_id', 'rwp_map_user_fk')
                    ->references('id')->on('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(
                    ['retail_store_id', 'source_wholesale_product_id'],
                    'retail_wholesale_product_mapping_unique',
                );
                $table->index(
                    ['retail_store_id', 'retail_product_id'],
                    'retail_wholesale_product_mapping_target_idx',
                );
            });
        }

        Schema::table('products', function (Blueprint $table): void {
            if (!Schema::hasColumn('products', 'barcode')) {
                $table->string('barcode', 120)->nullable()->index()->after('sku');
            }
        });

        Schema::table('b2b_price_rules', function (Blueprint $table): void {
            if (!Schema::hasColumn('b2b_price_rules', 'ordering_increment')) {
                $table->decimal('ordering_increment', 14, 3)->default(1)->after('minimum_quantity');
            }
            if (!Schema::hasColumn('b2b_price_rules', 'pack_size')) {
                $table->decimal('pack_size', 14, 3)->default(1)->after('ordering_increment');
            }
            if (!Schema::hasColumn('b2b_price_rules', 'case_size')) {
                $table->decimal('case_size', 14, 3)->nullable()->after('pack_size');
            }
            if (!Schema::hasColumn('b2b_price_rules', 'pack_label')) {
                $table->string('pack_label', 80)->nullable()->after('case_size');
            }
            if (!Schema::hasColumn('b2b_price_rules', 'retail_reference_price')) {
                $table->decimal('retail_reference_price', 14, 3)->nullable()->after('unit_price');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (!Schema::hasColumn('order_items', 'quantity_conversion_factor')) {
                $table->decimal('quantity_conversion_factor', 14, 3)->default(1)->after('quantity');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'requested_delivery_date')) {
                $table->date('requested_delivery_date')->nullable()->after('address_id');
            }
        });

        $retailStoreIds = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->pluck('stores.id');

        foreach ($retailStoreIds as $storeId) {
            DB::table('storefront_settings')->updateOrInsert(
                ['store_id' => (int) $storeId],
                [
                    'theme_code' => 'retail_grocery',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $defaults = [
                ['hero', 'hero', null, null, 10],
                ['categories', 'categories', 'التصنيفات', 'Categories', 20],
                ['featured_products', 'products', 'منتجات مميزة', 'Featured products', 30],
            ];

            foreach ($defaults as [$key, $type, $titleAr, $titleEn, $sort]) {
                DB::table('storefront_sections')->updateOrInsert(
                    ['store_id' => (int) $storeId, 'section_key' => $key],
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
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'requested_delivery_date')) {
                $table->dropColumn('requested_delivery_date');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'quantity_conversion_factor')) {
                $table->dropColumn('quantity_conversion_factor');
            }
        });

        Schema::table('b2b_price_rules', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['ordering_increment', 'pack_size', 'case_size', 'pack_label', 'retail_reference_price'],
                fn (string $column): bool => Schema::hasColumn('b2b_price_rules', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'barcode')) {
                $table->dropColumn('barcode');
            }
        });

        Schema::dropIfExists('retail_wholesale_product_mappings');
        Schema::dropIfExists('storefront_sections');
        Schema::dropIfExists('storefront_settings');
        Schema::dropIfExists('store_service_zones');
    }
};
