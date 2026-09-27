<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            $storedPath = $this->storePublicImage($file, "catalog/products/{$productId}");

            try {
                DB::table('product_images')->insert([
                    'product_id' => $productId,
                    'path' => $storedPath,
                    'sort_order' => $nextSort + $index,
                    'is_primary' => ! $hasPrimary && $index === 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $this->deletePublicPath($storedPath);
                throw $exception;
            }
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

        DB::table('product_images')->where('id', $imageId)->delete();
        $this->deletePublicPath($image->path);

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
        $storedPath = $this->storePublicImage($file, "catalog/categories/{$categoryId}");

        try {
            DB::table('categories')->where('id', $categoryId)->update([
                'image_path' => $storedPath,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->deletePublicPath($storedPath);
            throw $exception;
        }

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
        $storedPath = $this->storePublicImage($file, "catalog/brands/{$brandId}");

        try {
            DB::table('brands')->where('id', $brandId)->update([
                'image_path' => $storedPath,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $this->deletePublicPath($storedPath);
            throw $exception;
        }

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

    private function storePublicImage(UploadedFile $file, string $directory): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = Storage::disk('public')->putFileAs(
            trim($directory, '/'),
            $file,
            $filename,
            ['visibility' => 'public'],
        );

        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages([
                'image' => [$this->msg(
                    'تعذر حفظ الصورة في التخزين العام. راجع صلاحيات storage والرابط public/storage.',
                    'The image could not be persisted to public storage. Check storage permissions and the public/storage link.',
                )],
            ]);
        }

        return 'storage/'.ltrim($path, '/');
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

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
