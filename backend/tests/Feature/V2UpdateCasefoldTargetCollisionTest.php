<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdateCasefoldTargetCollisionTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryArchives = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_case_alias_release_targets_are_rejected_during_real_zip_preflight(): void
    {
        $runtime = app(LaravelUpdateRuntime::class);

        foreach ([
            ['backend/app/Config.php', 'backend/app/config.php'],
            ['backend/App/Services/Scope.php', 'backend/app/services/Scope.php'],
            ['backend/resources/Views/Report.blade.php', 'backend/resources/views/report.blade.php'],
        ] as $paths) {
            $archive = $this->createArchive($paths);

            try {
                $runtime->preflight($this->manifest($archive), $archive);
                $this->fail('ZIP preflight accepted two case-aliased release targets.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Update archive contains duplicate release target paths.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_case_aliased_file_directory_shadows_are_rejected_in_both_orders(): void
    {
        $runtime = app(LaravelUpdateRuntime::class);

        foreach ([
            ['backend/app/Tree', 'backend/app/tree/Leaf.php'],
            ['backend/app/tree/Leaf.php', 'backend/app/Tree'],
            ['backend/app/config', 'backend/app/config/Child.php'],
            ['backend/app/config/Child.php', 'backend/app/config'],
        ] as $paths) {
            $archive = $this->createArchive($paths);

            try {
                $runtime->preflight($this->manifest($archive), $archive);
                $this->fail('ZIP preflight accepted a file/directory target conflict.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Update archive contains a file/directory target conflict.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_distinct_release_files_and_normal_nested_directories_remain_allowed(): void
    {
        $archive = $this->createArchive([
            'backend/app/Alpha.php',
            'backend/app/Beta.php',
            'backend/app/Sub/Service.php',
            'backend/resources/views/receipt.blade.php',
        ]);

        // Actual ZIP member parsing and preflight, without backup, maintenance,
        // live extraction, migration, or any mutation of the installed release.
        app(LaravelUpdateRuntime::class)->preflight($this->manifest($archive), $archive);

        $this->assertFileExists($archive);
    }

    /**
     * @param  list<string>  $paths
     */
    private function createArchive(array $paths): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The ZIP extension is required for update security tests.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-zip-casefold-');
        $this->assertIsString($path);
        $this->temporaryArchives[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($paths as $name) {
            $this->assertTrue($zip->addFromString($name, 'V2 archive target collision regression'));
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.2',
            '99.0.1',
            hash_file('sha256', $path),
            'V2 ZIP release target collision regression fixture',
            false,
        );
    }
}
