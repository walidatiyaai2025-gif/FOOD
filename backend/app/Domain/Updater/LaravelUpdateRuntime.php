<?php

namespace App\Domain\Updater;

use App\Services\SchedulerRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use ZipArchive;

final class LaravelUpdateRuntime implements UpdateRuntime
{
    public function __construct(private readonly SchedulerRuntime $scheduler) {}

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

        // Validate the fully expanded archive before trusting the compressed
        // ZIP length as a disk-space estimate. A highly compressible payload
        // must not exhaust shared-host storage during staging/rollback.
        $expandedBytes = 0;
        $entries = $this->archiveEntries($packagePath, $expandedBytes);
        $backupRoot = $this->backupRoot();
        $freeSpace = disk_free_space($backupRoot);
        if ($freeSpace === false
            || $freeSpace < max($packageSize * 3, $expandedBytes * 2)) {
            throw new RuntimeException('Insufficient free disk space for safe update and rollback.');
        }

        // Reject symlinks before creating any backup or changing live files.
        foreach ($entries as $relativePath) {
            $this->assertSafeReleaseTarget($relativePath);
        }

        if ($manifest->containsMigrations) {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'sqlite') {
                $database = (string) config('database.connections.sqlite.database');
                if ($database === ':memory:' || ! is_file($database) || ! is_readable($database)) {
                    throw new RuntimeException('SQLite database cannot be backed up safely.');
                }
            } elseif ($driver === 'mysql') {
                $this->runProcess([(string) config('foodex.mysql_dump_binary', 'mysqldump'), '--version']);
                $this->runProcess([(string) config('foodex.mysql_client_binary', 'mysql'), '--version']);
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
        // Reject ambiguous/unsafe ZIP entries before creating any backup state.
        $entries = $this->archiveEntries($packagePath);
        $backupDirectory = $this->newBackupDirectory('files');
        $filesDirectory = $backupDirectory.'/existing';
        $this->ensureDirectory($filesDirectory);

        $targets = [];
        $existing = [];
        $fileDigests = [];

        foreach ($entries as $relativePath) {
            $this->assertSafeReleaseTarget($relativePath);
            $targets[] = $relativePath;
            $target = $this->releaseRoot().'/'.$relativePath;

            if (is_file($target)) {
                $backupPath = $filesDirectory.'/'.$relativePath;
                $this->ensureDirectory(dirname($backupPath));

                if (! copy($target, $backupPath)) {
                    throw new RuntimeException('Existing release file could not be backed up.');
                }

                // A readable file is not necessarily a recoverable file.
                // Record the bytes actually copied into this private snapshot.
                $digest = hash_file('sha256', $backupPath);
                if (! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/', $digest)) {
                    throw new RuntimeException('Existing release backup integrity could not be recorded.');
                }

                $fileDigests[$relativePath] = $digest;
                $existing[] = $relativePath;
            } elseif (file_exists($target)) {
                throw new RuntimeException('Update target conflicts with an existing non-file path.');
            }
        }

        $manifest = json_encode([
            'targets' => $targets,
            'existing' => $existing,
            // New snapshots have independently verifiable file content.
            // Older unversioned manifests remain readable for legacy recovery.
            'file_snapshot_format' => 1,
            'file_sha256' => (object) $fileDigests,
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

            if ($source === ':memory:' || ! is_file($source)) {
                throw new RuntimeException('SQLite database backup failed.');
            }

            $pdo = DB::connection()->getPdo();
            if ($pdo->inTransaction()) {
                throw new RuntimeException('SQLite database backup requires a committed database state.');
            }

            $quotedSnapshot = $pdo->quote($snapshot);
            if (! is_string($quotedSnapshot) || $quotedSnapshot === '') {
                throw new RuntimeException('SQLite database backup path could not be prepared.');
            }

            try {
                $pdo->exec('VACUUM INTO '.$quotedSnapshot);
            } catch (\Throwable $exception) {
                throw new RuntimeException('SQLite database backup failed.', 0, $exception);
            }

            if (! is_file($snapshot) || filesize($snapshot) === 0) {
                throw new RuntimeException('SQLite database backup did not produce a valid snapshot.');
            }

            $this->writeDatabaseMetadata($backupDirectory, 'sqlite', $snapshot);

            return $backupDirectory;
        }

        if ($driver === 'mysql') {
            $connection = $this->databaseConnectionConfig();
            $snapshot = $backupDirectory.'/database.sql';
            $this->runProcess([
                (string) config('foodex.mysql_dump_binary', 'mysqldump'),
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--user='.(string) ($connection['username'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ], [
                'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
            ], null, $snapshot);

            if (! is_file($snapshot) || filesize($snapshot) === 0) {
                throw new RuntimeException('MySQL/MariaDB database backup did not produce a valid snapshot.');
            }

            $this->writeDatabaseMetadata($backupDirectory, 'mysql', $snapshot);

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
                $stat = $archive->statName($relativePath);
                $expectedSize = is_array($stat) ? $stat['size'] : null;
                if (! is_int($expectedSize) || $expectedSize < 0) {
                    throw new RuntimeException('Update archive entry length could not be read.');
                }

                $stream = $archive->getStream($relativePath);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Update archive entry could not be read.');
                }

                $this->assertSafeReleaseTarget($relativePath);
                $target = $this->releaseRoot().'/'.$relativePath;
                $this->ensureDirectory(dirname($target));
                $this->assertSafeReleaseTarget($relativePath);
                $temporary = $target.'.foodex-update-'.bin2hex(random_bytes(6));
                $destination = fopen($temporary, 'wb');

                if ($destination === false) {
                    fclose($stream);
                    throw new RuntimeException('Update file could not be staged.');
                }

                // Bound actual decompression, not only ZIP central-directory
                // claims: extra data and truncated streams must fail before
                // the temporary file can be activated.
                $copied = stream_copy_to_stream($stream, $destination, $expectedSize + 1);
                fclose($stream);
                fclose($destination);

                if ($copied === false || $copied !== $expectedSize) {
                    @unlink($temporary);
                    throw new RuntimeException('Update archive entry size does not match its metadata.');
                }

                @chmod($temporary, 0644);

                if (! @rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('Update file could not be activated.');
                }

                $this->normalizePublicAssetPermissions($relativePath, $target);
            }
        } finally {
            $archive->close();
        }
    }

    public function runMigrations(): void
    {
        $exitCode = Artisan::call('migrate', ['--force' => true]);

        if ($exitCode !== 0) {
            $output = trim((string) Artisan::output());
            $output = preg_replace('/\s+/', ' ', $output) ?? $output;

            if (mb_strlen($output) > 1200) {
                $output = mb_substr($output, 0, 1200).'…';
            }

            throw new RuntimeException(
                $output !== ''
                    ? 'Update migrations failed: '.$output
                    : 'Update migrations failed with exit code '.$exitCode.'.'
            );
        }
    }

    public function rebuildCaches(): void
    {
        if (Artisan::call('optimize:clear') !== 0
            || Artisan::call('config:cache') !== 0
            || Artisan::call('view:cache') !== 0) {
            throw new RuntimeException('Application caches could not be rebuilt.');
        }

        // Built-in role definitions are configuration-authoritative, while live
        // authorization reads the persisted permission_role matrix. Reconcile in
        // a fresh PHP process only after config:cache so upgrades cannot finish
        // with new role definitions but stale database grants.
        $this->reconcileBuiltInJobRoles();

        $this->ensurePublicStorageLink();
        $this->scheduler->provisionCron();
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
        // Rollback sources must be issued under the configured trusted backup
        // root. Never accept a readable but foreign files.json as authority to
        // delete or restore release files.
        $rawConfiguredRoot = str_replace('\\', '/', $this->backupRoot());
        $configuredRoot = rtrim($rawConfiguredRoot, '/');
        $candidate = rtrim(str_replace('\\', '/', $backupReference), '/');
        $canonicalRoot = realpath($configuredRoot);
        $canonicalCandidate = realpath($candidate);
        $prefix = $configuredRoot.'/';

        if ($canonicalRoot === false
            || $canonicalCandidate === false
            || ! str_starts_with($candidate, $prefix)
            || ! str_starts_with(
                str_replace('\\', '/', $canonicalCandidate),
                rtrim(str_replace('\\', '/', $canonicalRoot), '/').'/'
            )) {
            throw new RuntimeException('File rollback backup location is invalid.');
        }

        // Reject traversals and symlinks even when an alias happens to resolve
        // back inside the trusted root. Keep configured-root ancestors working
        // on hosts where the trusted storage root itself has a canonical alias.
        // newBackupDirectory() historically appends '/' to the configured
        // backup root. When that setting already ends with '/', the stored
        // reference includes a double separator at this one trusted boundary.
        // Accept only exactly those generated boundary separators, never
        // arbitrary aliases or empty/dot segments inside the backup path.
        $relativeBackupPath = substr($candidate, strlen($prefix));
        $rootTrailingSeparators = strlen($rawConfiguredRoot) - strlen($configuredRoot);
        if ($rootTrailingSeparators > 0) {
            $generatedBoundary = str_repeat('/', $rootTrailingSeparators);
            if (str_starts_with($relativeBackupPath, $generatedBoundary)
                && ! str_starts_with(substr($relativeBackupPath, $rootTrailingSeparators), '/')) {
                $relativeBackupPath = substr($relativeBackupPath, $rootTrailingSeparators);
            }
        }

        $component = $configuredRoot;
        foreach (explode('/', $relativeBackupPath) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('File rollback backup location is invalid.');
            }

            $component .= '/'.$part;
            if (is_link($component)) {
                throw new RuntimeException('File rollback backup location is invalid.');
            }
        }

        $manifestPath = $candidate.'/files.json';
        if (is_link($backupReference) || is_link($manifestPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('File rollback manifest is missing or unsafe.');
        }

        try {
            $payload = json_decode((string) file_get_contents($manifestPath), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('File rollback manifest is invalid.');
        }

        if (! ($payload instanceof \stdClass)
            || ! is_array($payload->targets ?? null)
            || ! array_is_list($payload->targets)
            || ! is_array($payload->existing ?? null)
            || ! array_is_list($payload->existing)) {
            throw new RuntimeException('File rollback manifest is invalid.');
        }

        $targets = $this->stringList($payload->targets);
        $existing = $this->stringList($payload->existing);

        // Never silently accept incomplete or malformed integrity metadata in
        // a versioned snapshot. Unversioned files.json is supported only for
        // pre-existing legacy recovery references (not claimed as verified).
        $hasSnapshotFormat = property_exists($payload, 'file_snapshot_format');
        $hasSnapshotHashes = property_exists($payload, 'file_sha256');
        $fileDigests = null;
        if ($hasSnapshotFormat || $hasSnapshotHashes) {
            if (! $hasSnapshotFormat
                || ! $hasSnapshotHashes
                || $payload->file_snapshot_format !== 1
                || ! ($payload->file_sha256 instanceof \stdClass)) {
                throw new RuntimeException('File rollback manifest is invalid.');
            }

            $fileDigests = get_object_vars($payload->file_sha256);
            if (count($fileDigests) !== count($existing)) {
                throw new RuntimeException('File rollback manifest is invalid.');
            }
        }

        if ($targets === []
            || count($targets) !== count($payload->targets)
            || count($existing) !== count($payload->existing)) {
            throw new RuntimeException('File rollback manifest is invalid.');
        }

        // Validate the ENTIRE rollback manifest and every required backup source
        // before removing even one live file. A partial/corrupt backup must never
        // turn a recoverable failed update into further destructive file loss.
        $targetSet = [];
        foreach ($targets as $relativePath) {
            if ($relativePath !== $this->safeRelativePath($relativePath)
                || isset($targetSet[$relativePath])) {
                throw new RuntimeException('File rollback manifest is invalid.');
            }

            $this->assertSafeReleaseTarget($relativePath);
            $targetSet[$relativePath] = true;
        }

        $existingSet = [];
        $backedUpFiles = rtrim($backupReference, '/\\').'/existing';
        if (is_link($backedUpFiles)) {
            throw new RuntimeException('File rollback backup is incomplete or unsafe.');
        }

        foreach ($existing as $relativePath) {
            if ($relativePath !== $this->safeRelativePath($relativePath)
                || ! isset($targetSet[$relativePath])
                || isset($existingSet[$relativePath])) {
                throw new RuntimeException('File rollback manifest is invalid.');
            }

            $this->assertSafeReleaseTarget($relativePath);
            $existingSet[$relativePath] = true;

            $source = $backedUpFiles;
            foreach (explode('/', $relativePath) as $part) {
                $source .= '/'.$part;
                if (is_link($source)) {
                    throw new RuntimeException('File rollback backup is incomplete or unsafe.');
                }
            }

            // Empty original files are valid; require readability, not a
            // nonzero size. Do not follow links in the private backup tree.
            if (! is_file($source) || ! is_readable($source)) {
                throw new RuntimeException('File rollback backup is incomplete or unsafe.');
            }

            if ($fileDigests !== null) {
                $expected = $fileDigests[$relativePath] ?? null;
                if (! is_string($expected)
                    || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                    throw new RuntimeException('File rollback manifest is invalid.');
                }

                $actual = hash_file('sha256', $source);
                if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                    throw new RuntimeException('File rollback backup is incomplete or unsafe.');
                }
            }
        }

        foreach ($targets as $relativePath) {
            $this->assertSafeReleaseTarget($relativePath);
            $target = $this->releaseRoot().'/'.$relativePath;
            if (is_file($target) && ! @unlink($target)) {
                throw new RuntimeException('Updated file could not be removed during rollback.');
            }
        }

        foreach ($existing as $relativePath) {
            $this->assertSafeReleaseTarget($relativePath);
            $source = $backedUpFiles.'/'.$relativePath;
            $target = $this->releaseRoot().'/'.$relativePath;
            $this->ensureDirectory(dirname($target));
            $this->assertSafeReleaseTarget($relativePath);

            if (! copy($source, $target)) {
                throw new RuntimeException('Original release file could not be restored.');
            }
        }
    }

    public function verifyDatabaseBackup(string $backupReference): void
    {
        // Reject tampered references and snapshot metadata before opening a
        // database dump or invoking any database client.
        $metadata = $this->trustedDatabaseBackupMetadata($backupReference);
        $snapshot = $metadata['snapshot'];

        if ($metadata['driver'] === 'sqlite') {
            try {
                $pdo = new \PDO('sqlite:'.$snapshot, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->query('PRAGMA schema_version')->fetchColumn();
                $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();
            } catch (\Throwable $exception) {
                throw new RuntimeException('SQLite backup verification failed.', 0, $exception);
            }
            if ($result !== 'ok') {
                throw new RuntimeException('SQLite backup integrity verification failed.');
            }

            return;
        }

        if ($metadata['driver'] === 'mysql') {
            $this->runProcess([(string) config('foodex.mysql_client_binary', 'mysql'), '--version']);
            $handle = fopen($snapshot, 'rb');
            $prefix = $handle === false ? false : fread($handle, 4096);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (! is_string($prefix) || $prefix === '' || (! str_contains($prefix, 'MySQL dump') && ! str_contains($prefix, 'MariaDB dump'))) {
                throw new RuntimeException('MySQL/MariaDB backup does not have a recognized restorable dump header.');
            }

            return;
        }

        if ($metadata['driver'] === 'pgsql') {
            $this->runProcess([(string) config('foodex.pg_restore_binary', 'pg_restore'), '--list', $snapshot]);

            return;
        }

        throw new RuntimeException('Database backup verification driver is unsupported.');
    }

    public function rollbackDatabase(string $backupReference): void
    {
        // Revalidate the actual backup immediately before recovery, even when
        // it was previously checked at backup time. No DB purge, sidecar unlink
        // or restore process may run until all provenance/integrity checks pass.
        $this->verifyDatabaseBackup($backupReference);
        $metadata = $this->trustedDatabaseBackupMetadata($backupReference);
        $snapshot = $metadata['snapshot'];

        if ($metadata['driver'] === 'sqlite') {
            $database = (string) config('database.connections.sqlite.database');
            DB::purge();

            foreach ([$database.'-wal', $database.'-shm', $database.'-journal'] as $sidecar) {
                if (is_file($sidecar) && ! @unlink($sidecar)) {
                    throw new RuntimeException('SQLite rollback sidecar could not be removed.');
                }
            }

            if (! is_file($snapshot) || ! copy($snapshot, $database)) {
                throw new RuntimeException('SQLite database rollback failed.');
            }

            return;
        }

        if ($metadata['driver'] === 'mysql') {
            $connection = $this->databaseConnectionConfig();
            DB::purge();
            $this->runProcess([
                (string) config('foodex.mysql_client_binary', 'mysql'),
                '--default-character-set=utf8mb4',
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? '3306'),
                '--user='.(string) ($connection['username'] ?? ''),
                (string) ($connection['database'] ?? ''),
            ], [
                'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
            ], $snapshot);

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

    /**
     * Validate database backup provenance without modifying the database.
     *
     * @return array{driver: string, snapshot: string}
     */
    private function trustedDatabaseBackupMetadata(string $backupReference): array
    {
        $rawRoot = str_replace('\\', '/', $this->backupRoot());
        $configuredRoot = rtrim($rawRoot, '/');
        $candidate = rtrim(str_replace('\\', '/', $backupReference), '/');
        $canonicalRoot = realpath($configuredRoot);
        $canonicalCandidate = realpath($candidate);
        $prefix = $configuredRoot.'/';

        if ($canonicalRoot === false
            || $canonicalCandidate === false
            || ! str_starts_with($candidate, $prefix)
            || ! str_starts_with(
                str_replace('\\', '/', $canonicalCandidate),
                rtrim(str_replace('\\', '/', $canonicalRoot), '/').'/'
            )) {
            throw new RuntimeException('Database backup location is invalid or unsafe.');
        }

        // The backup creator appends "/" to a configured root. Preserve its
        // generated reference if the configuration already has a trailing
        // slash, but reject every other empty/dot/alias or symlink component.
        $relative = substr($candidate, strlen($prefix));
        $trailingSeparators = strlen($rawRoot) - strlen($configuredRoot);
        if ($trailingSeparators > 0
            && str_starts_with($relative, str_repeat('/', $trailingSeparators))
            && ! str_starts_with(substr($relative, $trailingSeparators), '/')) {
            $relative = substr($relative, $trailingSeparators);
        }

        $component = $configuredRoot;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Database backup location is invalid or unsafe.');
            }

            $component .= '/'.$part;
            if (is_link($component)) {
                throw new RuntimeException('Database backup location is invalid or unsafe.');
            }
        }

        $metadataPath = $candidate.'/database.json';
        if (is_link($metadataPath) || ! is_file($metadataPath) || ! is_readable($metadataPath)) {
            throw new RuntimeException('Database backup metadata is invalid or unsafe.');
        }

        try {
            $json = @file_get_contents($metadataPath);
            if (! is_string($json)) {
                throw new RuntimeException('Database backup metadata is invalid or unsafe.');
            }

            $metadata = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Database backup metadata is invalid or unsafe.');
        }

        $names = [
            'sqlite' => 'database.sqlite',
            'mysql' => 'database.sql',
            'pgsql' => 'database.dump',
        ];
        $driver = is_array($metadata) ? ($metadata['driver'] ?? null) : null;
        $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;

        if (! is_string($driver)
            || ! isset($names[$driver])
            || $driver !== DB::connection()->getDriverName()
            || ! is_string($snapshot)) {
            throw new RuntimeException('Database backup metadata is invalid or unsafe.');
        }

        $expected = $candidate.'/'.$names[$driver];
        if (str_replace('\\', '/', $snapshot) !== $expected
            || is_link($expected)
            || ! is_file($expected)
            || ! is_readable($expected)
            || filesize($expected) === 0
            || realpath($expected) !== rtrim(str_replace('\\', '/', $canonicalCandidate), '/').'/'.$names[$driver]) {
            throw new RuntimeException('Database backup snapshot is missing or unsafe.');
        }

        return ['driver' => $driver, 'snapshot' => $expected];
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
    private function archiveEntries(string $packagePath, int &$expandedBytes = 0): array
    {
        $archive = $this->openArchive($packagePath);

        try {
            return $this->archiveEntriesFrom($archive, $expandedBytes);
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function archiveEntriesFrom(ZipArchive $archive, int &$expandedBytes = 0): array
    {
        $entries = [];
        $seen = [];
        $requiredDirectories = [];
        $expandedBytes = 0;

        // Empty members and directories can exhaust memory/CPU without
        // consuming any expanded-byte budget. Bound the central directory
        // before preflight, backup or extraction inspects the first entry.
        $maxMembers = max(1, (int) config('foodex.update_max_zip_members', 50000));
        if ($archive->numFiles > $maxMembers) {
            throw new RuntimeException('Update archive exceeds the allowed member count.');
        }

        // Configurable for verified larger releases, but always positive and
        // finite: a tiny ZIP must not unpack an unlimited volume of files.
        $maxEntry = max(1, (int) config('foodex.update_max_entry_uncompressed_bytes', 268435456));
        $maxTotal = max(1, (int) config('foodex.update_max_total_uncompressed_bytes', 1073741824));

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if ($name === false) {
                throw new RuntimeException('Update archive contains an unreadable entry name.');
            }

            $operatingSystem = 0;
            $attributes = 0;
            if ($archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                && $operatingSystem === ZipArchive::OPSYS_UNIX) {
                $type = ($attributes >> 16) & 0170000;
                if ($type === 0120000) {
                    throw new RuntimeException('Update archive contains a symbolic link.');
                }

                // A ZIP member with a normal-looking filename may still be
                // labelled as a FIFO, device, socket, or disguised directory.
                // Allow Unix regular files and missing type bits for ZIPs made
                // on other platforms; canonical directory entries are skipped.
                if ($type !== 0
                    && $type !== 0100000
                    && ! ($type === 0040000 && str_ends_with($name, '/'))) {
                    throw new RuntimeException('Update archive contains a non-regular file entry.');
                }
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $stat = $archive->statIndex($index);
            $memberBytes = is_array($stat) ? $stat['size'] : null;
            if (! is_int($memberBytes) || $memberBytes < 0) {
                throw new RuntimeException('Update archive entry length could not be read.');
            }

            // Subtract first to avoid integer overflow when accumulating ZIP
            // central-directory sizes on 64-bit PHP.
            if ($memberBytes > $maxEntry || $memberBytes > $maxTotal - $expandedBytes) {
                throw new RuntimeException('Update archive exceeds the allowed expanded size.');
            }

            $expandedBytes += $memberBytes;

            // Two different ZIP member names can normalize to the same live
            // target (e.g. forward/backslash aliases). Never silently choose
            // one member while backup and extraction may read another.
            $relativePath = $this->safeRelativePath($name);
            // A pair of distinct ZIP paths can resolve to one live file on
            // case-insensitive cPanel/Windows/macOS filesystems. Backups and
            // extraction must agree on one deterministic target per member.
            $foldedPath = mb_strtolower($relativePath, 'UTF-8');
            if (isset($seen[$foldedPath])) {
                throw new RuntimeException('Update archive contains duplicate release target paths.');
            }

            // Keep duplicate-target rejection authoritative for existing
            // security regressions, then refuse a single noncanonical member.
            // The extractor opens ZIP streams using $relativePath, which may
            // not exist in an archive with padded or backslash-aliased names.
            if ($name !== $relativePath) {
                throw new RuntimeException('Update archive contains a non-canonical path.');
            }

            // A file cannot also be an ancestor directory of another archive
            // member (including via a case alias). Refuse both ZIP entry orders
            // before maintenance, file backup, or any release mutation.
            if (isset($requiredDirectories[$foldedPath])) {
                throw new RuntimeException('Update archive contains a file/directory target conflict.');
            }

            $parts = explode('/', $foldedPath);
            $parent = '';
            for ($partIndex = 0, $lastPart = count($parts) - 1; $partIndex < $lastPart; $partIndex++) {
                $parent = $parent === '' ? $parts[$partIndex] : $parent.'/'.$parts[$partIndex];
                if (isset($seen[$parent])) {
                    throw new RuntimeException('Update archive contains a file/directory target conflict.');
                }

                $requiredDirectories[$parent] = true;
            }

            $seen[$foldedPath] = true;
            $entries[] = $relativePath;
        }

        if ($entries === []) {
            throw new RuntimeException('Update archive contains no release files.');
        }

        return $entries;
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

        // Treat every live dotenv variant as protected runtime state, not
        // release source. Explicit *.example templates are versioned defaults,
        // never the active credentials. Check each component so a ZIP cannot
        // replace a private dotenv directory or exploit case-insensitive hosts.
        $pathComponents = explode('/', $normalized);
        $lastComponent = array_key_last($pathComponents);
        foreach ($pathComponents as $index => $component) {
            // On Windows and some shared-host filesystems, trailing periods
            // or spaces in a component alias a different live path. Reject
            // them before protected-directory and symlink checks.
            if ($component === '' || str_ends_with($component, '.') || str_ends_with($component, ' ')) {
                throw new RuntimeException('Update archive contains an unsafe path.');
            }

            $isExampleTemplate = $index === $lastComponent
                && preg_match('/^\.env(?:\.[A-Za-z0-9_-]+)*\.example$/', $component) === 1;

            if (preg_match('/^\.env(?:\..*)?$/i', $component) === 1 && ! $isExampleTemplate) {
                throw new RuntimeException('Update archive targets a protected runtime path.');
            }
        }

        if (preg_match('#(^|/)(storage|vendor|\.git)(/|$)#i', $normalized) === 1) {
            throw new RuntimeException('Update archive targets a protected runtime path.');
        }

        // Shared-host public release-root mirrors are static assets only.
        // Never stage server-executable scripts or handler configuration
        // there, including Apache multi-extension names like foo.php.jpg.
        // Laravel's own backend/public/index.php remains a valid release file.
        $staticMirror = preg_match('#^(assets|brand|demo|preview)/#i', $normalized) === 1;
        $laravelPublic = preg_match('#^backend/public/#i', $normalized) === 1;
        // Keep Laravel's exact front controller and root rewrite config.
        // All other scripts and handler overrides inside the served webroot
        // are unsafe to stage even from a signed ZIP release.
        $laravelBootstrap = in_array($normalized, [
            'backend/public/index.php',
            'backend/public/.htaccess',
        ], true);

        if (($staticMirror || ($laravelPublic && ! $laravelBootstrap))
            && (preg_match('#(?:^|/)[^/]*\.(?:php[0-9]*|phtml|phar|cgi|pl|py|sh|asp|aspx|jsp)(?:\.|$)#i', $normalized) === 1
                || preg_match('#(?:^|/)(?:\.htaccess|\.user\.ini|web\.config)(?:\.|$)#i', $normalized) === 1)) {
            throw new RuntimeException('Update archive targets an executable public asset path.');
        }

        return $normalized;
    }

    /**
     * Prevent following existing symlinks (including dangling links) from the
     * canonical release root to any package file. Recheck immediately before
     * activation and recovery, not only during the initial package preflight.
     */
    private function assertSafeReleaseTarget(string $relativePath): void
    {
        $parts = explode('/', $this->safeRelativePath($relativePath));
        $target = $this->releaseRoot();

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                throw new RuntimeException('Update archive contains a non-canonical path.');
            }

            $target .= '/'.$part;
            if (is_link($target)) {
                throw new RuntimeException('Update target traverses a symbolic link.');
            }
        }
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

    private function normalizePublicAssetPermissions(string $relativePath, string $target): void
    {
        if (str_starts_with($relativePath, 'backend/public/')) {
            $this->normalizePublicAssetTree($target, rtrim(public_path(), DIRECTORY_SEPARATOR), true);

            return;
        }

        if (preg_match('#^(assets|brand|demo|preview)/#', $relativePath) === 1) {
            // Supported shared-host deployments may serve the release root
            // directly while Laravel remains under backend/. The update bundle
            // mirrors static-only public files there; never mirror PHP/front
            // controller or .htaccess files.
            $this->normalizePublicAssetTree(
                $target,
                rtrim($this->releaseRoot(), DIRECTORY_SEPARATOR),
                false,
            );
        }
    }

    private function normalizePublicAssetTree(string $target, string $publicRoot, bool $normalizeRoot): void
    {
        if (! @chmod($target, 0644)) {
            throw new RuntimeException('Updated public asset permissions could not be normalized.');
        }

        $directory = dirname($target);

        while (str_starts_with($directory.DIRECTORY_SEPARATOR, $publicRoot.DIRECTORY_SEPARATOR)) {
            if ($directory === $publicRoot && ! $normalizeRoot) {
                break;
            }

            if (! @chmod($directory, 0755)) {
                throw new RuntimeException('Public asset directory permissions could not be normalized.');
            }

            if ($directory === $publicRoot) {
                break;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new RuntimeException('Required updater directory could not be prepared.');
        }
    }

    private function reconcileBuiltInJobRoles(): void
    {
        $php = (string) config('foodex.scheduler_php_binary', PHP_BINDIR.'/php');
        $artisan = base_path('artisan');

        if ($php === '' || ! is_file($artisan)) {
            throw new RuntimeException('Built-in role reconciliation runtime is unavailable.');
        }

        $this->runProcess([
            $php,
            $artisan,
            'db:seed',
            '--class=Database\\Seeders\\BuiltInJobRoleSeeder',
            '--force',
            '--no-interaction',
        ]);
    }

    private function ensurePublicStorageLink(): void
    {
        $publicRoot = (string) config('filesystems.disks.public.root');
        $this->ensureDirectory($publicRoot);

        $publicLink = public_path('storage');
        if (! is_link($publicLink) && ! is_dir($publicLink)) {
            Artisan::call('storage:link');
        }

        if (! is_link($publicLink) && ! is_dir($publicLink)) {
            throw new RuntimeException('The public/storage link could not be prepared after the update.');
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
    private function runProcess(
        array $command,
        array $extraEnvironment = [],
        ?string $stdinPath = null,
        ?string $stdoutPath = null,
    ): void {
        $environment = getenv();

        foreach ($extraEnvironment as $key => $value) {
            $environment[$key] = $value;
        }

        $descriptors = [
            0 => $stdinPath === null ? ['pipe', 'r'] : ['file', $stdinPath, 'r'],
            1 => $stdoutPath === null ? ['pipe', 'w'] : ['file', $stdoutPath, 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Required database backup tool could not be started.');
        }

        /** @var array<int, resource> $pipes */
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        if (isset($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

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
