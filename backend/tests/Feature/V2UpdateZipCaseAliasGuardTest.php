<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdateZipCaseAliasGuardTest extends TestCase
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

    public function test_case_colliding_zip_members_cannot_target_the_same_live_file(): void
    {
        foreach ([
            ['backend/app/Widget.php', 'backend/app/widget.php'],
            ['backend/App/Widget.php', 'backend/app/Widget.php'],
            ['backend/config/production.php', 'backend/CONFIG/production.php'],
        ] as $paths) {
            $archive = $this->createArchive($paths);

            try {
                app(LaravelUpdateRuntime::class)->preflight($this->manifest($archive), $archive);
                $this->fail('ZIP preflight accepted an ASCII case-fold collision: '.implode(', ', $paths));
            } catch (RuntimeException $exception) {
                $this->assertSame('Update archive contains duplicate release target paths.', $exception->getMessage());
            }
        }
    }

    public function test_trailing_dots_or_spaces_in_any_zip_path_component_are_rejected(): void
    {
        foreach ([
            'backend/vendor./autoload.php',
            'backend/.git./config',
            'backend/Storage. /app/private.json',
            'backend/app. /Widget.php',
            'backend/app /Widget.php',
            'backend/app/Widget.php.',
        ] as $member) {
            $archive = $this->createArchive([$member]);

            try {
                app(LaravelUpdateRuntime::class)->preflight($this->manifest($archive), $archive);
                $this->fail('ZIP preflight accepted an aliased path: '.$member);
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Update archive contains an unsafe path.',
                    $exception->getMessage(),
                    'Unexpected ZIP path guard outcome for '.$member,
                );
            }
        }
    }

    public function test_canonical_distinct_source_members_and_example_templates_are_allowed(): void
    {
        $archive = $this->createArchive([
            'backend/.env.example',
            'backend/.env.production.example',
            'backend/app/Widget.php',
            'backend/app/widget-service.php',
            'backend/config/production.php',
        ]);

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

        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-case-alias-');
        $this->assertIsString($path);
        $this->temporaryArchives[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($paths as $name) {
            $this->assertTrue($zip->addFromString($name, 'V2 archive path alias security regression'));
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
            'V2 Windows path alias preflight fixture',
            false,
        );
    }
}
