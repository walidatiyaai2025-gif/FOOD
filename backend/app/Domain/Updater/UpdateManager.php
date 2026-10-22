<?php

namespace App\Domain\Updater;

use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use App\Services\AuditLogger;
use RuntimeException;
use Throwable;

final class UpdateManager
{
    public function __construct(
        private readonly UpdatePackageValidator $validator,
        private readonly UpdateRuntime $runtime,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function execute(
        UpdatePackageManifest $manifest,
        string $currentVersion,
        string $packagePath,
        ?User $actor = null,
    ): UpdateHistory {
        $history = UpdateHistory::query()->create([
            'actor_id' => $actor?->getKey(),
            'from_version' => $currentVersion,
            'to_version' => $manifest->targetVersion,
            'status' => 'running',
            'environment' => (string) app()->environment(),
            'operation_reason' => 'system_update',
            'backup_status' => 'pending',
            'rollback_status' => 'not_required',
            'maintenance_mode_remained' => false,
            'package_hash' => strtolower($manifest->sha256),
            'release_notes' => $manifest->releaseNotes,
            'started_at' => now(),
            'execution_evidence' => [
                'package_integrity' => 'pending',
                'preflight' => 'pending',
                'backup_files' => 'pending',
                'backup_database' => $manifest->containsMigrations ? 'pending' : 'not_required',
                'migrations' => $manifest->containsMigrations ? 'pending' : 'not_required',
                'health_check' => 'pending',
                'rollback_files' => 'not_required',
                'rollback_database' => 'not_required',
                'rollback_health_check' => 'not_required',
            ],
        ]);

        $stage = 'validate_package';
        $fileBackup = null;
        $databaseBackup = null;
        $maintenanceMode = false;

        try {
            $this->validator->validate($manifest, $currentVersion, $packagePath);
            $this->checkpoint($history, ['package_integrity' => 'passed']);

            $stage = 'preflight';
            $this->runtime->preflight($manifest, $packagePath);
            $this->checkpoint($history, ['preflight' => 'passed']);

            $stage = 'backup_files';
            $fileBackup = $this->runtime->backupFiles($packagePath);
            $this->checkpoint($history, [
                'backup_files' => 'passed',
                'file_backup' => $this->backupDescriptor($fileBackup),
            ], [
                'file_backup_reference' => $fileBackup,
                'backup_status' => 'partial',
            ]);

            $stage = 'backup_database';
            $databaseBackup = $this->runtime->backupDatabase($manifest->containsMigrations);
            if ($databaseBackup !== null) {
                $stage = 'verify_database_backup';
                $this->runtime->verifyDatabaseBackup($databaseBackup);
            }
            $this->checkpoint($history, [
                'backup_database' => $databaseBackup !== null ? 'passed' : 'not_required',
                'backup_verification' => $databaseBackup !== null ? 'passed' : 'not_required',
                'database_backup' => $this->backupDescriptor($databaseBackup),
            ], [
                'database_backup_reference' => $databaseBackup,
                'backup_status' => 'available',
            ]);

            $stage = 'maintenance_mode';
            $this->runtime->enterMaintenanceMode();
            $maintenanceMode = true;
            $this->checkpoint($history, ['maintenance_mode' => 'entered']);

            $stage = 'extract_release';
            $this->runtime->extractRelease($packagePath);
            $this->checkpoint($history, ['release_activation' => 'passed']);

            if ($manifest->containsMigrations) {
                $stage = 'run_migrations';
                $this->runtime->runMigrations();
                $this->checkpoint($history, ['migrations' => 'passed']);
            }

            $stage = 'clear_and_rebuild_cache';
            $this->runtime->rebuildCaches();
            $this->checkpoint($history, ['cache_rebuild' => 'passed']);

            $stage = 'health_check';
            $this->runtime->healthCheck();
            $this->checkpoint($history, ['health_check' => 'passed'], [
                'health_verified_at' => now(),
            ]);

            $stage = 'exit_maintenance_mode';
            $this->runtime->exitMaintenanceMode();
            $maintenanceMode = false;
            $this->checkpoint($history, ['maintenance_mode' => 'exited']);

            SystemVersion::query()->updateOrCreate(
                ['version' => $manifest->targetVersion],
                ['installed_at' => now(), 'package_hash' => strtolower($manifest->sha256)],
            );

            $this->checkpoint($history, ['result' => 'success'], [
                'status' => 'success',
                'failure_reason' => null,
                'failed_stage' => null,
                'rollback_status' => 'not_required',
                'maintenance_mode_remained' => false,
                'finished_at' => now(),
            ]);

            $this->auditLogger->record(
                'updater.completed',
                $actor,
                $history,
                ['version' => $currentVersion],
                [
                    'version' => $manifest->targetVersion,
                    'backup_status' => $history->backup_status,
                    'health_verified' => $history->health_verified_at !== null,
                ],
            );

            return $history->refresh();
        } catch (Throwable $exception) {
            $exceptionSummary = $this->exceptionSummary($exception);
            $reason = "Update failed during [{$stage}]. Cause: {$exceptionSummary}. Rollback was attempted.";
            $rollbackSucceeded = true;
            $rollbackAttempted = false;
            $rollbackEvidence = [];

            if ($fileBackup !== null) {
                $rollbackAttempted = true;
                try {
                    $this->runtime->rollbackFiles($fileBackup);
                    $rollbackEvidence['rollback_files'] = 'passed';
                } catch (Throwable) {
                    $rollbackSucceeded = false;
                    $rollbackEvidence['rollback_files'] = 'failed';
                }
            }

            if ($databaseBackup !== null) {
                $rollbackAttempted = true;
                try {
                    $this->runtime->rollbackDatabase($databaseBackup);
                    $rollbackEvidence['rollback_database'] = 'passed';
                } catch (Throwable) {
                    $rollbackSucceeded = false;
                    $rollbackEvidence['rollback_database'] = 'failed';
                }
            }

            if ($rollbackAttempted && $rollbackSucceeded) {
                try {
                    $this->runtime->healthCheck();
                    $rollbackEvidence['rollback_health_check'] = 'passed';
                } catch (Throwable) {
                    $rollbackSucceeded = false;
                    $rollbackEvidence['rollback_health_check'] = 'failed';
                }
            }

            if ($maintenanceMode && $rollbackSucceeded) {
                try {
                    $this->runtime->exitMaintenanceMode();
                    $maintenanceMode = false;
                    $rollbackEvidence['maintenance_mode'] = 'exited_after_rollback';
                } catch (Throwable) {
                    $maintenanceMode = true;
                    $rollbackEvidence['maintenance_mode'] = 'exit_failed';
                }
            }

            if (! $rollbackSucceeded) {
                $reason .= ' Rollback is incomplete; manual recovery is required.';
            }

            if ($maintenanceMode) {
                $reason .= ' Maintenance mode remains enabled.';
            }

            $failureKey = match ($stage) {
                'validate_package' => 'package_integrity',
                'preflight' => 'preflight',
                'backup_files' => 'backup_files',
                'backup_database', 'verify_database_backup' => 'backup_database',
                'run_migrations' => 'migrations',
                'health_check' => 'health_check',
                default => 'execution',
            };

            $this->checkpoint($history, array_merge(
                [
                    $failureKey => 'failed',
                    'result' => 'failed',
                    'failure_stage' => $stage,
                    'failure_cause' => $exceptionSummary,
                    'failure_exception' => class_basename($exception),
                ],
                $rollbackEvidence,
            ), [
                'status' => 'failed',
                'backup_status' => ($fileBackup !== null || $databaseBackup !== null) ? 'available' : 'not_created',
                'failed_stage' => $stage,
                'rollback_status' => $rollbackAttempted
                    ? ($rollbackSucceeded ? 'succeeded' : 'incomplete')
                    : 'not_required',
                'maintenance_mode_remained' => $maintenanceMode,
                'failure_reason' => $reason,
                'finished_at' => now(),
            ]);

            $this->auditLogger->record(
                'updater.failed',
                $actor,
                $history,
                ['version' => $currentVersion],
                [
                    'target_version' => $manifest->targetVersion,
                    'stage' => $stage,
                    'cause' => $exceptionSummary,
                    'exception' => class_basename($exception),
                    'rollback_status' => $history->rollback_status,
                    'maintenance_mode_remained' => $maintenanceMode,
                ],
            );

            throw new RuntimeException($reason, 0, $exception);
        }
    }

    private function exceptionSummary(Throwable $exception): string
    {
        $parts = [];
        $current = $exception;
        $depth = 0;

        while ($current !== null && $depth < 3) {
            $message = trim(preg_replace('/\s+/', ' ', $current->getMessage()) ?? '');
            if ($message !== '') {
                // Do not expose absolute server paths in UI/audit-visible failure text.
                $message = preg_replace(
                    '#(?<![A-Za-z0-9._-])/(?:[^\s:()]+/)+[^\s:()]+#',
                    '[server-path]',
                    $message,
                ) ?? $message;

                // Keep operator-visible diagnostics bounded while preserving SQLSTATE,
                // migration names and framework/runtime failure messages.
                if (mb_strlen($message) > 1200) {
                    $message = mb_substr($message, 0, 1200).'…';
                }

                if (! in_array($message, $parts, true)) {
                    $parts[] = $message;
                }
            }

            $current = $current->getPrevious();
            $depth++;
        }

        return $parts !== []
            ? implode(' | Caused by: ', $parts)
            : class_basename($exception);
    }

    /**
     * Persist only non-secret evidence. Filesystem paths remain private in the
     * protected recovery reference columns and are never rendered directly.
     *
     * @return array{reference:string,size_bytes:?int,sha256:?string}|null
     */
    private function backupDescriptor(?string $reference): ?array
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $safeReference = basename(rtrim($reference, '/\\'));
        if (! is_dir($reference)) {
            return [
                'reference' => $safeReference !== '' ? $safeReference : 'runtime-backup',
                'size_bytes' => null,
                'sha256' => null,
            ];
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($reference, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $size += max(0, (int) $file->getSize());
                }
            }
        } catch (Throwable) {
            $size = 0;
        }

        $hash = null;
        $databaseMetadata = rtrim($reference, '/\\').'/database.json';
        $filesManifest = rtrim($reference, '/\\').'/files.json';

        if (is_file($databaseMetadata)) {
            try {
                $metadata = json_decode((string) file_get_contents($databaseMetadata), true, flags: JSON_THROW_ON_ERROR);
                $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
                if (is_string($snapshot) && is_file($snapshot)) {
                    $hash = hash_file('sha256', $snapshot) ?: null;
                }
            } catch (Throwable) {
                $hash = null;
            }
        } elseif (is_file($filesManifest)) {
            $hash = hash_file('sha256', $filesManifest) ?: null;
        }

        return [
            'reference' => $safeReference !== '' ? $safeReference : 'runtime-backup',
            'size_bytes' => $size > 0 ? $size : null,
            'sha256' => $hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $attributes
     */
    private function checkpoint(UpdateHistory $history, array $evidence, array $attributes = []): void
    {
        $storedEvidence = $history->getAttribute('execution_evidence');
        $current = is_array($storedEvidence) ? $storedEvidence : [];

        $history->forceFill(array_merge($attributes, [
            'execution_evidence' => array_merge($current, $evidence),
        ]))->save();
    }
}
