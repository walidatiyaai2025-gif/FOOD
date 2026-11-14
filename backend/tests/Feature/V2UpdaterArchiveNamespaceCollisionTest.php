<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterArchiveNamespaceCollisionTest extends TestCase
{
    private string $releaseDirectory;

    private string $releaseName;

    private string $backupDirectory;

    /** @var list<string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for updater namespace tests.');
        }

        $this->releaseName = 'v2-zip-namespace-'.bin2hex(random_bytes(8));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-zip-namespace-'.bin2hex(random_bytes(8)));

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

    public function test_case_insensitive_twin_targets_are_rejected_before_any_file_mutation(): void
    {
        $this->assertArchiveRejected([
            $this->releaseName.'/sentinel.txt' => 'should not overwrite',
            $this->releaseName.'/Config.php' => 'first',
            $this->releaseName.'/config.php' => 'second',
        ], 'Update archive contains duplicate release target paths.');
    }

    public function test_file_parent_before_child_is_rejected_before_any_file_mutation(): void
    {
        $this->assertArchiveRejected([
            $this->releaseName.'/sentinel.txt' => 'should not overwrite',
            $this->releaseName.'/module' => 'a file, not a directory',
            $this->releaseName.'/module/Feature.php' => 'illegal child',
        ], 'Update archive contains conflicting file and directory targets.');
    }

    public function test_file_child_before_parent_is_rejected_before_any_file_mutation(): void
    {
        $this->assertArchiveRejected([
            $this->releaseName.'/sentinel.txt' => 'should not overwrite',
            $this->releaseName.'/module/Feature.php' => 'illegal child',
            $this->releaseName.'/module' => 'a file, not a directory',
        ], 'Update archive contains conflicting file and directory targets.');
    }

    public function test_case_alias_file_parent_is_rejected_before_any_file_mutation(): void
    {
        $this->assertArchiveRejected([
            $this->releaseName.'/sentinel.txt' => 'should not overwrite',
            $this->releaseName.'/Module' => 'a file, not a directory',
            $this->releaseName.'/module/Feature.php' => 'illegal child',
        ], 'Update archive contains conflicting file and directory targets.');
    }

    public function test_distinct_canonical_release_paths_still_extract_and_rollback_safely(): void
    {
        file_put_contents($this->releaseDirectory.'/sentinel.txt', 'preserved');
        $package = $this->archive([
            $this->releaseName.'/sentinel.txt' => 'updated',
            $this->releaseName.'/admin/Config.php' => 'admin config',
            $this->releaseName.'/mobile/config.php' => 'mobile config',
        ]);
        $runtime = $this->runtime();

        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $this->assertFileExists($backup.'/files.json');

        $runtime->extractRelease($package);
        $this->assertSame('updated', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertSame('admin config', file_get_contents($this->releaseDirectory.'/admin/Config.php'));
        $this->assertSame('mobile config', file_get_contents($this->releaseDirectory.'/mobile/config.php'));

        $runtime->rollbackFiles($backup);
        $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/admin/Config.php');
        $this->assertFileDoesNotExist($this->releaseDirectory.'/mobile/config.php');
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertArchiveRejected(array $files, string $expectedError): void
    {
        file_put_contents($this->releaseDirectory.'/sentinel.txt', 'preserved');
        $package = $this->archive($files);
        $runtime = $this->runtime();

        foreach (['preflight', 'backupFiles', 'extractRelease'] as $operation) {
            try {
                if ($operation === 'preflight') {
                    $runtime->preflight($this->manifest($package), $package);
                } else {
                    $runtime->{$operation}($package);
                }

                $this->fail('ZIP ambiguity must fail before '.$operation.'.');
            } catch (RuntimeException $exception) {
                $this->assertSame($expectedError, $exception->getMessage());
            }

            $this->assertSame('preserved', file_get_contents($this->releaseDirectory.'/sentinel.txt'));
            $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
            $this->assertFileDoesNotExist($this->releaseDirectory.'/Config.php');
            $this->assertFileDoesNotExist($this->releaseDirectory.'/config.php');
            $this->assertFileDoesNotExist($this->releaseDirectory.'/module');
            $this->assertFileDoesNotExist($this->releaseDirectory.'/Module');
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function archive(array $files): string
    {
        $package = tempnam(sys_get_temp_dir(), 'foodex-v2-ns-');
        if (! is_string($package)) {
            throw new RuntimeException('Could not allocate ZIP namespace fixture.');
        }

        $this->archives[] = $package;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }

        $this->assertTrue($zip->close());

        $inspection = new ZipArchive;
        $this->assertTrue($inspection->open($package) === true);
        try {
            $this->assertSame(count($files), $inspection->numFiles);
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
            'ZIP namespace conflict test',
            false,
        );
    }
}
