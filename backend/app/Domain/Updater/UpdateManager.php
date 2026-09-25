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
            'from_version' => $currentVersion,
            'to_version' => $manifest->targetVersion,
            'status' => 'running',
            'package_hash' => strtolower($manifest->sha256),
            'release_notes' => $manifest->releaseNotes,
            'started_at' => now(),
        ]);

        $stage = 'validate_package';
        $fileBackup = null;
        $databaseBackup = null;
        $maintenanceMode = false;

        try {
            $this->validator->validate($manifest, $currentVersion, $packagePath);

            $stage = 'preflight';
            $this->runtime->preflight($manifest, $packagePath);

            $stage = 'backup_files';
            $fileBackup = $this->runtime->backupFiles($packagePath);

            $stage = 'backup_database';
            $databaseBackup = $this->runtime->backupDatabase($manifest->containsMigrations);

            $stage = 'maintenance_mode';
            $this->runtime->enterMaintenanceMode();
            $maintenanceMode = true;

            $stage = 'extract_release';
            $this->runtime->extractRelease($packagePath);

            if ($manifest->containsMigrations) {
                $stage = 'run_migrations';
                $this->runtime->runMigrations();
            }

            $stage = 'clear_and_rebuild_cache';
            $this->runtime->rebuildCaches();

            $stage = 'health_check';
            $this->runtime->healthCheck();

            $stage = 'exit_maintenance_mode';
            $this->runtime->exitMaintenanceMode();
            $maintenanceMode = false;

            SystemVersion::query()->updateOrCreate(
                ['version' => $manifest->targetVersion],
                ['installed_at' => now(), 'package_hash' => strtolower($manifest->sha256)],
            );

            $history->forceFill([
                'status' => 'success',
                'failure_reason' => null,
                'finished_at' => now(),
            ])->save();

            $this->auditLogger->record(
                'updater.completed',
                $actor,
                $history,
                ['version' => $currentVersion],
                ['version' => $manifest->targetVersion],
            );

            return $history->refresh();
        } catch (Throwable $exception) {
            $reason = "Update failed during [{$stage}]. Rollback was attempted.";
            $rollbackSucceeded = true;

            if ($fileBackup !== null) {
                try {
                    $this->runtime->rollbackFiles($fileBackup);
                } catch (Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            if ($databaseBackup !== null) {
                try {
                    $this->runtime->rollbackDatabase($databaseBackup);
                } catch (Throwable) {
                    $rollbackSucceeded = false;
                }
            }

            if ($maintenanceMode && $rollbackSucceeded) {
                try {
                    $this->runtime->exitMaintenanceMode();
                    $maintenanceMode = false;
                } catch (Throwable) {
                    // Keep the recovery state explicit when maintenance cannot be disabled.
                }
            }

            if (! $rollbackSucceeded) {
                $reason .= ' Rollback is incomplete; manual recovery is required.';
            }

            if ($maintenanceMode) {
                $reason .= ' Maintenance mode remains enabled.';
            }

            $history->forceFill([
                'status' => 'failed',
                'failure_reason' => $reason,
                'finished_at' => now(),
            ])->save();

            $this->auditLogger->record(
                'updater.failed',
                $actor,
                $history,
                ['version' => $currentVersion],
                ['target_version' => $manifest->targetVersion, 'stage' => $stage],
            );

            throw new RuntimeException($reason, 0, $exception);
        }
    }
}
