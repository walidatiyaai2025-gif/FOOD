<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class V2UpdaterCaseAliasedMigrationDeclarationTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for update package verification.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-migration-case-');
        if ($path === false) {
            throw new RuntimeException('Could not create isolated update ZIP fixture.');
        }

        $this->packagePath = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->packagePath)) {
            @unlink($this->packagePath);
        }

        parent::tearDown();
    }

    public function test_case_aliased_migration_directories_cannot_bypass_database_backup_declaration(): void
    {
        foreach ([
            'Backend/Database/Migrations/2026_11_14_000001_probe.PHP',
            'backend/DATABASE/migrations/2026_11_14_000001_probe.php',
            'BACKEND/database/MIGRATIONS/2026_11_14_000001_probe.PhP',
            " Backend\\Database\\Migrations\\2026_11_14_000001_probe.PHP \t",
            'backend/database/migrations/2026_11_14_000001_probe.php',
        ] as $entry) {
            $this->writeZip([$entry]);

            try {
                (new UpdatePackageValidator)->validate($this->manifest(false), '1.1.0', $this->packagePath);
                $this->fail('A case-aliased migration must not bypass the database backup declaration.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Update package contains migrations but database backup was not declared.',
                    $exception->getMessage(),
                    json_encode($entry, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    public function test_declared_case_aliased_migration_is_valid_for_manifest_classification(): void
    {
        $this->writeZip(['Backend/Database/Migrations/2026_11_14_000001_probe.PHP']);

        $result = (new UpdatePackageValidator)->validate($this->manifest(true), '1.1.0', $this->packagePath);

        $this->assertTrue($result['contains_migrations']);
        $this->assertSame('1.2.0', $result['target_version']);
    }

    public function test_distinct_nonmigration_paths_remain_accepted_without_backup_declaration(): void
    {
        $this->writeZip([
            'backend/database/migration_templates/safe.txt',
            'backend/app/ReadOnlyProbe.php',
            'Backend/Database/MigrationNotes/reference.PHP',
        ]);

        $result = (new UpdatePackageValidator)->validate($this->manifest(false), '1.1.0', $this->packagePath);

        $this->assertFalse($result['contains_migrations']);
        $this->assertSame((string) hash_file('sha256', $this->packagePath), $result['package_hash']);
    }

    public function test_checksum_mismatch_is_rejected_before_migration_scan(): void
    {
        $this->writeZip(['Backend/Database/Migrations/2026_11_14_000001_probe.PHP']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            str_repeat('0', 64),
            'Synthetic ZIP integrity regression',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity check failed');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    /** @param list<string> $paths */
    private function writeZip(array $paths): void
    {
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($this->packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($paths as $entry) {
            $this->assertTrue($archive->addFromString($entry, '<?php // isolated test payload'));
        }

        $this->assertTrue($archive->close());
    }

    private function manifest(bool $containsMigrations): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            (string) hash_file('sha256', $this->packagePath),
            'Synthetic migration source classification',
            $containsMigrations,
        );
    }
}
