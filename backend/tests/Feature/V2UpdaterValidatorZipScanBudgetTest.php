<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use Tests\TestCase;

class V2UpdaterValidatorZipScanBudgetTest extends TestCase
{
    private string $packagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The PHP ZIP extension is required.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-zip-scan-');
        $this->assertNotFalse($path);
        $this->packagePath = $path;
    }

    protected function tearDown(): void
    {
        if ($this->packagePath !== '') {
            @unlink($this->packagePath);
        }

        parent::tearDown();
    }

    public function test_oversized_archive_is_rejected_before_migration_member_scan(): void
    {
        config()->set('foodex.update_max_zip_members', 3);

        $manifest = $this->createPackage([
            'backend/',
            'backend/app/one.php',
            'backend/app/two.php',
            'backend/database/migrations/2026_11_14_000000_test.php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowed member count');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_exact_member_budget_accepts_empty_files_and_directories(): void
    {
        config()->set('foodex.update_max_zip_members', 3);

        $manifest = $this->createPackage([
            'backend/',
            'backend/app/one.php',
            'backend/app/two.php',
        ]);

        $result = (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);

        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertFalse($result['contains_migrations']);
    }

    public function test_in_budget_undeclared_migration_is_still_rejected(): void
    {
        config()->set('foodex.update_max_zip_members', 3);

        $manifest = $this->createPackage([
            'backend/',
            'backend/app/one.php',
            'backend/database/migrations/2026_11_14_000000_test.php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains migrations');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    public function test_nonpositive_member_configuration_fails_closed(): void
    {
        config()->set('foodex.update_max_zip_members', 0);

        $manifest = $this->createPackage([
            'backend/',
            'backend/app/one.php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowed member count');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function createPackage(array $entries): UpdatePackageManifest
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->packagePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name) {
            $added = str_ends_with($name, '/')
                ? $zip->addEmptyDir(rtrim($name, '/'))
                : $zip->addFromString($name, '');

            $this->assertTrue($added);
        }

        $this->assertTrue($zip->close());

        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            (string) hash_file('sha256', $this->packagePath),
            'ZIP validator regression fixture',
            false,
        );
    }
}
