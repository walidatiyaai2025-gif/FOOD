<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterDatabaseBackupProvenanceTest extends TestCase
{
    private string $backupRoot;

    private string $backupDirectory;

    private string $foreignDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(8));
        $this->backupRoot = storage_path('framework/testing/v2-db-backups-'.$suffix);
        $this->backupDirectory = $this->backupRoot.'/database';
        $this->foreignDirectory = storage_path('framework/testing/v2-db-foreign-'.$suffix);

        File::ensureDirectoryExists($this->backupDirectory);
        File::ensureDirectoryExists($this->foreignDirectory);
        config()->set('foodex.update_backup_path', $this->backupRoot);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    protected function tearDown(): void
    {
        if (isset($this->backupRoot)) {
            foreach ([
                $this->backupRoot.'/linked',
                $this->backupDirectory.'/database.json',
                $this->backupDirectory.'/database.sqlite',
            ] as $path) {
                if (is_link($path)) {
                    @unlink($path);
                }
            }

            File::deleteDirectory($this->backupRoot);
        }

        if (isset($this->foreignDirectory)) {
            File::deleteDirectory($this->foreignDirectory);
        }

        parent::tearDown();
    }

    public function test_foreign_database_backup_is_rejected_before_any_db_mutation(): void
    {
        $this->validSqliteBackup($this->foreignDirectory);

        $this->assertRejectedWithoutDatabasePurge($this->foreignDirectory);
    }

    public function test_parent_segment_alias_is_rejected_before_any_db_mutation(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        File::ensureDirectoryExists($this->backupRoot.'/nested');

        $this->assertRejectedWithoutDatabasePurge($this->backupRoot.'/nested/../database');
    }

    public function test_symlinked_backup_directory_is_rejected_before_any_db_mutation(): void
    {
        $this->validSqliteBackup($this->backupDirectory);

        if (! function_exists('symlink')
            || ! @symlink($this->backupDirectory, $this->backupRoot.'/linked')) {
            $this->markTestSkipped('Filesystem symlinks are unsupported in this test runner.');
        }

        $this->assertRejectedWithoutDatabasePurge($this->backupRoot.'/linked');
    }

    public function test_symlinked_database_manifest_is_rejected_before_any_db_mutation(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        $this->validSqliteBackup($this->foreignDirectory);
        $path = $this->backupDirectory.'/database.json';
        @unlink($path);

        if (! function_exists('symlink')
            || ! @symlink($this->foreignDirectory.'/database.json', $path)) {
            $this->markTestSkipped('Filesystem symlinks are unsupported in this test runner.');
        }

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_symlinked_database_snapshot_is_rejected_before_any_db_mutation(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        $this->validSqliteBackup($this->foreignDirectory);
        $path = $this->backupDirectory.'/database.sqlite';
        @unlink($path);

        if (! function_exists('symlink')
            || ! @symlink($this->foreignDirectory.'/database.sqlite', $path)) {
            $this->markTestSkipped('Filesystem symlinks are unsupported in this test runner.');
        }

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_metadata_cannot_repoint_snapshot_to_an_external_sqlite_file(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        $this->validSqliteBackup($this->foreignDirectory);
        $this->manifest($this->backupDirectory, 'sqlite', $this->foreignDirectory.'/database.sqlite');

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_metadata_cannot_repoint_snapshot_to_another_file_inside_backup_dir(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        $source = $this->backupDirectory.'/database.sqlite';
        $other = $this->backupDirectory.'/other.sqlite';
        copy($source, $other);
        $this->manifest($this->backupDirectory, 'sqlite', $other);

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_metadata_driver_cannot_override_active_database_connection(): void
    {
        $this->validSqliteBackup($this->backupDirectory);
        file_put_contents($this->backupDirectory.'/database.sql', '-- MySQL dump\n');
        $this->manifest($this->backupDirectory, 'mysql', $this->backupDirectory.'/database.sql');

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_missing_or_invalid_database_manifest_is_rejected_without_purge(): void
    {
        file_put_contents($this->backupDirectory.'/database.json', '{invalid-json');

        $this->assertRejectedWithoutDatabasePurge($this->backupDirectory);
    }

    public function test_valid_trusted_sqlite_snapshot_passes_integrity_verification(): void
    {
        $this->validSqliteBackup($this->backupDirectory);

        app(LaravelUpdateRuntime::class)->verifyDatabaseBackup($this->backupDirectory);
        $this->assertFileExists($this->backupDirectory.'/database.sqlite');
    }

    public function test_configured_trailing_separator_accepts_only_the_generated_boundary_alias(): void
    {
        $reference = $this->backupRoot.'//database';
        $this->validSqliteBackup($reference);
        config()->set('foodex.update_backup_path', $this->backupRoot.'/');

        app(LaravelUpdateRuntime::class)->verifyDatabaseBackup($reference);
        $this->assertRejectedWithoutDatabasePurge($this->backupRoot.'///database');
    }

    public function test_valid_trusted_sqlite_database_snapshot_can_restore_local_fixture(): void
    {
        $this->validSqliteBackup($this->backupDirectory);

        $liveDatabase = $this->backupRoot.'/live.sqlite';
        $pdo = new PDO('sqlite:'.$liveDatabase);
        $pdo->exec('CREATE TABLE restore_probe (marker TEXT NOT NULL)');
        $pdo->exec("INSERT INTO restore_probe (marker) VALUES ('updated')");
        $pdo = null;

        $original = config('database.connections.sqlite.database');
        config()->set('database.connections.sqlite.database', $liveDatabase);
        DB::purge('sqlite');

        try {
            app(LaravelUpdateRuntime::class)->rollbackDatabase($this->backupDirectory);

            $restored = new PDO('sqlite:'.$liveDatabase);
            $this->assertSame(
                'original',
                $restored->query('SELECT marker FROM restore_probe')->fetchColumn(),
            );
            $restored = null;
        } finally {
            DB::purge('sqlite');
            config()->set('database.connections.sqlite.database', $original);
        }
    }

    private function validSqliteBackup(string $directory): void
    {
        File::ensureDirectoryExists($directory);
        $snapshot = $directory.'/database.sqlite';
        $pdo = new PDO('sqlite:'.$snapshot);
        $pdo->exec('CREATE TABLE restore_probe (marker TEXT NOT NULL)');
        $pdo->exec("INSERT INTO restore_probe (marker) VALUES ('original')");
        $pdo = null;

        $this->manifest($directory, 'sqlite', $snapshot);
    }

    private function manifest(string $directory, string $driver, string $snapshot): void
    {
        file_put_contents($directory.'/database.json', json_encode([
            'driver' => $driver,
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertRejectedWithoutDatabasePurge(string $reference): void
    {
        $connectionBefore = DB::connection()->getPdo();

        foreach (['verifyDatabaseBackup', 'rollbackDatabase'] as $method) {
            try {
                app(LaravelUpdateRuntime::class)->{$method}($reference);
                $this->fail('Untrusted database backup must be rejected before any restore side effect.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Database backup', $exception->getMessage());
                $this->assertStringNotContainsString($this->backupRoot, $exception->getMessage());
                $this->assertStringNotContainsString($this->foreignDirectory, $exception->getMessage());
            }

            $this->assertSame($connectionBefore, DB::connection()->getPdo());
        }
    }
}
