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
            $this->checkpoint($history, ['backup_files' => 'passed'], [
                'file_backup_reference' => $fileBackup,
                'backup_status' => 'partial',
            ]);

            $stage = 'backup_database';
            $databaseBackup = $this->runtime->backupDatabase($manifest->containsMigrations);
            $this->checkpoint($history, [
                'backup_database' => $databaseBackup !== null ? 'passed' : 'not_required',
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
            $reason = "Update failed during [{$stage}]. Rollback was attempted.";
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
                'backup_database' => 'backup_database',
                'run_migrations' => 'migrations',
                'health_check' => 'health_check',
                default => 'execution',
            };

            $this->checkpoint($history, array_merge(
                [$failureKey => 'failed', 'result' => 'failed'],
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
                    'rollback_status' => $history->rollback_status,
                    'maintenance_mode_remained' => $maintenanceMode,
                ],
            );

            throw new RuntimeException($reason, 0, $exception);
        }
    }

    /**
     * @param array<string,string> $evidence
     * @param array<string,mixed> $attributes
     */
    private function checkpoint(UpdateHistory $history, array $evidence, array $attributes = []): void
    {
        $current = is_array($history->execution_evidence) ? $history->execution_evidence : [];

        $history->forceFill(array_merge($attributes, [
            'execution_evidence' => array_merge($current, $evidence),
        ]))->save();
    }
}
