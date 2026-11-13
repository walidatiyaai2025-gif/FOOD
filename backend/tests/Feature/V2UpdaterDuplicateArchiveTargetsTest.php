<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterDuplicateArchiveTargetsTest extends TestCase
{
    private string $releaseDirectory;

    private string $releaseName;

    private string $backupDirectory;

    /** @var array<int, string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required to validate updater archive ambiguity.');
        }

        $this->releaseName = 'v2-zip-duplicate-'.bin2hex(random_bytes(8));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-zip-duplicate-'.bin2hex(random_bytes(8)));

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

    public function test_preflight_rejects_conflicting_alias_entries_before_any_live_file_change(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'preserved');
        $package = $this->duplicateTargetArchive();

        $this->assertDuplicateRejected(fn () => $this->runtime()->preflight($this->manifest($package), $package));

        $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/target.txt');
        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
    }

    public function test_direct_backup_rejects_alias_entries_without_creating_backup_files(): void
    {
        $package = $this->duplicateTargetArchive();

        $this->assertDuplicateRejected(fn () => $this->runtime()->backupFiles($package));

        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
        $this->assertFileDoesNotExist($this->releaseDirectory.'/target.txt');
    }

    public function test_direct_extraction_rejects_all_aliases_before_activating_first_entry(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'preserved');
        $package = $this->duplicateTargetArchive();

        $this->assertDuplicateRejected(fn () => $this->runtime()->extractRelease($package));

        // Extraction must inspect the entire manifest before the first write,
        // even when the ambiguous target comes after a valid existing file.
        $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/target.txt');
    }

    public function test_two_distinct_canonical_entries_install_and_can_be_rolled_back(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'original');
        $package = $this->archive([
            $this->releaseName.'/first.txt' => 'upgraded',
            $this->releaseName.'/second.txt' => 'new',
        ]);
        $runtime = $this->runtime();

        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $this->assertFileExists($backup.'/files.json');

        $runtime->extractRelease($package);
        $this->assertSame('upgraded', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertSame('new', file_get_contents($this->releaseDirectory.'/second.txt'));

        $runtime->rollbackFiles($backup);
        $this->assertSame('original', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/second.txt');
    }

    public function test_same_canonical_relative_target_cannot_be_supplied_through_slash_alias(): void
    {
        $package = $this->archive([
            $this->releaseName.'/target.php' => 'canonical',
            $this->releaseName.'\\target.php' => 'aliased',
        ]);

        $this->assertDuplicateRejected(fn () => $this->runtime()->preflight($this->manifest($package), $package));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/target.php');
    }

    private function duplicateTargetArchive(): string
    {
        return $this->archive([
            $this->releaseName.'/first.txt' => 'must not install',
            $this->releaseName.'/target.txt' => 'canonical',
            $this->releaseName.'\\target.txt' => 'ambiguous',
        ]);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function archive(array $files): string
    {
        $package = tempnam(sys_get_temp_dir(), 'foodex-v2-dup-');
        if (! is_string($package)) {
            throw new RuntimeException('Unable to allocate V2 ZIP test fixture.');
        }

        $this->archives[] = $package;
        $archive = new ZipArchive;
        if ($archive->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to prepare V2 ZIP test fixture.');
        }

        foreach ($files as $name => $contents) {
            if (! $archive->addFromString($name, $contents)) {
                $archive->close();
                throw new RuntimeException('Unable to add archive member to V2 ZIP fixture.');
            }
        }

        $archive->close();

        $inspection = new ZipArchive;
        if ($inspection->open($package) !== true) {
            throw new RuntimeException('Unable to inspect V2 ZIP test fixture.');
        }
        try {
            $this->assertSame(
                count($files),
                $inspection->numFiles,
                'Archive fixture must retain all distinct raw member names.',
            );
        } finally {
            $inspection->close();
        }

        return $package;
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.0',
            '1.0.0',
            (string) hash_file('sha256', $package),
            'Duplicate member rejection test',
            false,
        );
    }

    /** @param  callable(): mixed  $operation */
    private function assertDuplicateRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Ambiguous normalized ZIP archive members must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Update archive contains duplicate release target paths.',
                $exception->getMessage(),
            );
        }
    }
}
