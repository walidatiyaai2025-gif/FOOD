<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterNoncanonicalZipMemberPreflightTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupDirectory;

    /** @var array<int, string> */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP support is required for updater preflight tests.');
        }

        $this->releaseName = 'v2-zip-member-'.bin2hex(random_bytes(7));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-zip-member-'.bin2hex(random_bytes(7)));

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

    public function test_single_noncanonical_member_is_rejected_during_preflight(): void
    {
        foreach ([
            ' '.$this->releaseName.'/target.txt',
            $this->releaseName.'/target.txt ',
            $this->releaseName.'\\target.txt',
            "\t".$this->releaseName.'/target.txt',
        ] as $rawName) {
            $package = $this->archive([$rawName => 'synthetic payload']);
            $this->assertNoncanonicalRejected(
                fn () => $this->runtime()->preflight($this->manifest($package), $package),
            );

            $this->assertFileDoesNotExist($this->releaseDirectory.'/target.txt');
            $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
        }
    }

    public function test_backup_and_extract_refuse_noncanonical_name_before_touching_valid_first_target(): void
    {
        $existing = $this->releaseDirectory.'/first.txt';
        file_put_contents($existing, 'preserved');

        $package = $this->archive([
            $this->releaseName.'/first.txt' => 'must not install',
            ' '.$this->releaseName.'/second.txt' => 'bad member',
        ]);

        $this->assertNoncanonicalRejected(fn () => $this->runtime()->backupFiles($package));
        $this->assertNoncanonicalRejected(fn () => $this->runtime()->extractRelease($package));

        $this->assertSame([], glob($this->backupDirectory.'/*') ?: []);
        $this->assertSame('preserved', file_get_contents($existing));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/second.txt');
    }

    public function test_canonical_zip_entry_still_supports_backup_extract_and_rollback(): void
    {
        file_put_contents($this->releaseDirectory.'/first.txt', 'old');
        $package = $this->archive([
            $this->releaseName.'/first.txt' => 'new',
            $this->releaseName.'/second.txt' => 'added',
        ]);

        $runtime = $this->runtime();
        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $runtime->extractRelease($package);

        $this->assertSame('new', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertSame('added', file_get_contents($this->releaseDirectory.'/second.txt'));

        $runtime->rollbackFiles($backup);
        $this->assertSame('old', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/second.txt');
    }

    /**
     * @param  array<string, string>  $members
     */
    private function archive(array $members): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-noncanonical-zip-');
        if (! is_string($path)) {
            throw new RuntimeException('Could not create a synthetic ZIP.');
        }

        $this->archives[] = $path;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open a synthetic ZIP.');
        }

        foreach ($members as $name => $payload) {
            if (! $zip->addFromString($name, $payload)) {
                $zip->close();
                throw new RuntimeException('Could not add a synthetic ZIP member.');
            }
        }

        $this->assertTrue($zip->close());

        $verify = new ZipArchive;
        $this->assertTrue($verify->open($path) === true);
        try {
            $this->assertSame(array_keys($members), array_map(
                fn (int $index): string|false => $verify->getNameIndex($index),
                range(0, $verify->numFiles - 1),
            ));
        } finally {
            $verify->close();
        }

        return $path;
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    private function manifest(string $path): UpdatePackageManifest
    {
        return new UpdatePackageManifest(
            '99.0.0',
            '1.0.0',
            (string) hash_file('sha256', $path),
            'Noncanonical ZIP member preflight regression',
            false,
        );
    }

    /** @param  callable(): mixed  $operation */
    private function assertNoncanonicalRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A single noncanonical ZIP member must fail before updater mutations.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Update archive contains a non-canonical path.', $exception->getMessage());
        }
    }
}
