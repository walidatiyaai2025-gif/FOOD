<?php

namespace App\Services;

use App\Models\Catalog;
use App\Models\Category;
use App\Models\Product;

final class CatalogOwnership
{
    public function defaultCatalogForStore(int $storeId, ?string $channel = null): Catalog
    {
        $query = Catalog::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('is_migration_quarantine', false)
            ->orderBy('id');

        if ($channel !== null) {
            $query->where('channel', strtolower($channel));
        }

        return $query->firstOrFail();
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
