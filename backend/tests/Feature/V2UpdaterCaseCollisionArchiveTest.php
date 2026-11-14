<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterCaseCollisionArchiveTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupDirectory;

    /** @var list<string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for updater archive tests.');
        }

        $this->releaseName = 'v2-casefold-'.bin2hex(random_bytes(8));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-casefold-'.bin2hex(random_bytes(8)));

        File::ensureDirectoryExists($this->releaseDirectory);
        File::ensureDirectoryExists($this->backupDirectory);
        config()->set('foodex.update_backup_path', $this->backupDirectory);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $archive) {
            @unlink($archive);
        }

        if (isset($this->releaseDirectory)) {
            File::deleteDirectory($this->releaseDirectory);
        }

        if (isset($this->backupDirectory)) {
            File::deleteDirectory($this->backupDirectory);
        }

        parent::tearDown();
    }

    public function test_preflight_denies_case_distinct_archive_members_without_touching_live_files(): void
    {
        file_put_contents($this->releaseDirectory.'/sentinel.txt', 'preserved');

        $package = $this->archive([
            $this->releaseName.'/Widget.php' => 'first',
            $this->releaseName.'/widget.php' => 'second',
        ]);

        $this->assertCollisionDenied(fn () => $this->runtime()->preflight($this->manifest($package), $package));

        $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/Widget.php');
        $this->assertFileDoesNotExist($this->releaseDirectory.'/widget.php');
        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
    }

    public function test_case_distinct_parent_directories_and_unicode_case_aliases_are_denied(): void
    {
        foreach ([
            [
                $this->releaseName.'/Nested/Item.php' => 'first',
                $this->releaseName.'/nested/item.php' => 'second',
            ],
            [
                $this->releaseName.'/MÜNCHEN.txt' => 'first',
                $this->releaseName.'/münchen.txt' => 'second',
            ],
        ] as $members) {
            $package = $this->archive($members);
            $this->assertCollisionDenied(fn () => $this->runtime()->preflight($this->manifest($package), $package));
        }
    }

    public function test_direct_backup_and_extraction_fail_before_mutation_on_case_aliases(): void
    {
        file_put_contents($this->releaseDirectory.'/sentinel.txt', 'preserved');
        $package = $this->archive([
            $this->releaseName.'/sentinel.txt' => 'must-not-write',
            $this->releaseName.'/Data.txt' => 'first',
            $this->releaseName.'/data.txt' => 'second',
        ]);

        $this->assertCollisionDenied(fn () => $this->runtime()->backupFiles($package));
        $this->assertCollisionDenied(fn () => $this->runtime()->extractRelease($package));

        $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/Data.txt');
        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
    }

    public function test_distinct_canonical_members_still_pass_preflight_and_existing_dotenv_guard_remains(): void
    {
        $package = $this->archive([
            $this->releaseName.'/Widget.php' => 'first',
            $this->releaseName.'/Other.php' => 'second',
        ]);

        $this->runtime()->preflight($this->manifest($package), $package);
        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);

        $protectedPackage = $this->archive(['backend/.Env.Production' => 'secret']);
        try {
            $this->runtime()->preflight($this->manifest($protectedPackage), $protectedPackage);
            $this->fail('A protected dotenv alias must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Update archive targets a protected runtime path.', $exception->getMessage());
        }
    }

    /** @param  array<string, string>  $files */
    private function archive(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-casefold-');
        if (! is_string($path)) {
            throw new RuntimeException('Failed to allocate a case-collision ZIP fixture.');
        }

        $this->archives[] = $path;
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create a case-collision ZIP fixture.');
        }

        foreach ($files as $name => $contents) {
            if (! $archive->addFromString($name, $contents)) {
                $archive->close();
                throw new RuntimeException('Failed to add a case-collision ZIP entry.');
            }
        }

        $this->assertTrue($archive->close());

        $inspection = new ZipArchive;
        $this->assertTrue($inspection->open($path) === true);
        try {
            $this->assertSame(count($files), $inspection->numFiles);
        } finally {
            $inspection->close();
        }

        return $path;
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.1',
            '99.0.0',
            (string) hash_file('sha256', $package),
            'Case-insensitive update ZIP path regression',
            false,
        );
    }

    /** @param  callable(): mixed  $operation */
    private function assertCollisionDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Case-insensitive ZIP release target collision must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Update archive contains duplicate release target paths.',
                $exception->getMessage(),
            );
        }
    }
}
