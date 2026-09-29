<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MarketingImageService
{
    public function store(UploadedFile $file, string $folder, ?int $storeId = null): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $scope = $storeId === null ? 'platform' : 'store-'.$storeId;
        $directory = 'marketing/'.trim($folder, '/').'/'.$scope;
        $filename = Str::uuid()->toString().'.'.$extension;

        $written = Storage::disk('public')->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'public'],
        );

        if (! is_string($written) || $written === '' || ! Storage::disk('public')->exists($written)) {
            throw ValidationException::withMessages([
                'image' => [app()->getLocale() === 'ar'
                    ? 'تعذر حفظ الصورة. راجع صلاحيات التخزين العام.'
                    : 'The image could not be persisted to public storage.'],
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
        if (! str_starts_with($path, 'storage/marketing/')) {
            return;
        }

        Storage::disk('public')->delete(substr($path, strlen('storage/')));
    }
}
