<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const QUARANTINE_STORE_CODE = 'SYSTEM-LEGACY-QUARANTINE';

    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('channel', 10);
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_migration_quarantine')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'code'], 'catalogs_store_code_unique');
            $table->index(['store_id', 'channel', 'is_active'], 'catalogs_store_channel_active_idx');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->restrictOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->restrictOnDelete();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_slug_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_sku_unique');
        });

        DB::transaction(function (): void {
            $catalogByStore = $this->createStoreCatalogs();
            $ownership = $this->productStoreOwnership();
            $quarantineCatalogId = null;

            foreach (DB::table('products')->orderBy('id')->get() as $product) {
                $storeIds = array_values(array_unique($ownership[(int) $product->id] ?? []));

                if ($storeIds === []) {
                    $quarantineCatalogId ??= $this->ensureQuarantineCatalog($catalogByStore);
                    $storeIds = [(int) DB::table('catalogs')->where('id', $quarantineCatalogId)->value('store_id')];
                }

                sort($storeIds);
                $primaryStoreId = $storeIds[0];
                $primaryCatalogId = $catalogByStore[$primaryStoreId] ?? null;

                if ($primaryCatalogId === null) {
                    $primaryCatalogId = $this->createDefaultCatalogForStore($primaryStoreId);
                    $catalogByStore[$primaryStoreId] = $primaryCatalogId;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'catalog_id' => $primaryCatalogId,
                    'updated_at' => now(),
                ]);

                foreach (array_slice($storeIds, 1) as $storeId) {
                    $catalogId = $catalogByStore[$storeId] ?? null;
                    if ($catalogId === null) {
                        $catalogId = $this->createDefaultCatalogForStore($storeId);
                        $catalogByStore[$storeId] = $catalogId;
                    }

                    $cloneId = (int) DB::table('products')->insertGetId([
                        'catalog_id' => $catalogId,
                        'category_id' => $product->category_id,
                        'brand_id' => $product->brand_id,
                        'unit_id' => $product->unit_id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'description' => $product->description,
                        'is_active' => $product->is_active,
                        'created_at' => $product->created_at,
                        'updated_at' => now(),
                    ]);

                    foreach (DB::table('product_images')->where('product_id', $product->id)->get() as $image) {
                        DB::table('product_images')->insert([
                            'product_id' => $cloneId,
                            'path' => $image->path,
                            'sort_order' => $image->sort_order,
                            'is_primary' => $image->is_primary,
                            'created_at' => $image->created_at,
                            'updated_at' => $image->updated_at,
                        ]);
                    }

                    $this->rewireProductReferences((int) $product->id, $cloneId, $storeId);
                }
            }

            $quarantineCatalogId = $this->backfillCategoryCatalogs($catalogByStore, $quarantineCatalogId);

            // Final reconciliation: every migrated legacy row must have explicit ownership.
            if (DB::table('products')->whereNull('catalog_id')->exists()
                || DB::table('categories')->whereNull('catalog_id')->exists()) {
                throw new RuntimeException('Catalog ownership reconciliation left unresolved products/categories.');
            }
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->unique(['catalog_id', 'slug'], 'categories_catalog_slug_unique');
            $table->index('catalog_id', 'categories_catalog_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['catalog_id', 'sku'], 'products_catalog_sku_unique');
            $table->index(['catalog_id', 'is_active'], 'products_catalog_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_catalog_sku_unique');
            $table->dropIndex('products_catalog_active_idx');
            $table->dropConstrainedForeignId('catalog_id');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_catalog_slug_unique');
            $table->dropIndex('categories_catalog_idx');
            $table->dropConstrainedForeignId('catalog_id');
        });

        Schema::dropIfExists('catalogs');
    }

    /** @return array<int, int> store_id => catalog_id */
    private function createStoreCatalogs(): array
    {
        $map = [];

        foreach (DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.name', 'store_types.code as channel']) as $store) {
            $catalogId = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $store->id,
                'channel' => strtolower((string) $store->channel),
                'code' => 'default',
                'name' => $store->name.' Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $map[(int) $store->id] = $catalogId;
        }

        return $map;
    }

    /** @return array<int, list<int>> */
    private function productStoreOwnership(): array
    {
        $ownership = [];

        $append = static function (iterable $rows) use (&$ownership): void {
            foreach ($rows as $row) {
                $productId = (int) $row->product_id;
                $storeId = (int) $row->store_id;
                if ($storeId <= 0) {
                    continue;
                }
                $ownership[$productId] ??= [];
                $ownership[$productId][] = $storeId;
            }
        };

        $append(DB::table('store_products')->select('product_id', 'store_id')->orderBy('product_id')->cursor());

        $append(DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->whereNotNull('warehouses.store_id')
            ->select('inventories.product_id', 'warehouses.store_id')
            ->orderBy('inventories.product_id')
            ->cursor());

        $append(DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->select('cart_items.product_id', 'carts.store_id')
            ->orderBy('cart_items.product_id')
            ->cursor());

        $append(DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->select('order_items.product_id', 'orders.store_id')
            ->orderBy('order_items.product_id')
            ->cursor());

        if (Schema::hasTable('b2b_price_rules')) {
            $append(DB::table('b2b_price_rules')
                ->select('product_id', 'store_id')
                ->orderBy('product_id')
                ->cursor());
        }

        foreach ($ownership as &$storeIds) {
            $storeIds = array_values(array_unique($storeIds));
        }

        return $ownership;
    }

    private function rewireProductReferences(int $originProductId, int $cloneProductId, int $storeId): void
    {
        DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $originProductId)
            ->update(['product_id' => $cloneProductId, 'updated_at' => now()]);

        $warehouseIds = DB::table('warehouses')->where('store_id', $storeId)->pluck('id');
        if ($warehouseIds->isNotEmpty()) {
            DB::table('inventories')
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('product_id', $originProductId)
                ->update(['product_id' => $cloneProductId, 'updated_at' => now()]);
        }

        $cartIds = DB::table('carts')->where('store_id', $storeId)->pluck('id');
        if ($cartIds->isNotEmpty()) {
            DB::table('cart_items')
                ->whereIn('cart_id', $cartIds)
                ->where('product_id', $originProductId)
                ->update(['product_id' => $cloneProductId, 'updated_at' => now()]);
        }

        $orderIds = DB::table('orders')->where('store_id', $storeId)->pluck('id');
        if ($orderIds->isNotEmpty()) {
            DB::table('order_items')
                ->whereIn('order_id', $orderIds)
                ->where('product_id', $originProductId)
                ->update(['product_id' => $cloneProductId, 'updated_at' => now()]);
        }

        if (Schema::hasTable('b2b_price_rules')) {
            DB::table('b2b_price_rules')
                ->where('store_id', $storeId)
                ->where('product_id', $originProductId)
                ->update(['product_id' => $cloneProductId, 'updated_at' => now()]);
        }
    }

    /**
     * @param array<int, int> $catalogByStore
     */
    private function backfillCategoryCatalogs(array &$catalogByStore, ?int $quarantineCatalogId): ?int
    {
        $categories = DB::table('categories')->orderBy('id')->get()->keyBy('id');
        $catalogsByCategory = [];

        foreach (DB::table('products')->whereNotNull('category_id')->get(['category_id', 'catalog_id']) as $row) {
            $categoryId = (int) $row->category_id;
            $catalogId = (int) $row->catalog_id;
            $catalogsByCategory[$categoryId] ??= [];
            $catalogsByCategory[$categoryId][] = $catalogId;
        }

        // Parent categories inherit every catalog used by their descendants.
        foreach (array_keys($catalogsByCategory) as $categoryId) {
            foreach (array_values(array_unique($catalogsByCategory[$categoryId])) as $catalogId) {
                $cursor = $categoryId;
                while ($cursor !== 0 && isset($categories[$cursor])) {
                    $catalogsByCategory[$cursor] ??= [];
                    $catalogsByCategory[$cursor][] = $catalogId;
                    $parentId = $categories[$cursor]->parent_id;
                    $cursor = $parentId === null ? 0 : (int) $parentId;
                }
            }
        }

        $mapping = [];

        foreach ($categories as $category) {
            $catalogIds = array_values(array_unique($catalogsByCategory[(int) $category->id] ?? []));

            if ($catalogIds === []) {
                $quarantineCatalogId ??= $this->ensureQuarantineCatalog($catalogByStore);
                $catalogIds = [$quarantineCatalogId];
            }

            sort($catalogIds);
            $primaryCatalogId = $catalogIds[0];
            DB::table('categories')->where('id', $category->id)->update([
                'catalog_id' => $primaryCatalogId,
                'updated_at' => now(),
            ]);
            $mapping[(int) $category->id][$primaryCatalogId] = (int) $category->id;

            foreach (array_slice($catalogIds, 1) as $catalogId) {
                $cloneId = (int) DB::table('categories')->insertGetId([
                    'catalog_id' => $catalogId,
                    'parent_id' => null,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'is_active' => $category->is_active,
                    'created_at' => $category->created_at,
                    'updated_at' => now(),
                ]);
                $mapping[(int) $category->id][$catalogId] = $cloneId;
            }
        }

        foreach ($categories as $category) {
            $originId = (int) $category->id;
            foreach ($mapping[$originId] ?? [] as $catalogId => $mappedCategoryId) {
                $parentId = $category->parent_id === null
                    ? null
                    : ($mapping[(int) $category->parent_id][$catalogId] ?? null);

                DB::table('categories')->where('id', $mappedCategoryId)->update([
                    'parent_id' => $parentId,
                    'updated_at' => now(),
                ]);
            }
        }

        foreach (DB::table('products')->whereNotNull('category_id')->get(['id', 'category_id', 'catalog_id']) as $product) {
            $mappedCategoryId = $mapping[(int) $product->category_id][(int) $product->catalog_id] ?? null;
            if ($mappedCategoryId === null) {
                throw new RuntimeException('Product category could not be reconciled into the owning catalog.');
            }

            DB::table('products')->where('id', $product->id)->update([
                'category_id' => $mappedCategoryId,
                'updated_at' => now(),
            ]);
        }

        return $quarantineCatalogId;
    }

    /**
     * @param array<int, int> $catalogByStore
     */
    private function ensureQuarantineCatalog(array &$catalogByStore): int
    {
        $storeId = (int) (DB::table('stores')->where('code', self::QUARANTINE_STORE_CODE)->value('id') ?? 0);

        if ($storeId === 0) {
            $typeId = (int) (DB::table('store_types')->where('code', 'B2B')->value('id') ?? 0);
            if ($typeId === 0) {
                $typeId = (int) DB::table('store_types')->insertGetId([
                    'code' => 'B2B',
                    'name' => 'Wholesale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $storeId = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $typeId,
                'code' => self::QUARANTINE_STORE_CODE,
                'name' => 'Legacy Catalog Quarantine',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('catalogs')
            ->where('store_id', $storeId)
            ->where('code', 'legacy-quarantine')
            ->value('id');

        if ($existing !== null) {
            $catalogByStore[$storeId] = (int) $existing;

            return (int) $existing;
        }

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'legacy-quarantine',
            'name' => 'Legacy Catalog Quarantine',
            'is_active' => false,
            'is_migration_quarantine' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogByStore[$storeId] = $catalogId;

        return $catalogId;
    }

    private function createDefaultCatalogForStore(int $storeId): int
    {
        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->first(['stores.name', 'store_types.code as channel']);

        if ($store === null) {
            throw new RuntimeException('Cannot create catalog for a missing store.');
        }

        return (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => strtolower((string) $store->channel),
            'code' => 'default',
            'name' => $store->name.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
