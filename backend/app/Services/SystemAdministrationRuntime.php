<?php

namespace App\Services;

use App\Domain\Updater\UpdateRuntime;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class SystemAdministrationRuntime
{
    public function __construct(
        private readonly UpdateRuntime $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function healthSnapshot(): array
    {
        $databaseState = 'healthy';
        $databaseMessage = null;

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            $databaseState = 'error';
            $databaseMessage = $exception::class;
        }

        $cacheState = 'healthy';
        try {
            $key = 'foodex:runtime-health:'.bin2hex(random_bytes(5));
            Cache::put($key, 'ok', 30);
            if (Cache::get($key) !== 'ok') {
                $cacheState = 'error';
            }
            Cache::forget($key);
        } catch (Throwable) {
            $cacheState = 'error';
        }

        $failedJobs = null;
        $failedJobs24h = null;
        try {
            if (Schema::hasTable('failed_jobs')) {
                $failedJobs = (int) DB::table('failed_jobs')->count();
                $failedJobs24h = (int) DB::table('failed_jobs')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();
            } else {
                $failedJobs = 0;
                $failedJobs24h = 0;
            }
        } catch (Throwable) {
            $failedJobs = null;
            $failedJobs24h = null;
        }

        $failedJobRows = $this->failedJobs(50);

        $pendingMigrations = $this->pendingMigrationCount();
        $scheduler = $this->schedulerHealth();
        $storageWritable = is_dir(storage_path()) && is_writable(storage_path());
        $backups = $this->backups(100);
        $lastBackup = collect($backups)->first(
            static fn (array $backup): bool => $backup['kind'] === 'database',
        );

        $configurationWarnings = [];
        if (app()->environment('production') && (bool) config('app.debug')) {
            $configurationWarnings[] = 'production_debug_enabled';
        }
        if (app()->environment('production') && (string) config('queue.default') === 'sync') {
            $configurationWarnings[] = 'production_sync_queue';
        }
        if ((string) config('app.key') === '') {
            $configurationWarnings[] = 'missing_app_key';
        }

        $state = 'healthy';
        if (
            $databaseState !== 'healthy'
            || $cacheState !== 'healthy'
            || ! $storageWritable
            || $pendingMigrations > 0
            || $scheduler['state'] === 'error'
        ) {
            $state = 'error';
        } elseif (
            $failedJobs === null
            || $failedJobs > 0
            || $scheduler['state'] === 'warning'
            || $configurationWarnings !== []
        ) {
            $state = 'warning';
        }

        return [
            'state' => $state,
            'database' => [
                'state' => $databaseState,
                'message' => $databaseMessage,
                'driver' => (string) config('database.default'),
            ],
            'cache' => [
                'state' => $cacheState,
                'store' => (string) config('cache.default'),
            ],
            'queue' => [
                'connection' => (string) config('queue.default'),
                'failed_jobs' => $failedJobs,
                'failed_jobs_24h' => $failedJobs24h,
                'recent_failures' => $failedJobRows,
                'state' => $failedJobs === null ? 'unknown' : ($failedJobs > 0 ? 'warning' : 'healthy'),
            ],
            'scheduler' => $scheduler,
            'storage' => [
                'writable' => $storageWritable,
                'state' => $storageWritable ? 'healthy' : 'error',
            ],
            'pending_migrations' => $pendingMigrations,
            'configuration_warnings' => $configurationWarnings,
            'backup_count' => count($backups),
            'last_backup' => $lastBackup,
            'observed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function backups(int $limit = 50): array
    {
        $root = $this->backupRoot();
        if (! is_dir($root)) {
            return [];
        }

        $directories = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        rsort($directories, SORT_STRING);
        $rows = [];

        foreach (array_slice($directories, 0, max(1, min($limit, 200))) as $directory) {
            $reference = basename($directory);
            if (! preg_match('/^\d{8}-\d{6}-(database|files)-[a-f0-9]{10}$/', $reference)) {
                continue;
            }

            $kind = str_contains($reference, '-database-') ? 'database' : 'files';
            $metadata = $this->readJson($directory.'/foodex-backup.json');
            $databaseMetadata = $kind === 'database' ? $this->readJson($directory.'/database.json') : [];
            $manifestPath = $kind === 'database' ? $directory.'/database.json' : $directory.'/files.json';
            $createdAt = CarbonImmutable::createFromFormat('Ymd-His', substr($reference, 0, 15)) ?: null;
            $retentionDays = max(1, (int) config('foodex.update_backup_retention_days', 30));
            $snapshot = is_string($databaseMetadata['snapshot'] ?? null)
                ? basename((string) $databaseMetadata['snapshot'])
                : null;

            $rows[] = [
                'reference' => $reference,
                'kind' => $kind,
                'scope' => $kind === 'database'
                    ? (string) ($databaseMetadata['driver'] ?? 'database')
                    : 'release-files',
                'created_at' => $createdAt?->toIso8601String(),
                'size_bytes' => $this->directorySize($directory),
                'integrity_reference' => is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null,
                'snapshot' => $snapshot,
                'status' => $this->backupAvailable($directory, $kind, $databaseMetadata) ? 'available' : 'incomplete',
                'retention_days' => $retentionDays,
                'retention_until' => $createdAt?->addDays($retentionDays)->toIso8601String(),
                'origin' => (string) ($metadata['origin'] ?? ($kind === 'files' ? 'system_update' : 'system_update')),
                'source_version' => is_string($metadata['source_version'] ?? null) ? $metadata['source_version'] : null,
                'reason' => is_string($metadata['reason'] ?? null) ? $metadata['reason'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failedJobs(int $limit = 50): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        try {
            $columns = ['id', 'connection', 'queue', 'exception', 'failed_at'];
            if (Schema::hasColumn('failed_jobs', 'uuid')) {
                $columns[] = 'uuid';
            }

            return DB::table('failed_jobs')
                ->select($columns)
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->limit(max(1, min($limit, 100)))
                ->get()
                ->map(static function ($row): array {
                    $exception = is_string($row->exception ?? null) ? (string) $row->exception : '';

                    return [
                        'id' => (int) $row->id,
                        'uuid' => is_string($row->uuid ?? null) ? (string) $row->uuid : null,
                        'connection' => (string) ($row->connection ?? ''),
                        'queue' => (string) ($row->queue ?? ''),
                        'failure_fingerprint' => $exception === '' ? null : substr(hash('sha256', $exception), 0, 16),
                        'failed_at' => is_string($row->failed_at ?? null) ? (string) $row->failed_at : null,
                    ];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentOperations(int $limit = 50): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = AuditLog::query()
            ->whereIn('event', [
                'updater.completed',
                'updater.failed',
                'system.backup.created',
                'system.restore.started',
                'system.restore.completed',
                'system.restore.failed',
            ])
            ->latest('id')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return $rows->map(static function (AuditLog $row) use ($users): array {
            $actor = $row->user_id ? $users->get($row->user_id) : null;
            $before = $row->getAttribute('before');
            $after = $row->getAttribute('after');

            return [
                'id' => (int) $row->id,
                'event' => (string) $row->event,
                'actor' => $actor?->name ?: $actor?->email,
                'before' => is_array($before) ? $before : [],
                'after' => is_array($after) ? $after : [],
                'created_at' => $row->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createDatabaseBackup(
        User $actor,
        Request $request,
        string $reason,
        string $sourceVersion,
    ): array {
        $referencePath = $this->runtime->backupDatabase(true);
        if (! is_string($referencePath) || $referencePath === '') {
            throw new RuntimeException('Database backup did not produce a recovery reference.');
        }

        $reference = $this->assertBackupPath($referencePath, 'database');
        $metadataPath = $referencePath.'/foodex-backup.json';
        $metadata = [
            'origin' => 'manual_backup',
            'created_at' => now()->toIso8601String(),
            'created_by' => (int) $actor->getKey(),
            'reason' => $reason,
            'environment' => (string) app()->environment(),
            'source_version' => $sourceVersion,
        ];

        if (file_put_contents(
            $metadataPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            LOCK_EX,
        ) === false) {
            throw new RuntimeException('Backup metadata could not be recorded.');
        }

        $this->audit->record(
            'system.backup.created',
            $actor,
            null,
            null,
            [
                'reference' => $reference,
                'reason' => $reason,
                'environment' => (string) app()->environment(),
                'source_version' => $sourceVersion,
                'outcome' => 'completed',
            ],
            $request,
        );

        return $this->backupByReference($reference);
    }

    public function restoreDatabaseBackup(
        User $actor,
        Request $request,
        string $reference,
        string $reason,
        string $confirmation,
        ?string $productionConfirmation,
        string $targetVersion,
    ): void {
        $backup = $this->backupByReference($reference);
        if ($backup['kind'] !== 'database' || $backup['status'] !== 'available') {
            throw new RuntimeException('Only an available database backup can be restored.');
        }

        if (! hash_equals($reference, trim($confirmation))) {
            throw new RuntimeException('Restore confirmation does not match the selected backup reference.');
        }

        if (
            app()->environment('production')
            && trim((string) $productionConfirmation) !== 'RESTORE FOODEX PRODUCTION'
        ) {
            throw new RuntimeException('Production restore confirmation is required.');
        }

        $path = $this->backupRoot().DIRECTORY_SEPARATOR.$reference;
        $stage = 'verify_backup';
        $maintenanceMode = false;

        $this->audit->record(
            'system.restore.started',
            $actor,
            null,
            null,
            [
                'reference' => $reference,
                'reason' => $reason,
                'environment' => (string) app()->environment(),
                'source_version' => $backup['source_version'],
                'target_version' => $targetVersion,
                'outcome' => 'started',
            ],
            $request,
        );

        try {
            $this->runtime->verifyDatabaseBackup($path);

            $stage = 'maintenance_mode';
            $this->runtime->enterMaintenanceMode();
            $maintenanceMode = true;

            $stage = 'restore_database';
            $this->runtime->rollbackDatabase($path);

            $stage = 'migration_reconciliation';
            $this->runtime->runMigrations();

            $stage = 'rebuild_caches';
            $this->runtime->rebuildCaches();

            $stage = 'health_check';
            $this->runtime->healthCheck();

            $stage = 'exit_maintenance_mode';
            $this->runtime->exitMaintenanceMode();
            $maintenanceMode = false;

            $this->audit->record(
                'system.restore.completed',
                $actor,
                null,
                [
                    'reference' => $reference,
                    'target_version' => $targetVersion,
                ],
                [
                    'reference' => $reference,
                    'reason' => $reason,
                    'environment' => (string) app()->environment(),
                    'source_version' => $backup['source_version'],
                    'target_version' => $targetVersion,
                    'outcome' => 'completed',
                ],
                $request,
            );
        } catch (Throwable $exception) {
            try {
                $this->audit->record(
                    'system.restore.failed',
                    $actor,
                    null,
                    [
                        'reference' => $reference,
                        'target_version' => $targetVersion,
                    ],
                    [
                        'reference' => $reference,
                        'reason' => $reason,
                        'environment' => (string) app()->environment(),
                        'source_version' => $backup['source_version'],
                        'target_version' => $targetVersion,
                        'stage' => $stage,
                        'maintenance_mode' => $maintenanceMode,
                        'outcome' => 'failed',
                    ],
                    $request,
                );
            } catch (Throwable) {
                // Preserve the original restore failure when the restored database cannot record audit evidence.
            }

            throw new RuntimeException(
                "Restore failed during [{$stage}].".($maintenanceMode ? ' Maintenance mode may remain enabled.' : ''),
                0,
                $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function backupByReference(string $reference): array
    {
        if (! preg_match('/^\d{8}-\d{6}-(database|files)-[a-f0-9]{10}$/', $reference)) {
            throw new RuntimeException('Invalid backup reference.');
        }

        foreach ($this->backups(200) as $backup) {
            if (hash_equals((string) $backup['reference'], $reference)) {
                return $backup;
            }
        }

        throw new RuntimeException('Backup reference was not found.');
    }

    private function assertBackupPath(string $path, string $expectedKind): string
    {
        $root = realpath($this->backupRoot());
        $real = realpath($path);
        if ($root === false || $real === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Backup reference is outside the configured recovery directory.');
        }

        $reference = basename($real);
        if (! preg_match('/^\d{8}-\d{6}-'.preg_quote($expectedKind, '/').'-[a-f0-9]{10}$/', $reference)) {
            throw new RuntimeException('Backup reference has an invalid format.');
        }

        return $reference;
    }

    private function backupRoot(): string
    {
        return rtrim((string) config('foodex.update_backup_path', storage_path('app/system/update-backups')), '/\\');
    }

    private function pendingMigrationCount(): int
    {
        try {
            $files = glob(database_path('migrations/*.php')) ?: [];
            $available = array_map(
                static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
                $files,
            );

            if (! Schema::hasTable('migrations')) {
                return count($available);
            }

            $applied = DB::table('migrations')->pluck('migration')->map(
                static fn ($migration): string => (string) $migration,
            )->all();

            return count(array_diff($available, $applied));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{state:string,age_seconds:?int,last_heartbeat:?string,enabled:bool}
     */
    private function schedulerHealth(): array
    {
        $enabled = (bool) config('foodex.scheduler_heartbeat', true);
        if (! $enabled) {
            return [
                'state' => 'disabled',
                'age_seconds' => null,
                'last_heartbeat' => null,
                'enabled' => false,
            ];
        }

        $path = storage_path('app/system/scheduler-heartbeat.lock');
        if (! is_file($path)) {
            return [
                'state' => 'warning',
                'age_seconds' => null,
                'last_heartbeat' => null,
                'enabled' => true,
            ];
        }

        $last = (int) trim((string) @file_get_contents($path));
        if ($last <= 0) {
            $last = (int) @filemtime($path);
        }

        $age = $last > 0 ? max(0, time() - $last) : null;

        return [
            'state' => $age !== null && $age <= 180 ? 'healthy' : 'warning',
            'age_seconds' => $age,
            'last_heartbeat' => $last > 0 ? CarbonImmutable::createFromTimestamp($last)->toIso8601String() : null,
            'enabled' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $databaseMetadata
     */
    private function backupAvailable(string $directory, string $kind, array $databaseMetadata): bool
    {
        if ($kind === 'files') {
            return is_file($directory.'/files.json');
        }

        $snapshot = $databaseMetadata['snapshot'] ?? null;

        return is_string($snapshot) && is_file($snapshot) && (filesize($snapshot) ?: 0) > 0;
    }

    private function directorySize(string $directory): int
    {
        $total = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            }
        } catch (Throwable) {
            return 0;
        }

        return $total;
    }
}
