<?php

namespace App\Services;

use App\Domain\Updater\UpdateRuntime;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;
use Throwable;

final class ProductionDatasetSnapshot
{
    public const NO_SNAPSHOT = 'no_snapshot';
    public const SNAPSHOT_READY = 'snapshot_ready';
    public const LAUNCH_ACTIVE = 'launch_active';
    public const RESTORING = 'restoring';
    public const LEGACY_RESTORED = 'legacy_restored';
    public const FAILED = 'failed';

    public function __construct(
        private readonly UpdateRuntime $runtime,
        private readonly AuditLogger $audit,
    ) {}

    public function status(): array
    {
        $metadata = $this->readMetadata();

        return [
            'state' => $metadata['state'] ?? self::NO_SNAPSHOT,
            'snapshot_id' => $metadata['snapshot_id'] ?? null,
            'verified' => ($metadata['verified'] ?? false) === true,
            'created_at' => $metadata['created_at'] ?? null,
            'verified_at' => $metadata['verified_at'] ?? null,
            'launch_activated_at' => $metadata['launch_activated_at'] ?? null,
            'restore_started_at' => $metadata['restore_started_at'] ?? null,
            'restored_at' => $metadata['restored_at'] ?? null,
            'failed_at' => $metadata['failed_at'] ?? null,
        ];
    }

    public function create(User $actor, ?Request $request = null): array
    {
        return Cache::lock('foodex:production-dataset-snapshot', 1800)->block(2, function () use ($actor, $request): array {
            $existing = $this->readMetadata();
            if (($existing['verified'] ?? false) === true
                && in_array($existing['state'] ?? null, [self::SNAPSHOT_READY, self::LAUNCH_ACTIVE], true)) {
                $this->verify($existing);
                return $this->status();
            }

            $reference = $this->runtime->backupDatabase(true);
            if (! is_string($reference) || $reference === '') {
                throw new RuntimeException('Production dataset snapshot was not created.');
            }

            $snapshot = $this->snapshotFile($reference);
            $size = filesize($snapshot);
            $checksum = hash_file('sha256', $snapshot);
            if ($size === false || $size < 1 || $checksum === false) {
                throw new RuntimeException('Production dataset snapshot is unreadable.');
            }

            $metadata = [
                'state' => self::SNAPSHOT_READY,
                'snapshot_id' => bin2hex(random_bytes(16)),
                'database' => DB::connection()->getDatabaseName(),
                'connection' => (string) config('database.default'),
                'source_release' => trim((string) @file_get_contents(base_path('../VERSION'))),
                'source_sha' => (string) config('foodex.release_source_sha', env('FOODEX_RELEASE_SOURCE_SHA', 'unknown')),
                'backup_reference' => $reference,
                'snapshot_file' => $snapshot,
                'size' => $size,
                'checksum_sha256' => $checksum,
                'created_at' => now()->toIso8601String(),
                'verified' => false,
                'verified_at' => null,
            ];
            $this->writeMetadata($metadata);
            $this->runtime->verifyDatabaseBackup($reference);
            $this->verify($metadata);
            $metadata['verified'] = true;
            $metadata['verified_at'] = now()->toIso8601String();
            $this->writeMetadata($metadata);
            $this->audit->record('system.production_snapshot.created', $actor, null, null, $this->safeAudit($metadata), $request);

            return $this->status();
        });
    }

    public function assertReady(): void
    {
        $metadata = $this->readMetadata();
        if (($metadata['state'] ?? null) !== self::SNAPSHOT_READY || ($metadata['verified'] ?? false) !== true) {
            throw ValidationException::withMessages(['reset' => 'A verified pre-launch snapshot is required.']);
        }
        $this->verify($metadata);
    }

    public function markLaunchActive(User $actor, ?Request $request = null): void
    {
        $metadata = $this->readMetadata();
        $this->verify($metadata);
        if (($metadata['state'] ?? null) !== self::SNAPSHOT_READY) {
            throw new RuntimeException('Snapshot is not ready to enter launch-active state.');
        }
        $metadata['state'] = self::LAUNCH_ACTIVE;
        $metadata['launch_activated_at'] = now()->toIso8601String();
        $this->writeMetadata($metadata);
        $this->audit->record('system.production_snapshot.launch_active', $actor, null, null, $this->safeAudit($metadata), $request);
    }

