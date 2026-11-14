<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class V2UpdaterCasefoldMigrationDeclarationTest extends TestCase
{
    /** @var list<string> */
    private array $archives = [];

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_undeclared_case_aliased_migrations_are_rejected_before_verification(): void
    {
        foreach ([
            'BACKEND/DATABASE/MIGRATIONS/2026_11_14_000000_test.php',
            'Backend/Database/Migrations/2026_11_14_000000_test.PHP',
            'backend/DataBase/migrations/2026_11_14_000000_test.Php',
            ' BACKEND\DATABASE\MIGRATIONS\2026_11_14_000000_test.PHP ',
        ] as $member) {
            $package = $this->archive([$member]);

            try {
                (new UpdatePackageValidator)->validate($this->manifest($package, false), '1.1.0', $package);
                $this->fail('ZIP Verify Package accepted an undeclared migration: '.$member);
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Update package contains migrations but database backup was not declared.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_case_aliased_migration_with_declaration_passes_manifest_validation(): void
    {
        $package = $this->archive(['BACKEND/DATABASE/MIGRATIONS/2026_11_14_000000_test.PHP']);
        $result = (new UpdatePackageValidator)->validate($this->manifest($package, true), '1.1.0', $package);

        $this->assertSame('1.2.0', $result['target_version']);
        $this->assertTrue($result['contains_migrations']);
    }

    public function test_unrelated_similar_named_directories_remain_valid_without_migrations(): void
    {
        $package = $this->archive([
            'backend/database/migrations_backup/example.php',
            'backend/database/other_migrations/example.php',
            'backend/app/Services/Example.php',
        ]);
        $result = (new UpdatePackageValidator)->validate($this->manifest($package, false), '1.1.0', $package);

        $this->assertFalse($result['contains_migrations']);
        $this->assertSame('1.2.0', $result['target_version']);
    }

    public function test_hash_mismatch_still_fails_before_archive_migration_scan(): void
    {
        $package = $this->archive(['BACKEND/DATABASE/MIGRATIONS/2026_11_14_000000_test.php']);
        $manifest = new UpdatePackageManifest('1.2.0', '1.0.0', str_repeat('a', 64), 'Tampered', false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity check failed');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $package);
    }

    /**
     * @param  list<string>  $members
     */
    private function archive(array $members): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP extension required for real archive verification.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-migration-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not allocate migration test ZIP.');
        }

        $this->archives[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($members as $member) {
            $this->assertTrue($zip->addFromString($member, '<?php // synthetic migration'));
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function manifest(string $package, bool $containsMigrations): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            (string) hash_file('sha256', $package),
            'ZIP casefold migration declaration regression',
            $containsMigrations,
        );
    }
}
