<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CatalogImageService
{
    /** @param list<UploadedFile> $files */
    public function addProductImages(int $productId, array $files): void
    {
        $hasPrimary = DB::table('product_images')
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->exists();
        $nextSort = (int) DB::table('product_images')
            ->where('product_id', $productId)
            ->max('sort_order') + 1;

        foreach ($files as $index => $file) {
            $path = $file->store("catalog/products/{$productId}", 'public');
            DB::table('product_images')->insert([
                'product_id' => $productId,
                'path' => 'storage/'.$path,
                'sort_order' => $nextSort + $index,
                'is_primary' => ! $hasPrimary && $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function setPrimary(int $productId, int $imageId): void
    {
        abort_unless(
            DB::table('product_images')
                ->where('id', $imageId)
                ->where('product_id', $productId)
                ->exists(),
            404,
        );

        DB::transaction(function () use ($productId, $imageId): void {
            DB::table('product_images')
                ->where('product_id', $productId)
                ->update(['is_primary' => false, 'updated_at' => now()]);

            DB::table('product_images')
                ->where('id', $imageId)
                ->where('product_id', $productId)
                ->update(['is_primary' => true, 'updated_at' => now()]);
        });
    }

    public function updateProductImage(int $productId, int $imageId, int $sortOrder, bool $primary): void
    {
        abort_unless(
            DB::table('product_images')
                ->where('id', $imageId)
                ->where('product_id', $productId)
                ->exists(),
            404,
        );

        DB::table('product_images')
            ->where('id', $imageId)
            ->where('product_id', $productId)
            ->update([
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);

        if ($primary) {
            $this->setPrimary($productId, $imageId);
        }
    }

    public function deleteProductImage(int $productId, int $imageId): void
    {
        $image = DB::table('product_images')
            ->where('id', $imageId)
            ->where('product_id', $productId)
            ->first(['id', 'path', 'is_primary']);
        abort_if($image === null, 404);

        $this->deletePublicPath($image->path);
        DB::table('product_images')->where('id', $imageId)->delete();

        if ((bool) $image->is_primary) {
            $replacement = DB::table('product_images')
                ->where('product_id', $productId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($replacement !== null) {
                DB::table('product_images')
                    ->where('id', $replacement)
                    ->update(['is_primary' => true, 'updated_at' => now()]);
            }
        }
    }

    public function replaceCategoryImage(int $categoryId, UploadedFile $file): string
    {
        $oldPath = DB::table('categories')->where('id', $categoryId)->value('image_path');
        $path = $file->store("catalog/categories/{$categoryId}", 'public');
        $storedPath = 'storage/'.$path;

        DB::table('categories')->where('id', $categoryId)->update([
            'image_path' => $storedPath,
            'updated_at' => now(),
        ]);

        $this->deletePublicPath($oldPath);

        return $storedPath;
    }

    public function removeCategoryImage(int $categoryId): void
    {
        $path = DB::table('categories')->where('id', $categoryId)->value('image_path');
        DB::table('categories')->where('id', $categoryId)->update([
            'image_path' => null,
            'updated_at' => now(),
        ]);
        $this->deletePublicPath($path);
    }

    public function replaceBrandImage(int $brandId, UploadedFile $file): string
    {
        $oldPath = DB::table('brands')->where('id', $brandId)->value('image_path');
        $path = $file->store("catalog/brands/{$brandId}", 'public');
        $storedPath = 'storage/'.$path;

        DB::table('brands')->where('id', $brandId)->update([
            'image_path' => $storedPath,
            'updated_at' => now(),
        ]);

        $this->deletePublicPath($oldPath);

        return $storedPath;
    }

    public function removeBrandImage(int $brandId): void
    {
        $path = DB::table('brands')->where('id', $brandId)->value('image_path');
        DB::table('brands')->where('id', $brandId)->update([
            'image_path' => null,
            'updated_at' => now(),
        ]);
        $this->deletePublicPath($path);
    }

    public function purgeProductImages(int $productId): void
    {
        $paths = DB::table('product_images')->where('product_id', $productId)->pluck('path');
        foreach ($paths as $path) {
            $this->deletePublicPath($path);
        }
    }

    private function deletePublicPath(mixed $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $path = ltrim(trim($path), '/');
        if (str_starts_with($path, 'storage/')) {
            Storage::disk('public')->delete(substr($path, strlen('storage/')));
        }
    }
}
