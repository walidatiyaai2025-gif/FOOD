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
                'status' => ($result['errors'] ?? []) === [] ? 'validated' : 'validation_failed',
                'counts_json' => json_encode($result['counts'] ?? [], JSON_THROW_ON_ERROR),
                'errors_json' => json_encode($result['errors'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'warnings_json' => json_encode($result['warnings'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
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

        return $this->imports->manifest($token);
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
            DB::table('catalog_import_runs')->where('token', $token)->update([
                'status' => 'committed',
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'committed_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        } catch (Throwable $exception) {
            DB::table('catalog_import_runs')->where('token', $token)->update([
                'status' => 'failed',
                'errors_json' => json_encode([$exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }
}
