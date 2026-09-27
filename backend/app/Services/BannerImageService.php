<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BannerImageService
{
    public function store(UploadedFile $file, int $storeId): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $relative = 'banners/'.$storeId.'/'.Str::uuid()->toString().'.'.$extension;
        $written = Storage::disk('public')->putFileAs(
            'banners/'.$storeId,
            $file,
            basename($relative),
            ['visibility' => 'public'],
        );

        if (! is_string($written) || $written === '' || ! Storage::disk('public')->exists($written)) {
            throw ValidationException::withMessages([
                'banner_image' => [app()->getLocale() === 'ar'
                    ? 'تعذر حفظ صورة البانر. راجع صلاحيات التخزين العام.'
                    : 'The banner image could not be persisted to public storage.'],
            ]);
        }

        return 'storage/'.ltrim($written, '/');
    }

    public function delete(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $path = ltrim(trim($path), '/');
        if (! str_starts_with($path, 'storage/banners/')) {
            return;
        }

        Storage::disk('public')->delete(substr($path, strlen('storage/')));
    }
}
