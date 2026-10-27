<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SystemAdministrationCompletionTest extends TestCase
{
    public function test_system_administration_uses_shared_dashboard_contract_and_canonical_drilldowns(): void
    {
        $view = file_get_contents(resource_path('views/admin/enterprise-system-health.blade.php'));

        $this->assertIsString($view);
        foreach ([
            'foodex-module-overview',
            'foodex-module-kpis',
            'foodex-module-chart',
            'admin._module-dashboard-kpi',
            'admin._module-dashboard-filters',
            'admin.inspector.index',
            'admin.system-update.index',
            'admin.production-dataset-switch.index',
            'backup-history',
            'failed-jobs',
            'module-health',
        ] as $required) {
            $this->assertStringContainsString($required, $view);
        }

        $this->assertStringContainsString('@media(max-width:767px)', $view);
        $this->assertStringNotContainsString('file_backup_reference', $view);
        $this->assertStringNotContainsString('database_backup_reference', $view);
    }

    public function test_system_administration_localization_has_ar_en_parity(): void
    {
        $en = require lang_path('en/system_admin.php');
        $ar = require lang_path('ar/system_admin.php');

        $flatten = static function (array $values, string $prefix = '') use (&$flatten): array {
            $keys = [];
            foreach ($values as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    array_push($keys, ...$flatten($value, $path));
                } else {
                    $keys[] = $path;
                }
            }
            sort($keys);

            return $keys;
        };

        $this->assertSame($flatten($en), $flatten($ar));
    }

    public function test_update_and_restore_workflows_have_fail_closed_recovery_evidence(): void
    {
        $manager = file_get_contents(app_path('Domain/Updater/UpdateManager.php'));
        $snapshot = file_get_contents(app_path('Services/ProductionDatasetSnapshot.php'));

        $this->assertStringContainsString('verifyDatabaseBackup($databaseBackup)', $manager);
        $this->assertStringContainsString("'rollback_health_check'", $manager);
        $this->assertStringContainsString("'maintenance_mode_remained'", $manager);
        $this->assertStringContainsString('assertRestoreCompatibility($metadata)', $snapshot);
        $this->assertStringContainsString('migrationFingerprint()', $snapshot);
        $this->assertStringContainsString('$this->runtime->healthCheck()', $snapshot);
    }

    public function test_dashboard_updater_reconciles_builtin_roles_after_rebuilding_new_config_cache(): void
    {
        $runtime = file_get_contents(app_path('Domain/Updater/LaravelUpdateRuntime.php'));

        $this->assertIsString($runtime);
        $this->assertStringContainsString('config:cache', $runtime);
        $this->assertStringContainsString('$this->reconcileBuiltInJobRoles();', $runtime);
        $this->assertStringContainsString('Database\\\\Seeders\\\\BuiltInJobRoleSeeder', $runtime);
    }

    public function test_failed_job_runtime_store_and_exports_are_redacted_by_contract(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_17_121000_create_failed_jobs_runtime_store.php'));
        $inspector = file_get_contents(app_path('Http/Controllers/Admin/SystemInspectorController.php'));
        $updateView = file_get_contents(resource_path('views/admin/system-update.blade.php'));

        $this->assertStringContainsString("Schema::create('failed_jobs'", $migration);
        $this->assertStringContainsString("'uuid'", $migration);
        $this->assertStringContainsString("'payload'", $migration);
        $this->assertStringContainsString("'exception'", $migration);
        $this->assertStringContainsString('safeFailedJobs()', $inspector);
        $this->assertStringContainsString('safeUpdateEvidence', $inspector);
        $this->assertStringNotContainsString('file_backup_reference }}', $updateView);
        $this->assertStringNotContainsString('database_backup_reference }}', $updateView);
    }
}
