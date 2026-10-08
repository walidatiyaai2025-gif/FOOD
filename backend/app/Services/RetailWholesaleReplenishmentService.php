<?php

namespace App\Services;

use App\Models\Catalog;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RetailWholesaleReplenishmentService
{
    /** @var array<string, int> */
    private array $categoryCache = [];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function receive(Order $order, User $actor): ?int
    {
        if (strtolower((string) $order->channel) !== 'b2b' || $order->b2b_customer_id === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $actor): ?int {
            $mapping = DB::table('retail_wholesale_accounts')
                ->join('stores', 'stores.id', '=', 'retail_wholesale_accounts.retail_store_id')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('retail_wholesale_accounts.b2b_customer_id', $order->b2b_customer_id)
                ->where('store_types.code', 'B2C')
                ->first([
                    'retail_wholesale_accounts.retail_store_id',
                    'stores.name as retail_store_name',
                    'stores.code as retail_store_code',
                ]);

            if ($mapping === null) {
                return null;
            }

            $existing = DB::table('retail_replenishments')
                ->where('source_order_id', $order->getKey())
                ->value('id');

            if ($existing !== null) {
                return (int) $existing;
            }

            $retailStoreId = (int) $mapping->retail_store_id;
            $catalogId = $this->retailCatalogId($retailStoreId, (string) $mapping->retail_store_name);
            $warehouseId = $this->receivingWarehouseId(
                $retailStoreId,
                (string) $mapping->retail_store_code,
            );

            $replenishmentId = (int) DB::table('retail_replenishments')->insertGetId([
                'retail_store_id' => $retailStoreId,
                'source_order_id' => $order->getKey(),
                'source_wholesale_store_id' => (int) $order->store_id,
                'b2b_customer_id' => (int) $order->b2b_customer_id,
                'received_by_user_id' => $actor->getKey(),
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = DB::table('order_items')
                ->where('order_id', $order->getKey())
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                $source = DB::table('products')
                    ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                    ->where('products.id', $item->product_id)
                    ->where('catalogs.store_id', $order->store_id)
                    ->where('catalogs.channel', 'b2b')
                    ->first([
                        'products.id',
                        'products.category_id',
                        'products.brand_id',
                        'products.unit_id',
                        'products.description',
                        'products.is_active',
                    ]);

                if ($source === null) {
                    throw new HttpException(409, 'Wholesale order contains a product outside its source catalog.');
                }

                $conversionFactor = (float) ($item->quantity_conversion_factor ?? 1);
                if ($conversionFactor <= 0) {
                    throw new HttpException(
                        409,
                        'Wholesale order item has an invalid quantity conversion factor.',
                    );
                }

                $mapping = DB::table('retail_wholesale_product_mappings')
                    ->where('retail_store_id', $retailStoreId)
                    ->where('source_wholesale_product_id', $source->id)
                    ->first(['retail_product_id', 'quantity_conversion_factor']);

                if ($mapping !== null) {
                    $retailProductId = (int) $mapping->retail_product_id;
                    $conversionFactor = (float) $mapping->quantity_conversion_factor;
                    if ($conversionFactor <= 0) {
                        throw new HttpException(
                            409,
                            'Wholesale product mapping has an invalid quantity conversion factor.',
                        );
                    }

                    $mappedTargetExists = DB::table('products')
                        ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                        ->where('products.id', $retailProductId)
                        ->where('catalogs.store_id', $retailStoreId)
                        ->where('catalogs.channel', 'b2c')
                        ->exists();
                    abort_unless($mappedTargetExists, 409, 'Wholesale product mapping points outside the retail tenant.');
                } else {
                    $existingTarget = DB::table('products')
                        ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                        ->where('catalogs.id', $catalogId)
                        ->where('products.sku', $item->sku_snapshot)
                        ->first(['products.id']);

                    if ($existingTarget === null && abs($conversionFactor - 1.0) > 0.0001) {
                        throw new HttpException(
                            409,
                            'Converted wholesale packs require an explicit retail product mapping before receipt.',
                        );
                    }

                    $unitId = $this->retailUnitId((int) $source->unit_id, $retailStoreId);
                    $brandId = $source->brand_id === null
                        ? null
                        : $this->retailBrandId((int) $source->brand_id, $retailStoreId);
                    $categoryId = $source->category_id === null
                        ? null
                        : $this->retailCategoryId((int) $source->category_id, $catalogId);

                    $retailProductId = $this->retailProductId(
                        $catalogId,
                        (string) $item->sku_snapshot,
                        (string) $item->name_snapshot,
                        $source->description === null ? null : (string) $source->description,
                        $categoryId,
                        $brandId,
                        $unitId,
                        (bool) $source->is_active,
                    );

                    DB::table('retail_wholesale_product_mappings')->insert([
                        'retail_store_id' => $retailStoreId,
                        'source_wholesale_product_id' => (int) $source->id,
                        'retail_product_id' => $retailProductId,
                        'quantity_conversion_factor' => $conversionFactor,
                        'mapped_by_user_id' => $actor->getKey(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $retailUnitCost = round((float) $item->unit_price / $conversionFactor, 3);

                $this->syncImages((int) $source->id, $retailProductId);
                $this->syncStoreProduct(
                    $retailStoreId,
                    $retailProductId,
                    $retailUnitCost,
                );

                $inventoryId = $this->inventoryId($warehouseId, $retailProductId);
                $quantity = round((float) $item->quantity * $conversionFactor, 3);

                DB::table('inventories')
                    ->where('id', $inventoryId)
                    ->increment('quantity', $quantity, ['updated_at' => now()]);

                DB::table('stock_movements')->insert([
                    'inventory_id' => $inventoryId,
                    'store_id' => $retailStoreId,
                    'user_id' => $actor->getKey(),
                    'type' => 'purchase_receipt',
                    'quantity' => $quantity,
                    'reference_type' => 'retail_replenishment',
                    'reference_id' => $replenishmentId,
                    'reason' => 'Wholesale order '.$order->order_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('retail_replenishment_items')->insert([
                    'replenishment_id' => $replenishmentId,
                    'source_order_item_id' => (int) $item->id,
                    'source_product_id' => (int) $source->id,
                    'retail_product_id' => $retailProductId,
                    'quantity' => $quantity,
                    'quantity_conversion_factor' => round($conversionFactor, 3),
                    'unit_cost' => $retailUnitCost,
                    'line_total' => round((float) $item->line_total, 3),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(
                'retail.wholesale_order_received',
                $actor,
                Store::query()->findOrFail($retailStoreId),
                null,
                [
                    'store_id' => $retailStoreId,
                    'retail_store_id' => $retailStoreId,
                    'source_wholesale_store_id' => (int) $order->store_id,
                    'source_order_id' => (int) $order->getKey(),
                    'source_order_number' => (string) $order->order_number,
                    'replenishment_id' => $replenishmentId,
                    'item_count' => $items->count(),
                ],
            );

            return $replenishmentId;
        }, 3);
    }

    private function retailCatalogId(int $storeId, string $storeName): int
    {
        $catalog = Catalog::query()->firstOrCreate(
            ['store_id' => $storeId, 'code' => 'default'],
            [
                'channel' => 'b2c',
                'name' => $storeName.' Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
            ],
        );

        abort_unless(
            strtolower((string) $catalog->channel) === 'b2c' && ! $catalog->is_migration_quarantine,
            409,
            'Retail default catalog ownership is inconsistent.',
        );

        return (int) $catalog->getKey();
    }

    private function receivingWarehouseId(int $storeId, string $storeCode): int
    {
        $warehouse = DB::table('warehouses')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderByRaw("CASE
                WHEN UPPER(code) IN ('MAIN', 'PRIMARY') THEN 0
                WHEN UPPER(code) LIKE 'MAIN-%' OR UPPER(code) LIKE '%-MAIN' THEN 1
                WHEN LOWER(name) LIKE '%main%' OR name LIKE '%رئيس%' THEN 2
                ELSE 3
            END")
            ->orderBy('id')
            ->first(['id']);

        if ($warehouse !== null) {
            return (int) $warehouse->id;
        }

        $code = 'MAIN-'.strtoupper($storeCode);

        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => 'Main Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailUnitId(int $sourceUnitId, int $storeId): int
    {
        $source = DB::table('units')->where('id', $sourceUnitId)->first();
        abort_if($source === null, 409, 'Wholesale product unit is missing.');

        if ((string) $source->scope === 'global') {
            return $sourceUnitId;
        }

        $target = DB::table('units')
            ->where('scope_key', 'store:'.$storeId)
            ->where('code', $source->code)
            ->first(['id']);

        $values = [
            'store_id' => $storeId,
            'scope' => 'store',
            'name' => (string) $source->name,
            'name_ar' => $source->name_ar,
            'name_en' => $source->name_en,
            'decimal_places' => (int) $source->decimal_places,
            'is_active' => (bool) $source->is_active,
            'updated_at' => now(),
        ];

        if ($target === null) {
            return (int) DB::table('units')->insertGetId([
                'scope_key' => 'store:'.$storeId,
                'code' => (string) $source->code,
                ...$values,
                'created_at' => now(),
            ]);
        }

        DB::table('units')->where('id', $target->id)->update($values);

        return (int) $target->id;
    }

    private function retailBrandId(int $sourceBrandId, int $storeId): int
    {
        $source = DB::table('brands')->where('id', $sourceBrandId)->first();
        abort_if($source === null, 409, 'Wholesale product brand is missing.');

        if ((string) $source->scope === 'global') {
            return $sourceBrandId;
        }

        $target = DB::table('brands')
            ->where('scope_key', 'store:'.$storeId)
            ->where('slug', $source->slug)
            ->first(['id']);

        $values = [
            'store_id' => $storeId,
            'scope' => 'store',
            'name' => (string) $source->name,
            'name_ar' => $source->name_ar,
            'name_en' => $source->name_en,
            'image_path' => $source->image_path,
            'is_active' => (bool) $source->is_active,
            'updated_at' => now(),
        ];

        if ($target === null) {
            return (int) DB::table('brands')->insertGetId([
                'scope_key' => 'store:'.$storeId,
                'slug' => (string) $source->slug,
                ...$values,
                'created_at' => now(),
            ]);
        }

        DB::table('brands')->where('id', $target->id)->update($values);

        return (int) $target->id;
    }

    private function retailCategoryId(int $sourceCategoryId, int $catalogId): int
    {
        $cacheKey = $catalogId.':'.$sourceCategoryId;
        if (isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        $source = DB::table('categories')->where('id', $sourceCategoryId)->first();
        abort_if($source === null, 409, 'Wholesale product category is missing.');

        $parentId = $source->parent_id === null
            ? null
            : $this->retailCategoryId((int) $source->parent_id, $catalogId);

        $target = DB::table('categories')
            ->where('catalog_id', $catalogId)
            ->where('slug', $source->slug)
            ->first(['id']);

        if ($target === null) {
            $targetId = (int) DB::table('categories')->insertGetId([
                'catalog_id' => $catalogId,
                'parent_id' => $parentId,
                'name' => (string) $source->name,
                'slug' => (string) $source->slug,
                'image_path' => $source->image_path,
                'is_active' => (bool) $source->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $targetId = (int) $target->id;
            DB::table('categories')->where('id', $targetId)->update([
                'parent_id' => $parentId,
                'name' => (string) $source->name,
                'image_path' => $source->image_path,
                'updated_at' => now(),
            ]);
        }

        $this->categoryCache[$cacheKey] = $targetId;

        return $targetId;
    }

    private function retailProductId(
        int $catalogId,
        string $sku,
        string $name,
        ?string $description,
        ?int $categoryId,
        ?int $brandId,
        int $unitId,
        bool $sourceActive,
    ): int {
        $target = DB::table('products')
            ->where('catalog_id', $catalogId)
            ->where('sku', $sku)
            ->first(['id']);

        if ($target === null) {
            return (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalogId,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $unitId,
                'sku' => $sku,
                'name' => $name,
                'description' => $description,
                'is_active' => $sourceActive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productId = (int) $target->id;
        DB::table('products')->where('id', $productId)->update([
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'unit_id' => $unitId,
            'name' => $name,
            'description' => $description,
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function syncImages(int $sourceProductId, int $retailProductId): void
    {
        $hasPrimary = DB::table('product_images')
            ->where('product_id', $retailProductId)
            ->where('is_primary', true)
            ->exists();

        foreach (DB::table('product_images')->where('product_id', $sourceProductId)->orderBy('sort_order')->get() as $image) {
            $exists = DB::table('product_images')
                ->where('product_id', $retailProductId)
                ->where('path', $image->path)
                ->exists();

            if ($exists) {
                continue;
            }

            $isPrimary = ! $hasPrimary && (bool) $image->is_primary;
            DB::table('product_images')->insert([
                'product_id' => $retailProductId,
                'path' => (string) $image->path,
                'sort_order' => (int) $image->sort_order,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $hasPrimary = $hasPrimary || $isPrimary;
        }
    }

    private function syncStoreProduct(int $storeId, int $productId, float $unitCost): void
    {
        $existing = DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->first(['id']);

        if ($existing === null) {
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $productId,
                'price' => round($unitCost, 3),
                'cost_price' => round($unitCost, 3),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('store_products')->where('id', $existing->id)->update([
            'cost_price' => round($unitCost, 3),
            'updated_at' => now(),
        ]);
    }

    private function inventoryId(int $warehouseId, int $productId): int
    {
        $inventoryId = DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('id');

        if ($inventoryId !== null) {
            return (int) $inventoryId;
        }

        return (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 0,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
