<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerImageService
{
    public function store(UploadedFile $file, int $storeId): string
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $directory = 'customers/'.$storeId;
        $filename = Str::uuid()->toString().'.'.$extension;
        $written = Storage::disk('public')->putFileAs($directory, $file, $filename, ['visibility' => 'public']);

        if (! is_string($written) || $written === '' || ! Storage::disk('public')->exists($written)) {
            throw ValidationException::withMessages([
                'customer_image' => [app()->getLocale() === 'ar'
                    ? 'تعذر حفظ صورة العميل. راجع صلاحيات التخزين العام.'
                    : 'The customer image could not be persisted to public storage.'],
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
        if (! str_starts_with($path, 'storage/customers/')) {
            return;
        }

        Storage::disk('public')->delete(substr($path, strlen('storage/')));
    }
}
