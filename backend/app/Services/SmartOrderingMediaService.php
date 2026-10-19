<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SmartOrderingMediaService
{
    public function publicUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        if (str_starts_with($value, '/')
            && ! str_starts_with($value, '/storage/')) {
            return $value;
        }

        $path = ltrim($value, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! $this->isSafePath($path)) {
            return null;
        }

        $segments = array_map('rawurlencode', explode('/', $path));

        return '/smart-ordering/media/'.implode('/', $segments);
    }

    public function absoluteUrl(mixed $value): ?string
    {
        $publicUrl = $this->publicUrl($value);
        if ($publicUrl === null || str_starts_with($publicUrl, 'https://') || str_starts_with($publicUrl, 'http://')) {
            return $publicUrl;
        }

        return url($publicUrl);
    }

    public function response(string $path): StreamedResponse
    {
        $path = ltrim(trim($path), '/');
        abort_unless($this->isSafePath($path), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isSafePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '\\')
            && ! str_contains($path, "\0")
            && preg_match('#(?:^|/)\.\.?(?:/|$)#', $path) !== 1;
    }
}
