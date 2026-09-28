<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreLogoService
{
    public function store(UploadedFile $file, int $storeId): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $directory = 'stores/'.$storeId.'/branding';
        $filename = 'logo-'.Str::uuid()->toString().'.'.$extension;
        $written = Storage::disk('public')->putFileAs($directory, $file, $filename, ['visibility' => 'public']);

        if (! is_string($written) || $written === '' || ! Storage::disk('public')->exists($written)) {
            throw ValidationException::withMessages([
                'logo' => [app()->getLocale() === 'ar'
                    ? 'تعذر حفظ شعار المتجر. راجع صلاحيات التخزين العام.'
                    : 'The store logo could not be saved to public storage.'],
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
        if (! str_starts_with($path, 'storage/stores/')) {
            return;
        }

        Storage::disk('public')->delete(substr($path, strlen('storage/')));
    }
}
