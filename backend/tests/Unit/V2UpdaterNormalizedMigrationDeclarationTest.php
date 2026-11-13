<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdatePackageValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class V2UpdaterNormalizedMigrationDeclarationTest extends TestCase
{
    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required to test update migration paths.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-updater-migration-path-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary ZIP fixture.');
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

    public function test_whitespace_and_backslash_aliases_cannot_hide_undeclared_migrations(): void
    {
        foreach ([
            ' backend/database/migrations/2026_11_14_000000_test.php',
            "backend/database/migrations/2026_11_14_000000_test.php \t",
            "\tbackend\\database\\migrations\\2026_11_14_000000_test.php",
            "\nbackend/database/migrations/2026_11_14_000000_test.php\r",
        ] as $zipMember) {
            $this->writeZip([$zipMember]);

            try {
                (new UpdatePackageValidator)->validate(
                    $this->manifest(false),
                    '1.1.0',
                    $this->packagePath,
                );

                $this->fail('An alias for a migration path must not bypass the backup declaration.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Update package contains migrations but database backup was not declared.',
                    $exception->getMessage(),
                    json_encode($zipMember, JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    public function test_declared_migrations_remain_valid_and_benign_files_remain_allowed(): void
    {
        $this->writeZip([' backend/database/migrations/2026_11_14_000000_test.php']);

        $declared = (new UpdatePackageValidator)->validate(
            $this->manifest(true),
            '1.1.0',
            $this->packagePath,
        );

        $this->assertTrue($declared['contains_migrations']);

        $this->writeZip(['docs/release-notes.txt', 'backend/app/Support/ReadOnlyMarker.php']);

        $withoutMigrations = (new UpdatePackageValidator)->validate(
            $this->manifest(false),
            '1.1.0',
            $this->packagePath,
        );

        $this->assertFalse($withoutMigrations['contains_migrations']);
        $this->assertSame('1.2.0', $withoutMigrations['target_version']);
    }

    public function test_invalid_checksum_still_fails_before_migration_declaration_scan(): void
    {
        $this->writeZip([' backend/database/migrations/2026_11_14_000000_test.php']);

        $manifest = new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            str_repeat('a', 64),
            'Synthetic invalid checksum',
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity check failed');

        (new UpdatePackageValidator)->validate($manifest, '1.1.0', $this->packagePath);
    }

    /**
     * @param  array<int, string> $paths
     */
    private function writeZip(array $paths): void
    {
        $archive = new ZipArchive;
        $this->assertTrue(
            $archive->open($this->packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,
        );

        foreach ($paths as $path) {
            $this->assertTrue($archive->addFromString($path, '<?php // synthetic fixture'));
        }

        $this->assertTrue($archive->close());
    }

    private function manifest(bool $containsMigrations): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '1.2.0',
            '1.0.0',
            (string) hash_file('sha256', $this->packagePath),
            'Synthetic package migration declaration',
            $containsMigrations,
        );
    }
}
