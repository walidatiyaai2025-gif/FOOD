<?php

namespace App\Domain\Updater;

interface UpdateRuntime
{
    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void;

    public function backupFiles(string $packagePath): string;

    public function backupDatabase(bool $required): ?string;

    public function enterMaintenanceMode(): void;

    public function extractRelease(string $packagePath): void;

    public function runMigrations(): void;

    public function rebuildCaches(): void;

    public function healthCheck(): void;

    public function rollbackFiles(string $backupReference): void;

    public function rollbackDatabase(string $backupReference): void;

    public function exitMaintenanceMode(): void;
}
