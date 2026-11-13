<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UpdatePackageValidatorTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = tempnam(sys_get_temp_dir(), 'foodex-update-');
        file_put_contents($this->packagePath, 'signed release payload');
    }

    protected function tearDown(): void
    {
        @unlink($this->packagePath);
        parent::tearDown();
    }

    public function test_valid_package_passes_integrity_and_compatibility_checks(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', true);
        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertTrue($result['contains_migrations']);
    }

    public function test_migration_zip_cannot_bypass_database_backup_when_manifest_flag_is_false(): void
    {
        $this->createZipPackage(['backend/database/migrations/2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Migration bundle',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_backslash_migration_paths_cannot_bypass_backup_declaration(): void
    {
        $this->createZipPackage(['backend\database\migrations\2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Windows path migration bundle',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_migration_zip_with_declared_database_backup_passes_validator(): void
    {
        $this->createZipPackage(['backend/database/migrations/2026_11_13_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            hash_file('sha256', $this->packagePath),
            'Migration bundle',
            true,
        );

        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);

        $this->assertTrue($result['contains_migrations']);
        $this->assertSame('1.2.0', $result['target_version']);
    }

    public function test_legacy_compact_installed_version_is_normalized_for_upgrade_comparison(): void
    {
        $manifest = new UpdatePackageManifest('1.0.85', '1.0.84', hash_file('sha256', $this->packagePath), 'Release', false);

        $result = (new UpdatePackageValidator)->validate($manifest, '1.084', $this->packagePath);

        $this->assertSame('1.0.84', $result['current_version']);
        $this->assertSame('1.0.85', $result['target_version']);
    }

    public function test_noncanonical_manifest_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.086', '1.0.84', hash_file('sha256', $this->packagePath), 'Release', false);

        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.0.84', $this->packagePath);
    }

    public function test_tampered_package_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', str_repeat('a', 64), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_incompatible_current_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('2.0.0', '1.5.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.4.9', $this->packagePath);
    }

    public function test_downgrade_or_same_version_is_rejected(): void
    {
        $manifest = new UpdatePackageManifest('1.1.0', '1.0.0', hash_file('sha256', $this->packagePath), 'Release', false);
        $this->expectException(InvalidArgumentException::class);
        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function createZipPackage(array $paths): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required.');
        }

        $zip = new \ZipArchive;
        $this->assertTrue(
            $zip->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
        );

        foreach ($paths as $path) {
            $this->assertTrue($zip->addFromString($path, '<?php // migration fixture'));
        }

        $this->assertTrue($zip->close());
    }
}
