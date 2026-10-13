<?php

namespace App\Services;

use App\Domain\Updater\UpdateRuntime;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ProductionSnapshotService
{
    public const STATUS_NONE = 'no_snapshot';
    public const STATUS_READY = 'snapshot_ready';
    public const STATUS_LAUNCH_ACTIVE = 'launch_active';
    public const STATUS_RESTORING = 'restoring';
    public const STATUS_RESTORED = 'legacy_restored';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly UpdateRuntime $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $metadata = $this->readMetadata();

        return [
            'status' => $metadata['status'] ?? self::STATUS_NONE,
            'snapshot_id' => $metadata['snapshot_id'] ?? null,
            'database' => $metadata['database'] ?? null,
            'release' => $metadata['release'] ?? null,
            'size' => $metadata['size'] ?? null,
            'checksum' => $metadata['checksum'] ?? null,
            'created_at' => $metadata['created_at'] ?? null,
            'verified_at' => $metadata['verified_at'] ?? null,
            'verified' => ($metadata['verified'] ?? false) === true,
        ];
    }

    /** @return array<string,mixed> */
    public function createVerified(User $actor, Request $request, array $release = []): array
    {
        $this->authorize($actor);

        return Cache::lock('foodex:production-snapshot', 1800)->block(1, function () use ($actor, $request, $release): array {
            $current = $this->readMetadata();
            if (($current['verified'] ?? false) === true
                && in_array($current['status'] ?? null, [self::STATUS_READY, self::STATUS_LAUNCH_ACTIVE], true)
                && ($current['database'] ?? null) === DB::connection()->getDatabaseName()) {
                $this->verify($current);
                return $current;
            }

            $reference = $this->runtime->backupDatabase(true);
            if (! is_string($reference) || $reference === '') {
                throw new RuntimeException('Production snapshot backup did not return a reference.');
            }

            $snapshot = $this->snapshotFile($reference);
            $size = filesize($snapshot);
            $checksum = hash_file('sha256', $snapshot);
            if ($size === false || $size < 1 || ! is_string($checksum)) {
                throw new RuntimeException('Production snapshot could not be measured.');
            }

            $metadata = [
                'snapshot_id' => (string) Str::uuid(),
                'database' => DB::connection()->getDatabaseName(),
                'connection' => (string) config('database.default'),
                'release' => $release,
                'backup_reference' => $reference,
                'snapshot_file' => $snapshot,
                'size' => $size,
                'checksum' => $checksum,
                'created_at' => now()->toIso8601String(),
                'verified_at' => now()->toIso8601String(),
                'verified' => true,
                'status' => self::STATUS_READY,
                'restore_count' => 0,
            ];
            $this->verify($metadata);
            $this->writeMetadata($metadata);
            $this->writeGateEvidence($metadata);

            $this->audit->record('foodex.production_snapshot.created', $actor, null, null, [
                'snapshot_id' => $metadata['snapshot_id'],
                'database' => $metadata['database'],
                'size' => $metadata['size'],
                'checksum' => $metadata['checksum'],
                'verified' => true,
            ], $request);

            return $metadata;
        });
    }

    /** @return array<string,mixed> */
    public function markLaunchActive(): array
    {
        $metadata = $this->readMetadata();
        $this->verify($metadata);
        $metadata['status'] = self::STATUS_LAUNCH_ACTIVE;
        $this->writeMetadata($metadata);

        return $metadata;
    }

    /** @return array<string,mixed> */
    public function restore(User $actor, Request $request): array
    {
        $this->authorize($actor);

        return Cache::lock('foodex:production-snapshot', 1800)->block(1, function () use ($actor, $request): array {
            $metadata = $this->readMetadata();
            if (($metadata['status'] ?? null) === self::STATUS_RESTORED) {
                $this->verify($metadata);
                return $metadata;
            }
            $this->verify($metadata);

            $metadata['status'] = self::STATUS_RESTORING;
            $this->writeMetadata($metadata);
            $enteredMaintenance = false;

            try {
                if (! app()->isDownForMaintenance()) {
                    $this->runtime->enterMaintenanceMode();
                    $enteredMaintenance = true;
                }
                $this->runtime->rollbackDatabase((string) $metadata['backup_reference']);
                DB::purge();
                DB::connection()->getPdo();

                $metadata['status'] = self::STATUS_RESTORED;
                $metadata['restored_at'] = now()->toIso8601String();
                $metadata['restore_count'] = ((int) ($metadata['restore_count'] ?? 0)) + 1;
                $this->writeMetadata($metadata);

                $this->audit->record('foodex.production_snapshot.restored', $actor, null, null, [
                    'snapshot_id' => $metadata['snapshot_id'],
                    'database' => $metadata['database'],
                    'checksum' => $metadata['checksum'],
                    'restore_count' => $metadata['restore_count'],
                ], $request);

                return $metadata;
            } catch (\Throwable $exception) {
                $metadata['status'] = self::STATUS_FAILED;
                $metadata['failed_at'] = now()->toIso8601String();
                $this->writeMetadata($metadata);
                throw $exception;
            } finally {
                if ($enteredMaintenance) {
                    $this->runtime->exitMaintenanceMode();
                }
            }
        });
    }

    /** @param array<string,mixed> $metadata */
    public function verify(array $metadata): void
    {
        if (($metadata['verified'] ?? false) !== true
            || ($metadata['database'] ?? null) !== DB::connection()->getDatabaseName()
            || ! is_string($metadata['backup_reference'] ?? null)
            || ! is_string($metadata['snapshot_file'] ?? null)
            || ! is_file($metadata['snapshot_file'])
            || ! is_readable($metadata['snapshot_file'])
            || filesize($metadata['snapshot_file']) !== ($metadata['size'] ?? null)
            || ! is_string($metadata['checksum'] ?? null)
            || ! hash_equals($metadata['checksum'], (string) hash_file('sha256', $metadata['snapshot_file']))) {
            throw ValidationException::withMessages(['snapshot' => 'Verified pre-launch snapshot is missing, stale or corrupt.']);
        }

        $rollbackMetadata = $metadata['backup_reference'].'/database.json';
        $decoded = is_file($rollbackMetadata)
            ? json_decode((string) file_get_contents($rollbackMetadata), true)
            : null;
        if (! is_array($decoded)
            || ! in_array($decoded['driver'] ?? null, ['sqlite', 'mysql', 'pgsql'], true)
            || ($decoded['snapshot'] ?? null) !== $metadata['snapshot_file']) {
            throw ValidationException::withMessages(['snapshot' => 'Snapshot rollback metadata is invalid.']);
        }
    }

    /** @return array<string,mixed> */
    private function readMetadata(): array
    {
        $path = (string) config('foodex_snapshot.metadata_path');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $metadata */
    private function writeMetadata(array $metadata): void
    {
        $path = (string) config('foodex_snapshot.metadata_path');
        File::ensureDirectoryExists(dirname($path), 0750, true);
        if (File::put($path, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, true) === false) {
            throw new RuntimeException('Production snapshot metadata could not be persisted.');
        }
        File::chmod($path, 0600);
    }

    /** @param array<string,mixed> $metadata */
    private function writeGateEvidence(array $metadata): void
    {
        $path = storage_path('app/private/launch-gate/backup-rollback.json');
        File::ensureDirectoryExists(dirname($path), 0750, true);
        $source = $metadata['release']['source_commit'] ?? null;
        File::put($path, json_encode([
            'passed' => true,
            'source_commit' => $source,
            'snapshot_id' => $metadata['snapshot_id'],
            'database' => $metadata['database'],
            'size' => $metadata['size'],
            'checksum' => $metadata['checksum'],
            'verified_at' => $metadata['verified_at'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, true);
        File::chmod($path, 0600);
    }

    private function snapshotFile(string $reference): string
    {
        $path = $reference.'/database.json';
        $metadata = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
        if (! is_string($snapshot) || ! is_file($snapshot) || ! is_readable($snapshot)) {
            throw new RuntimeException('Database snapshot artifact is missing or unreadable.');
        }

        return $snapshot;
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);
    }
}
