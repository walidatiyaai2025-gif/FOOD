<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class CustomerCatalogImageController extends Controller
{
    private const MAX_EDGE = 320;

    public function thumbnail(int $image): Response
    {
        $model = ProductImage::query()->findOrFail($image);
        $relative = $this->storagePath((string) $model->path);

        if ($relative === null || ! Storage::disk('public')->exists($relative)) {
            return $this->placeholder();
        }

        $source = Storage::disk('public')->path($relative);
        $modified = @filemtime($source) ?: 0;
        $cachedRelative = sprintf(
            'customer-thumbnails/%d-%d-%d.webp',
            (int) $model->getKey(),
            $modified,
            self::MAX_EDGE,
        );

        if (! Storage::disk('public')->exists($cachedRelative)) {
            if (! $this->generateWebp($source, $cachedRelative)) {
                return $this->placeholder();
            }
        }

        $path = Storage::disk('public')->path($cachedRelative);
        $etag = '"'.hash_file('sha256', $path).'"';

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storagePath(string $path): ?string
    {
        $value = ltrim(trim($path), '/');
        if (! str_starts_with($value, 'storage/')) {
            return null;
        }

        $relative = substr($value, strlen('storage/'));
        if (
            $relative === ''
            || str_contains($relative, '..')
            || str_contains($relative, "\0")
            || str_contains($relative, '\\')
        ) {
            return null;
        }

        return $relative;
    }

    private function generateWebp(string $source, string $targetRelative): bool
    {
        if (
            ! function_exists('imagecreatefromstring')
            || ! function_exists('imagecreatetruecolor')
            || ! function_exists('imagecopyresampled')
            || ! function_exists('imagewebp')
        ) {
            return false;
        }

        $raw = @file_get_contents($source);
        if (! is_string($raw) || $raw === '' || strlen($raw) > 20 * 1024 * 1024) {
            return false;
        }

        $sourceImage = @imagecreatefromstring($raw);
        if ($sourceImage === false) {
            return false;
        }

        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);

        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
        imagefilledrectangle(
            $targetImage,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $transparent,
        );
        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        $temporary = tempnam(sys_get_temp_dir(), 'foodex-thumb-');
        $written = imagewebp($targetImage, $temporary, 72);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (! $written) {
            @unlink($temporary);

            return false;
        }

        $bytes = @file_get_contents($temporary);
        @unlink($temporary);
        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        Storage::disk('public')->put($targetRelative, $bytes);

        return true;
    }

    private function placeholder(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="12" fill="#f2f4f7"/><path d="M28 62l13-15 10 11 8-9 10 13H28z" fill="#c8ced8"/><circle cx="61" cy="34" r="6" fill="#c8ced8"/></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-FOODEX-Thumbnail-Fallback' => '1',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
