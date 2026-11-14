<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdateDotenvArchiveGuardTest extends TestCase
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

    public function test_live_dotenv_and_case_alias_runtime_files_fail_at_zip_preflight(): void
    {
        $runtime = app(LaravelUpdateRuntime::class);

        foreach ([
            'backend/.env',
            'backend/.env.local',
            'backend/.env.production',
            'backend/.env.production.backup',
            'backend/.env.local.secrets',
            'backend/.env.backup',
            'backend/.ENV',
            'backend/.Env.Secrets',
            'backend/config/.env.production',
            'backend/.env.production/private.php',
            'backend/.env.production.example/private.php',
            'backend/Storage/app/private/credentials.json',
            'backend/VENDOR/autoload.php',
            'backend/.GIT/config',
        ] as $path) {
            $archive = $this->createArchive([$path]);

            try {
                $runtime->preflight($this->manifest($archive), $archive);
                $this->fail('ZIP preflight accepted a protected runtime path: '.$path);
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Update archive targets a protected runtime path.',
                    $exception->getMessage(),
                    'ZIP preflight rejected an archive for an unexpected reason: '.$path,
                );
            }
        }
    }

    public function test_non_secret_dotenv_example_templates_and_normal_source_remain_allowed(): void
    {
        $archive = $this->createArchive([
            'backend/.env.example',
            'backend/.env.production.example',
            'backend/.env.testing.example',
            'backend/app/Example.php',
        ]);

        // Exercise the real archive reader and preflight rather than only a
        // helper's pattern match. This test never applies the ZIP to live files.
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

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-dotenv-');
        $this->assertIsString($path);
        $this->temporaryArchives[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($paths as $name) {
            $this->assertTrue($zip->addFromString($name, 'V2 trusted-template security regression'));
        }

        $this->assertTrue($zip->close());

        return $path;
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.1',
            '99.0.0',
            hash_file('sha256', $path),
            'V2 protected dotenv ZIP preflight fixture',
            false,
        );
    }
}
