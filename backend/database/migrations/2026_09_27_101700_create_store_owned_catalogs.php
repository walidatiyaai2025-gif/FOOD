<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->default('default');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code'], 'catalogs_store_code_unique');
            $table->index(['store_id', 'is_active'], 'catalogs_store_active_idx');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('catalog_id')->nullable();
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedBigInteger('catalog_id')->nullable();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_slug_unique');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_sku_unique');
        });

        [$wholesaleStoreId, $catalogByStore] = $this->ensureCatalogOwners();
        $categoryMap = $this->backfillCategories($catalogByStore, $wholesaleStoreId);
        $this->backfillProducts($catalogByStore, $categoryMap, $wholesaleStoreId);

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('catalog_id', 'categories_catalog_fk')
                ->references('id')
                ->on('catalogs')
                ->restrictOnDelete();
            $table->unique(['catalog_id', 'slug'], 'categories_catalog_slug_unique');
            $table->index(['catalog_id', 'is_active'], 'categories_catalog_active_idx');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreign('catalog_id', 'products_catalog_fk')
                ->references('id')
                ->on('catalogs')
                ->restrictOnDelete();
            $table->unique(['catalog_id', 'sku'], 'products_catalog_sku_unique');
            $table->index(['catalog_id', 'is_active'], 'products_catalog_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign('products_catalog_fk');
            $table->dropUnique('products_catalog_sku_unique');
            $table->dropIndex('products_catalog_active_idx');
            $table->dropColumn('catalog_id');
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropForeign('categories_catalog_fk');
            $table->dropUnique('categories_catalog_slug_unique');
            $table->dropIndex('categories_catalog_active_idx');
            $table->dropColumn('catalog_id');
        });

        Schema::dropIfExists('catalogs');
    }

    /**
     * @return array{0:int,1:array<int,int>}
     */
    private function ensureCatalogOwners(): array
    {
        $now = now();
        $b2bTypeId = DB::table('store_types')->where('code', 'B2B')->value('id');

        if ($b2bTypeId === null) {
            $b2bTypeId = DB::table('store_types')->insertGetId([
                'code' => 'B2B',
                'name' => 'Wholesale',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $wholesaleStoreId = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id')
            ->value('stores.id');

        if ($wholesaleStoreId === null) {
            $code = 'FOODEX-WHOLESALE';
            $suffix = 1;
            while (DB::table('stores')->where('code', $code)->exists()) {
                $code = 'FOODEX-WHOLESALE-'.$suffix++;
            }

            $wholesaleStoreId = DB::table('stores')->insertGetId([
                'store_type_id' => $b2bTypeId,
                'code' => $code,
                'name' => 'FOODEX Wholesale',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $catalogByStore = [];
        foreach (DB::table('stores')->orderBy('id')->get(['id', 'name']) as $store) {
            $catalogId = DB::table('catalogs')
                ->where('store_id', $store->id)
                ->where('code', 'default')
                ->value('id');

            if ($catalogId === null) {
                $catalogId = DB::table('catalogs')->insertGetId([
                    'store_id' => $store->id,
                    'code' => 'default',
                    'name' => $store->name.' Catalog',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $catalogByStore[(int) $store->id] = (int) $catalogId;
        }

        return [(int) $wholesaleStoreId, $catalogByStore];
    }

    /**
     * @param  array<int,int>  $catalogByStore
     * @return array<int,array<int,int>>
     */
    private function backfillCategories(array $catalogByStore, int $wholesaleStoreId): array
    {
        $categories = DB::table('categories')->orderBy('id')->get();
        $wholesaleCatalogId = $catalogByStore[$wholesaleStoreId];
        $map = [];

        foreach ($categories as $category) {
            DB::table('categories')->where('id', $category->id)->update([
                'catalog_id' => $wholesaleCatalogId,
            ]);
            $map[$wholesaleCatalogId][(int) $category->id] = (int) $category->id;
        }

        foreach ($catalogByStore as $storeId => $catalogId) {
            if ($storeId === $wholesaleStoreId) {
                continue;
            }

            foreach ($categories as $category) {
                $cloneId = DB::table('categories')->insertGetId([
                    'catalog_id' => $catalogId,
                    'parent_id' => null,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'is_active' => $category->is_active,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                ]);
                $map[$catalogId][(int) $category->id] = (int) $cloneId;
            }

            foreach ($categories as $category) {
                if ($category->parent_id === null) {
                    continue;
                }

                DB::table('categories')
                    ->where('id', $map[$catalogId][(int) $category->id])
                    ->update([
                        'parent_id' => $map[$catalogId][(int) $category->parent_id] ?? null,
                    ]);
            }
        }

        return $map;
    }

    /**
     * @param  array<int,int>  $catalogByStore
     * @param  array<int,array<int,int>>  $categoryMap
     */
    private function backfillProducts(array $catalogByStore, array $categoryMap, int $wholesaleStoreId): void
    {
        $owners = [];
        $this->collectOwners($owners, DB::table('store_products')->get(['product_id', 'store_id']));

        $inventoryOwners = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->whereNotNull('warehouses.store_id')
            ->get(['inventories.product_id', 'warehouses.store_id']);
        $this->collectOwners($owners, $inventoryOwners);

        $orderOwners = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->get(['order_items.product_id', 'orders.store_id']);
        $this->collectOwners($owners, $orderOwners);

        $cartOwners = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->get(['cart_items.product_id', 'carts.store_id']);
        $this->collectOwners($owners, $cartOwners);

        if (Schema::hasTable('b2b_price_rules')) {
            $this->collectOwners(
                $owners,
                DB::table('b2b_price_rules')->get(['product_id', 'store_id']),
            );
        }

        $products = DB::table('products')->orderBy('id')->get();
        $productMap = [];

        foreach ($products as $product) {
            $storeIds = array_values(array_unique($owners[(int) $product->id] ?? []));
            sort($storeIds);
            if ($storeIds === []) {
                $storeIds = [$wholesaleStoreId];
            }

            $primaryStoreId = $storeIds[0];
            $primaryCatalogId = $catalogByStore[$primaryStoreId];
            $primaryCategoryId = $product->category_id === null
                ? null
                : ($categoryMap[$primaryCatalogId][(int) $product->category_id] ?? null);

            DB::table('products')->where('id', $product->id)->update([
                'catalog_id' => $primaryCatalogId,
                'category_id' => $primaryCategoryId,
            ]);
            $productMap[$primaryStoreId][(int) $product->id] = (int) $product->id;

            foreach (array_slice($storeIds, 1) as $storeId) {
                $catalogId = $catalogByStore[$storeId];
                $categoryId = $product->category_id === null
                    ? null
                    : ($categoryMap[$catalogId][(int) $product->category_id] ?? null);

                $cloneId = DB::table('products')->insertGetId([
                    'catalog_id' => $catalogId,
                    'category_id' => $categoryId,
                    'brand_id' => $product->brand_id,
                    'unit_id' => $product->unit_id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    'is_active' => $product->is_active,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ]);
                $productMap[$storeId][(int) $product->id] = (int) $cloneId;

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
            }
        }

        foreach ($productMap as $storeId => $map) {
            foreach ($map as $legacyProductId => $ownedProductId) {
                if ($legacyProductId === $ownedProductId) {
                    continue;
                }

                DB::table('store_products')
                    ->where('store_id', $storeId)
                    ->where('product_id', $legacyProductId)
                    ->update(['product_id' => $ownedProductId]);

                $inventoryIds = DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->where('warehouses.store_id', $storeId)
                    ->where('inventories.product_id', $legacyProductId)
                    ->pluck('inventories.id');
                if ($inventoryIds->isNotEmpty()) {
                    DB::table('inventories')->whereIn('id', $inventoryIds)->update([
                        'product_id' => $ownedProductId,
                    ]);
                }

                $orderItemIds = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.store_id', $storeId)
                    ->where('order_items.product_id', $legacyProductId)
                    ->pluck('order_items.id');
                if ($orderItemIds->isNotEmpty()) {
                    DB::table('order_items')->whereIn('id', $orderItemIds)->update([
                        'product_id' => $ownedProductId,
                    ]);
                }

                $cartItemIds = DB::table('cart_items')
                    ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                    ->where('carts.store_id', $storeId)
                    ->where('cart_items.product_id', $legacyProductId)
                    ->pluck('cart_items.id');
                if ($cartItemIds->isNotEmpty()) {
                    DB::table('cart_items')->whereIn('id', $cartItemIds)->update([
                        'product_id' => $ownedProductId,
                    ]);
                }

                $invoiceItemIds = DB::table('invoice_items')
                    ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                    ->join('orders', 'orders.id', '=', 'invoices.order_id')
                    ->where('orders.store_id', $storeId)
                    ->where('invoice_items.product_id', $legacyProductId)
                    ->pluck('invoice_items.id');
                if ($invoiceItemIds->isNotEmpty()) {
                    DB::table('invoice_items')->whereIn('id', $invoiceItemIds)->update([
                        'product_id' => $ownedProductId,
                    ]);
                }

                if (Schema::hasTable('b2b_price_rules')) {
                    DB::table('b2b_price_rules')
                        ->where('store_id', $storeId)
                        ->where('product_id', $legacyProductId)
                        ->update(['product_id' => $ownedProductId]);
                }
            }
        }
    }

    /**
     * @param  array<int,list<int>>  $owners
     * @param  iterable<object>  $rows
     */
    private function collectOwners(array &$owners, iterable $rows): void
    {
        foreach ($rows as $row) {
            if ($row->store_id === null) {
                continue;
            }
            $owners[(int) $row->product_id][] = (int) $row->store_id;
        }
    }
};
