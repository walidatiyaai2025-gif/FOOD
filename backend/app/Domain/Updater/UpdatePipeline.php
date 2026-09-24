<?php

namespace App\Domain\Updater;

final class UpdatePipeline
{
    public const STAGES = [
        'upload',
        'validate_package',
        'verify_signature_or_hash',
        'preflight',
        'backup_files',
        'backup_database',
        'maintenance_mode',
        'extract_release',
        'run_migrations',
        'clear_and_rebuild_cache',
        'health_check',
        'success',
    ];

    public const FAILURE_STAGES = [
        'log_failure',
        'rollback_files',
        'rollback_database_when_safe',
        'restore_previous_version',
        'exit_maintenance_mode',
        'report_failure',
    ];
}
