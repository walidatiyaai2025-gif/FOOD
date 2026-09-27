<?php

namespace App\Services;

use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

final class CatalogOwnership
{
    public function defaultCatalogForStore(int $storeId, ?string $channel = null): Catalog
    {
        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->first(['stores.name', 'store_types.code as channel']);
        abort_if($store === null, 404);

        $resolvedChannel = strtolower((string) $store->channel);
        if ($channel !== null) {
            abort_unless($resolvedChannel === strtolower($channel), 404);
        }

        return Catalog::query()->firstOrCreate(
            ['store_id' => $storeId, 'code' => 'default'],
            [
                'channel' => $resolvedChannel,
                'name' => (string) $store->name.' Catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
            ],
        );
    }

    public function productForStore(int $productId, int $storeId): Product
    {
        return Product::query()->forStore($storeId)->whereKey($productId)->firstOrFail();
    }

    public function categoryForStore(int $categoryId, int $storeId): Category
    {
        return Category::query()->forStore($storeId)->whereKey($categoryId)->firstOrFail();
    }

    public function assertSameCatalog(?int $categoryId, int $catalogId): void
    {
        if ($categoryId === null) {
            return;
        }

        abort_unless(
            Category::query()->whereKey($categoryId)->where('catalog_id', $catalogId)->exists(),
            422,
            'Category must belong to the same catalog as the product.',
        );
    }

    public function assertParentInCatalog(?int $parentId, int $catalogId): void
    {
        if ($parentId === null) {
            return;
        }

        abort_unless(
            Category::query()->whereKey($parentId)->where('catalog_id', $catalogId)->exists(),
            422,
            'Parent category must belong to the same catalog.',
        );
    }
}