    public function restore(User $actor, ?Request $request = null): array
    {
        return Cache::lock('foodex:production-dataset-snapshot', 1800)->block(2, function () use ($actor, $request): array {
            $metadata = $this->readMetadata();
            if (($metadata['state'] ?? null) === self::LEGACY_RESTORED) {
                $this->verify($metadata);
                return $this->status();
            }
            if (($metadata['state'] ?? null) !== self::LAUNCH_ACTIVE) {
                throw new RuntimeException('Legacy restore is only allowed from launch-active state.');
            }
            $this->verify($metadata);
            $metadata['state'] = self::RESTORING;
            $metadata['restore_started_at'] = now()->toIso8601String();
            $this->writeMetadata($metadata);

            $enteredMaintenance = false;
            try {
                if (! app()->isDownForMaintenance()) {
                    $this->runtime->enterMaintenanceMode();
                    $enteredMaintenance = true;
                }
                $this->runtime->rollbackDatabase((string) $metadata['backup_reference']);
                $this->verify($metadata);
                $metadata['state'] = self::LEGACY_RESTORED;
                $metadata['restored_at'] = now()->toIso8601String();
                $this->writeMetadata($metadata);
                $this->audit->record('system.production_snapshot.restored', $actor, null, null, $this->safeAudit($metadata), $request);
                if ($enteredMaintenance) {
                    $this->runtime->exitMaintenanceMode();
                }
                return $this->status();
            } catch (Throwable $exception) {
                $metadata['state'] = self::FAILED;
                $metadata['failed_at'] = now()->toIso8601String();
                $metadata['failure'] = 'restore_failed';
                $this->writeMetadata($metadata);
                $this->audit->record('system.production_snapshot.restore_failed', $actor, null, null, $this->safeAudit($metadata), $request);
                throw $exception;
            }
        });
    }

    private function verify(array $metadata): void
    {
        foreach (['backup_reference', 'snapshot_file', 'checksum_sha256', 'database'] as $key) {
            if (! is_string($metadata[$key] ?? null) || $metadata[$key] === '') {
                throw new RuntimeException('Snapshot metadata is incomplete.');
            }
        }
        if (! hash_equals((string) $metadata['database'], (string) DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Snapshot belongs to a different database.');
        }
        $file = (string) $metadata['snapshot_file'];
        $actual = is_file($file) ? hash_file('sha256', $file) : false;
        if ($actual === false || ! hash_equals((string) $metadata['checksum_sha256'], $actual)) {
            throw new RuntimeException('Snapshot verification failed.');
        }
        $rollbackMetadata = rtrim((string) $metadata['backup_reference'], '/\\').'/database.json';
        if (! is_file($rollbackMetadata)) {
            throw new RuntimeException('Snapshot restore metadata is missing.');
        }
    }

    private function snapshotFile(string $reference): string
    {
        $path = rtrim($reference, '/\\').'/database.json';
        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Snapshot restore metadata is invalid.');
        }
        $snapshot = is_array($payload) ? ($payload['snapshot'] ?? null) : null;
        if (! is_string($snapshot) || ! is_file($snapshot)) {
            throw new RuntimeException('Snapshot payload is missing.');
        }
        return $snapshot;
    }

    private function metadataPath(): string
    {
        return (string) config('foodex_reset.snapshot_metadata_path', storage_path('app/private/production-dataset-snapshot.json'));
    }

    private function readMetadata(): array
    {
        $path = $this->metadataPath();
        if (! is_file($path)) {
            return ['state' => self::NO_SNAPSHOT];
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Snapshot metadata is invalid.');
        }
        return is_array($value) ? $value : ['state' => self::NO_SNAPSHOT];
    }

    private function writeMetadata(array $metadata): void
    {
        $path = $this->metadataPath();
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Private snapshot metadata directory could not be created.');
        }
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(5));
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Snapshot metadata could not be persisted.');
        }
        @chmod($path, 0600);
    }

    private function safeAudit(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'state', 'snapshot_id', 'database', 'connection', 'source_release', 'source_sha',
            'size', 'checksum_sha256', 'created_at', 'verified', 'verified_at',
            'launch_activated_at', 'restore_started_at', 'restored_at', 'failed_at', 'failure',
        ]));
    }
}
