<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Central company identity for PDFs, reports and printable documents. */
final class DocumentBrandingService
{
    private const DEFAULTS = [
        'name' => 'FOODEX',
        'address' => '30 ش الوفاء - منطقة الاستاد - الغربية - طنطا',
        'phone_primary' => '0403332801',
        'phone_secondary' => '01202021034',
        'logo_path' => '',
    ];

    /** @return array{name:string,address:string,phone_primary:string,phone_secondary:string,logo_path:string,logo_data_uri:?string} */
    public function identity(): array
    {
        $keys = array_map(
            static fn (string $key): string => 'documents.'.$key,
            array_keys(self::DEFAULTS),
        );

        $values = Schema::hasTable('settings')
            ? DB::table('settings')
                ->whereNull('store_id')
                ->whereIn('key', $keys)
                ->orderByDesc('id')
                ->get(['key', 'value'])
                ->unique('key')
                ->pluck('value', 'key')
            : collect();

        $identity = [];
        foreach (self::DEFAULTS as $key => $default) {
            $raw = $values->get('documents.'.$key);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $value = is_string($decoded) ? $decoded : $raw;
            $identity[$key] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : $default;
        }

        $identity['logo_data_uri'] = $this->logoDataUri($identity['logo_path'])
            ?? $this->bundledLogoDataUri();

        return $identity;
    }

    private function bundledLogoDataUri(): ?string
    {
        // The existing official FOODEX economical-group logo is bundled with
        // production assets. Convert WebP only when the host supports it.
        $path = public_path('brand/foodex-economical-group.webp');
        if (! is_file($path) || filesize($path) > 1024 * 1024
            || ! function_exists('imagecreatefromwebp')
            || ! function_exists('imagepng')) {
            return null;
        }

        $image = @imagecreatefromwebp($path);
        if ($image === false) {
            return null;
        }

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return is_string($png) && $png !== ''
            ? 'data:image/png;base64,'.base64_encode($png)
            : null;
    }

    private function logoDataUri(string $path): ?string
    {
        // Only the protected Dashboard logo upload directory is trusted for PDFs.
        if (! str_starts_with($path, 'document-branding/')
            || str_contains($path, '..')
            || str_contains($path, '\\')) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path) || $disk->size($path) > 1024 * 1024) {
            return null;
        }

        $mime = $disk->mimeType($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }
}
