<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CatalogImportRunService
{
    public function __construct(
        private readonly CatalogZipImportService $imports,
    ) {}

    public function sampleZip(string $unitCode = 'PCS'): string
    {
        return $this->imports->sampleZip($unitCode);
    }

    /** @return array<string,mixed> */
    public function preview(UploadedFile $archive, int $storeId, int $catalogId, string $channel): array
    {
        $result = $this->imports->preview($archive, $storeId, $catalogId, $channel);
        $result['errors'] = $this->localizedMessages($result['errors']);
        $result['warnings'] = $this->localizedMessages($result['warnings']);
        $token = is_string($result['token'] ?? null) && $result['token'] !== ''
            ? (string) $result['token']
            : (string) Str::uuid();
        $path = $archive->getRealPath();
        $hash = is_string($path) && is_file($path)
            ? hash_file('sha256', $path)
            : false;

        DB::table('catalog_import_runs')->updateOrInsert(
            ['token' => $token],
            [
                'store_id' => $storeId,
                'catalog_id' => $catalogId,
                'channel' => strtolower($channel),
                'package_hash' => is_string($hash) ? $hash : hash('sha256', $token),
                'status' => $result['errors'] === [] ? 'validated' : 'validation_failed',
                'counts_json' => json_encode($result['counts'], JSON_THROW_ON_ERROR),
                'errors_json' => json_encode($result['errors'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'warnings_json' => json_encode($result['warnings'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'result_json' => null,
                'committed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $result;
    }

    /** @return array<string,mixed> */
    public function manifest(string $token): array
    {
        $run = DB::table('catalog_import_runs')->where('token', $token)->first();
        if ($run !== null && $run->status === 'committed') {
            return [
                'version' => 1,
                'token' => $token,
                'store_id' => (int) $run->store_id,
                'catalog_id' => (int) $run->catalog_id,
                'channel' => (string) $run->channel,
                'created_at' => (string) $run->created_at,
                'expires_at' => null,
                'committed' => true,
            ];
        }

        try {
            return $this->imports->manifest($token);
        } catch (ValidationException $exception) {
            if (app()->getLocale() !== 'ar') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'preview_token' => [__('catalog_completion.import_errors.preview_invalid_or_expired')],
            ]);
        }
    }

    /** @return array{counts:array<string,int>,media_errors:list<string>} */
    public function commit(string $token): array
    {
        $run = DB::table('catalog_import_runs')->where('token', $token)->first();
        if ($run !== null && $run->status === 'committed' && $run->result_json !== null) {
            $stored = json_decode((string) $run->result_json, true);
            if (is_array($stored) && isset($stored['counts'], $stored['media_errors'])) {
                return $stored;
            }
        }

        if ($run === null) {
            throw ValidationException::withMessages([
                'preview_token' => [__('catalog_completion.validation.import_preview_missing')],
            ]);
        }

        $claimed = DB::table('catalog_import_runs')
            ->where('token', $token)
            ->whereIn('status', ['validated', 'failed'])
            ->update(['status' => 'committing', 'updated_at' => now()]);

        if ($claimed !== 1) {
            throw ValidationException::withMessages([
                'preview_token' => [__('catalog_completion.validation.import_already_processing')],
            ]);
        }

        try {
            $result = $this->imports->commit($token);
            $result['media_errors'] = $this->localizedMessages($result['media_errors']);
            DB::table('catalog_import_runs')->where('token', $token)->update([
                'status' => 'committed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'committed_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        } catch (Throwable $exception) {
            $storedErrors = $this->localizedMessages([$exception->getMessage()]);
            DB::table('catalog_import_runs')->where('token', $token)->update([
                'status' => 'failed',
                'errors_json' => json_encode($storedErrors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            if (app()->getLocale() === 'ar') {
                throw ValidationException::withMessages([
                    'preview_token' => [__('catalog_completion.import_errors.commit_failed')],
                ]);
            }

            throw $exception;
        }
    }

    /** @param list<string> $messages @return list<string> */
    private function localizedMessages(array $messages): array
    {
        if (app()->getLocale() !== 'ar') {
            return $messages;
        }

        return array_map(fn (string $message): string => $this->localizeMessage($message), $messages);
    }

    private function localizeMessage(string $message): string
    {
        $patterns = [
            '/^Unexpected file in ZIP: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.unexpected_file', ['path' => $m[1]]),
            '/^catalog\.xlsx is required at the ZIP root\.$/' => fn () => __('catalog_completion.import_errors.workbook_required'),
            '/^catalog\.xlsx may not exceed 5 MB uncompressed\.$/' => fn () => __('catalog_completion.import_errors.workbook_size'),
            '/^(Categories|Brands) row (\d+): code is required(?: and may contain only letters, numbers, dot, underscore, and hyphen)? and invalid\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_code_invalid', ['sheet' => $m[1], 'row' => $m[2]]),
            '/^Categories row (\d+): code is required and may contain only letters, numbers, dot, underscore, and hyphen\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_code_invalid', ['sheet' => 'Categories', 'row' => $m[1]]),
            '/^(Categories|Brands) row (\d+): duplicate code (.+)\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_duplicate_code', ['sheet' => $m[1], 'row' => $m[2], 'code' => $m[3]]),
            '/^Categories row (\d+): name is required\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_name_required', ['sheet' => 'Categories', 'row' => $m[1]]),
            '/^Categories row (\d+): parent_code is invalid\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_parent_invalid', ['sheet' => 'Categories', 'row' => $m[1]]),
            '/^(Categories|Brands) row (\d+): image_file must be a JPG\/JPEG\/PNG\/WEBP filename without folders\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_image_invalid', ['sheet' => $m[1], 'row' => $m[2]]),
            '/^Category (.+): parent_code (.+) does not exist in this package or catalog\.$/' => fn (array $m) => __('catalog_completion.import_errors.parent_missing', ['code' => $m[1], 'parent' => $m[2]]),
            '/^Brands row (\d+): code is required and invalid\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_code_invalid', ['sheet' => 'Brands', 'row' => $m[1]]),
            '/^Brands row (\d+): at least one of name_ar or name_en is required\.$/' => fn (array $m) => __('catalog_completion.import_errors.brand_name_required', ['row' => $m[1]]),
            '/^Products row (\d+): sku is required and invalid\.$/' => fn (array $m) => __('catalog_completion.import_errors.sku_invalid', ['row' => $m[1]]),
            '/^Products row (\d+): duplicate sku (.+)\.$/' => fn (array $m) => __('catalog_completion.import_errors.duplicate_sku', ['row' => $m[1], 'sku' => $m[2]]),
            '/^Products row (\d+): name is required\.$/' => fn (array $m) => __('catalog_completion.import_errors.row_name_required', ['sheet' => 'Products', 'row' => $m[1]]),
            '/^Products row (\d+): category_code (.+) is not available in the selected catalog\.$/' => fn (array $m) => __('catalog_completion.import_errors.category_unavailable', ['row' => $m[1], 'code' => $m[2]]),
            '/^Products row (\d+): brand_code (.+) is not available for the selected store\/channel\.$/' => fn (array $m) => __('catalog_completion.import_errors.brand_unavailable', ['row' => $m[1], 'code' => $m[2]]),
            '/^Products row (\d+): unit_code (.+) is missing, inactive, or outside the selected store\/channel\.$/' => fn (array $m) => __('catalog_completion.import_errors.unit_unavailable', ['row' => $m[1], 'code' => $m[2]]),
            '/^Products row (\d+): price must be zero or a positive number\.$/' => fn (array $m) => __('catalog_completion.import_errors.price_invalid', ['row' => $m[1]]),
            '/^Products row (\d+): image_files may contain at most 8 images\.$/' => fn (array $m) => __('catalog_completion.import_errors.too_many_images', ['row' => $m[1]]),
            '/^Products row (\d+): invalid image filename (.+)\.$/' => fn (array $m) => __('catalog_completion.import_errors.invalid_image_filename', ['row' => $m[1], 'file' => $m[2]]),
            '/^Sheet (.+) is missing or empty\.$/' => fn (array $m) => __('catalog_completion.import_errors.sheet_missing', ['sheet' => $m[1]]),
            '/^Sheet (.+) is missing required column (.+)\.$/' => fn (array $m) => __('catalog_completion.import_errors.required_column', ['sheet' => $m[1], 'column' => $m[2]]),
            '/^Category hierarchy contains a cycle involving (.+)\.$/' => fn (array $m) => __('catalog_completion.import_errors.category_cycle', ['code' => $m[1]]),
            '/^(.+): is_active must be .+$/' => fn (array $m) => __('catalog_completion.import_errors.active_invalid', ['context' => $m[1]]),
            '/^(.+): referenced image is missing: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.referenced_image_missing', ['context' => $m[1], 'path' => $m[2]]),
            '/^(.+): image exceeds 5 MB: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.image_too_large', ['context' => $m[1], 'path' => $m[2]]),
            '/^(.+): image could not be read: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.image_unreadable', ['context' => $m[1], 'path' => $m[2]]),
            '/^(.+): image content\/type does not match an allowed JPG\/JPEG\/PNG\/WEBP file: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.image_type_invalid', ['context' => $m[1], 'path' => $m[2]]),
            '/^Image is present but not referenced by catalog\.xlsx: (.+)$/' => fn (array $m) => __('catalog_completion.import_errors.unused_image', ['path' => $m[1]]),
            '/^(Category|Brand|Product) (.+): .+$/' => fn (array $m) => __('catalog_completion.import_errors.media_error', ['entity' => $m[1] === 'Product' ? __('catalog_completion.tabs.products') : ($m[1] === 'Brand' ? __('catalog_completion.product_360.brand') : __('catalog_completion.product_360.category')), 'reference' => $m[2]]),
        ];

        foreach ($patterns as $pattern => $formatter) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return $formatter($matches);
            }
        }

        return __('catalog_completion.import_errors.commit_failed');
    }
}
