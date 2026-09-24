<?php

namespace App\Domain\Updater;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use ZipArchive;

final class LaravelUpdateRuntime implements UpdateRuntime
{
    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP support is required for system updates.');
        }

        $packageSize = filesize($packagePath);
        if ($packageSize === false || $packageSize < 1) {
            throw new RuntimeException('Update package is empty or unreadable.');
        }

        $releaseRoot = $this->releaseRoot();
        if (! is_dir($releaseRoot) || ! is_writable($releaseRoot)) {
            throw new RuntimeException('Release root is not writable.');
        }

        $backupRoot = $this->backupRoot();
        $freeSpace = disk_free_space($backupRoot);
        if ($freeSpace === false || $freeSpace < ($packageSize * 3)) {
            throw new RuntimeException('Insufficient free disk space for safe update and rollback.');
        }

        $this->archiveEntries($packagePath);

        if ($manifest->containsMigrations) {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlite') {
                $database = (string) config('database.connections.sqlite.database');
                if ($database === ':memory:' || ! is_file($database) || ! is_readable($database)) {
                    throw new RuntimeException('SQLite database cannot be backed up safely.');
                }
            } elseif ($driver === 'pgsql') {
                $this->runProcess([(string) config('foodex.pg_dump_binary', 'pg_dump'), '--version']);
                $this->runProcess([(string) config('foodex.pg_restore_binary', 'pg_restore'), '--version']);
            } else {
                throw new RuntimeException('Database backup is not supported for this database driver.');
            }
        }
    }

    public function backupFiles(string $packagePath): string
    {
        $backupDirectory = $this->newBackupDirectory('files');
        $filesDirectory = $backupDirectory.'/existing';
        $this->ensureDirectory($filesDirectory);

        $targets = [];
        $existing = [];

        foreach ($this->archiveEntries($packagePath) as $relativePath) {
            $targets[] = $relativePath;
            $target = $this->releaseRoot().'/'.$relativePath;

            if (is_file($target)) {
                $backupPath = $filesDirectory.'/'.$relativePath;
                $this->ensureDirectory(dirname($backupPath));

                if (! copy($target, $backupPath)) {
                    throw new RuntimeException('Existing release file could not be backed up.');
                }

                $existing[] = $relativePath;
            } elseif (file_exists($target)) {
                throw new RuntimeException('Update target conflicts with an existing non-file path.');
            }
        }

        $manifest = json_encode([
            'targets' => $targets,
            'existing' => $existing,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($backupDirectory.'/files.json', $manifest.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('File rollback manifest could not be written.');
        }

        return $backupDirectory;
    }

    public function backupDatabase(bool $required): ?string
    {
        if (! $required) {
            return null;
        }

        $driver = DB::connection()->getDriverName();
        $backupDirectory = $this->newBackupDirectory('database');

        if ($driver === 'sqlite') {
            $source = (string) config('database.connections.sqlite.database');
            $snapshot = $backupDirectory.'/database.sqlite';

            if ($source === ':memory:' || ! is_file($source) || ! copy($source, $snapshot)) {
                throw new RuntimeException('SQLite database backup failed.');
            }

            $this->writeDatabaseMetadata($backupDirectory, 'sqlite', $snapshot);

            return $backupDirectory;
        }

        if ($driver === 'pgsql') {
            $connection = $this->databaseConnectionConfig();
            $snapshot = $backupDirectory.'/database.dump';
            $this->runProcess([
                (string) config('foodex.pg_dump_binary', 'pg_dump'),
                '--format=custom',
                '--no-owner',
                '--no-privileges',
                '--file='.$snapshot,
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ], [
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            ]);

            if (! is_file($snapshot) || filesize($snapshot) === 0) {
                throw new RuntimeException('PostgreSQL database backup did not produce a valid snapshot.');
            }

            $this->writeDatabaseMetadata($backupDirectory, 'pgsql', $snapshot);

            return $backupDirectory;
        }

        throw new RuntimeException('Database backup is not supported for this database driver.');
    }

    public function enterMaintenanceMode(): void
    {
        if (Artisan::call('down') !== 0) {
            throw new RuntimeException('Maintenance mode could not be enabled.');
        }
    }

    public function extractRelease(string $packagePath): void
    {
        $archive = $this->openArchive($packagePath);

        try {
            foreach ($this->archiveEntriesFrom($archive) as $relativePath) {
                $stream = $archive->getStream($relativePath);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Update archive entry could not be read.');
                }

                $target = $this->releaseRoot().'/'.$relativePath;
                $this->ensureDirectory(dirname($target));
                $temporary = $target.'.foodex-update-'.bin2hex(random_bytes(6));
                $destination = fopen($temporary, 'wb');

                if ($destination === false) {
                    fclose($stream);
                    throw new RuntimeException('Update file could not be staged.');
                }

                $copied = stream_copy_to_stream($stream, $destination);
                fclose($stream);
                fclose($destination);

                if ($copied === false) {
                    @unlink($temporary);
                    throw new RuntimeException('Update file could not be written.');
                }

                @chmod($temporary, 0644);

                if (! @rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('Update file could not be activated.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    public function runMigrations(): void
    {
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Update migrations failed.');
        }
    }

    public function rebuildCaches(): void
    {
        if (Artisan::call('optimize:clear') !== 0
            || Artisan::call('config:cache') !== 0
            || Artisan::call('view:cache') !== 0) {
            throw new RuntimeException('Application caches could not be rebuilt.');
        }
    }

    public function healthCheck(): void
    {
        DB::select('select 1');

        $probeDirectory = storage_path('app/system');
        $this->ensureDirectory($probeDirectory);
        $probe = $probeDirectory.'/update-health-'.bin2hex(random_bytes(5));

        if (file_put_contents($probe, 'ok', LOCK_EX) === false) {
            throw new RuntimeException('Storage health check failed.');
        }

        @unlink($probe);

        $version = trim((string) @file_get_contents(base_path('../VERSION')));
        if ($version === '') {
            throw new RuntimeException('Updated release version could not be read.');
        }
    }

    public function rollbackFiles(string $backupReference): void
    {
        $manifestPath = $backupReference.'/files.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('File rollback manifest is missing.');
        }

        try {
            $payload = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('File rollback manifest is invalid.');
        }

        if (! is_array($payload)) {
            throw new RuntimeException('File rollback manifest is invalid.');
        }

        $targets = $this->stringList($payload['targets'] ?? []);
        $existing = $this->stringList($payload['existing'] ?? []);

        foreach ($targets as $relativePath) {
            $target = $this->releaseRoot().'/'.$this->safeRelativePath($relativePath);
            if (is_file($target) && ! @unlink($target)) {
                throw new RuntimeException('Updated file could not be removed during rollback.');
            }
        }

        foreach ($existing as $relativePath) {
            $safePath = $this->safeRelativePath($relativePath);
            $source = $backupReference.'/existing/'.$safePath;
            $target = $this->releaseRoot().'/'.$safePath;
            $this->ensureDirectory(dirname($target));

            if (! is_file($source) || ! copy($source, $target)) {
                throw new RuntimeException('Original release file could not be restored.');
            }
        }
    }

    public function rollbackDatabase(string $backupReference): void
    {
        $metadataPath = $backupReference.'/database.json';

        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Database rollback metadata is invalid.');
        }

        if (! is_array($metadata)
            || ! is_string($metadata['driver'] ?? null)
            || ! is_string($metadata['snapshot'] ?? null)) {
            throw new RuntimeException('Database rollback metadata is invalid.');
        }

        $snapshot = $metadata['snapshot'];

        if ($metadata['driver'] === 'sqlite') {
            $database = (string) config('database.connections.sqlite.database');
            DB::purge();

            if (! is_file($snapshot) || ! copy($snapshot, $database)) {
                throw new RuntimeException('SQLite database rollback failed.');
            }

            return;
        }

        if ($metadata['driver'] === 'pgsql') {
            $connection = $this->databaseConnectionConfig();
            DB::purge();
            $this->runProcess([
                (string) config('foodex.pg_restore_binary', 'pg_restore'),
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '5432'),
                '--username='.(string) ($connection['username'] ?? ''),
                '--dbname='.(string) ($connection['database'] ?? ''),
                $snapshot,
            ], [
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            ]);

            return;
        }

        throw new RuntimeException('Database rollback driver is unsupported.');
    }

    public function exitMaintenanceMode(): void
    {
        if (Artisan::call('up') !== 0) {
            throw new RuntimeException('Maintenance mode could not be disabled.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function archiveEntries(string $packagePath): array
    {
        $archive = $this->openArchive($packagePath);

        try {
            return $this->archiveEntriesFrom($archive);
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function archiveEntriesFrom(ZipArchive $archive): array
    {
        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }

            $entries[] = $this->safeRelativePath($name);
        }

        if ($entries === []) {
            throw new RuntimeException('Update archive contains no release files.');
        }

        return array_values(array_unique($entries));
    }

    private function openArchive(string $packagePath): ZipArchive
    {
        $archive = new ZipArchive;
        if ($archive->open($packagePath) !== true) {
            throw new RuntimeException('Update package is not a valid ZIP archive.');
        }

        return $archive;
    }

    private function safeRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || str_contains($normalized, "\0")
            || preg_match('/(^|\/)\.\.($|\/)/', $normalized) === 1
            || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw new RuntimeException('Update archive contains an unsafe path.');
        }

        if ($normalized === '.env'
            || str_ends_with($normalized, '/.env')
            || preg_match('#(^|/)(storage|vendor|\.git)(/|$)#', $normalized) === 1) {
            throw new RuntimeException('Update archive targets a protected runtime path.');
        }

        return $normalized;
    }

    private function releaseRoot(): string
    {
        $root = realpath(dirname(base_path()));

        return $root !== false ? $root : dirname(base_path());
    }

    private function backupRoot(): string
    {
        $path = (string) config('foodex.update_backup_path', storage_path('app/system/update-backups'));
        $this->ensureDirectory($path);

        return $path;
    }

    private function newBackupDirectory(string $kind): string
    {
        $path = $this->backupRoot().'/'.now()->format('Ymd-His').'-'.$kind.'-'.bin2hex(random_bytes(5));
        $this->ensureDirectory($path);

        return $path;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Required updater directory could not be prepared.');
        }
    }

    private function writeDatabaseMetadata(string $directory, string $driver, string $snapshot): void
    {
        $json = json_encode([
            'driver' => $driver,
            'snapshot' => $snapshot,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($directory.'/database.json', $json.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Database rollback metadata could not be written.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseConnectionConfig(): array
    {
        $name = (string) config('database.default');

        return (array) config('database.connections.'.$name, []);
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $extraEnvironment
     */
    private function runProcess(array $command, array $extraEnvironment = []): void
    {
        $environment = getenv();

        foreach ($extraEnvironment as $key => $value) {
            $environment[$key] = $value;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Required database backup tool could not be started.');
        }

        /** @var array<int, resource> $pipes */
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('Required database backup tool failed.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
