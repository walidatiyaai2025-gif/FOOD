<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class V2UpdaterSymlinkContainmentTest extends TestCase
{
    private string $fixtureName;

    private string $fixturePath;

    private string $outsideDir;

    /** @var array<int, string> */
    private array $temporaryArchives = [];

    /** @var array<int, string> */
    private array $temporaryManifests = [];

    private string $rollbackBackupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP support is needed for updater containment tests.');
        }

        $this->fixtureName = 'v2-updater-'.bin2hex(random_bytes(8));
        $this->fixturePath = dirname(base_path()).'/'.$this->fixtureName;
        $this->outsideDir = sys_get_temp_dir().'/foodex-updater-outside-'.bin2hex(random_bytes(8));

        if (! mkdir($this->outsideDir, 0700) && ! is_dir($this->outsideDir)) {
            throw new RuntimeException('Cannot create updater test fixture.');
        }

        file_put_contents($this->outsideDir.'/sentinel.txt', 'protected');

        // Keep the existing symlink regression's manifest inside the
        // canonical updater backup root so target-symlink validation is tested.
        $this->rollbackBackupRoot = sys_get_temp_dir().'/foodex-v2-trusted-rollback-'.bin2hex(random_bytes(8));
        if (! mkdir($this->rollbackBackupRoot, 0700) && ! is_dir($this->rollbackBackupRoot)) {
            throw new RuntimeException('Cannot create trusted updater test backup root.');
        }

        config()->set('foodex.update_backup_path', $this->rollbackBackupRoot);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $path) {
            @unlink($path);
        }

        foreach ($this->temporaryManifests as $path) {
            @unlink($path.'/files.json');
            @rmdir($path);
        }

        if (isset($this->rollbackBackupRoot)) {
            @rmdir($this->rollbackBackupRoot);
        }

        if (is_link($this->fixturePath)) {
            @unlink($this->fixturePath);
        } elseif (is_dir($this->fixturePath)) {
            @unlink($this->fixturePath.'/safe.txt');
            @rmdir($this->fixturePath);
        }

        if (isset($this->outsideDir)) {
            @unlink($this->outsideDir.'/sentinel.txt');
            @rmdir($this->outsideDir);
        }

        parent::tearDown();
    }

    public function test_preflight_rejects_symlinked_directory_without_writing_outside_release_root(): void
    {
        $this->linkFixtureTo($this->outsideDir);
        $package = $this->archive([$this->fixtureName.'/sentinel.txt' => 'overwritten']);

        $this->assertSymlinkRejected(fn () => $this->runtime()->preflight($this->manifest($package), $package));
        $this->assertSame('protected', file_get_contents($this->outsideDir.'/sentinel.txt'));
    }

    public function test_backup_rejects_symlinked_directory_before_reading_outside_release_root(): void
    {
        $this->linkFixtureTo($this->outsideDir);
        $package = $this->archive([$this->fixtureName.'/sentinel.txt' => 'overwritten']);

        $this->assertSymlinkRejected(fn () => $this->runtime()->backupFiles($package));
        $this->assertSame('protected', file_get_contents($this->outsideDir.'/sentinel.txt'));
    }

    public function test_extraction_rechecks_symlinked_directory_even_without_preflight(): void
    {
        $this->linkFixtureTo($this->outsideDir);
        $package = $this->archive([$this->fixtureName.'/sentinel.txt' => 'overwritten']);

        $this->assertSymlinkRejected(fn () => $this->runtime()->extractRelease($package));
        $this->assertSame('protected', file_get_contents($this->outsideDir.'/sentinel.txt'));
    }

    public function test_rollback_does_not_follow_a_symlinked_target_from_its_manifest(): void
    {
        $this->linkFixtureTo($this->outsideDir);
        $backup = $this->rollbackBackupRoot.'/foodex-updater-manifest-'.bin2hex(random_bytes(8));
        mkdir($backup, 0700);
        $this->temporaryManifests[] = $backup;

        file_put_contents($backup.'/files.json', json_encode([
            'targets' => [$this->fixtureName.'/sentinel.txt'],
            'existing' => [],
        ], JSON_THROW_ON_ERROR));

        $this->assertSymlinkRejected(fn () => $this->runtime()->rollbackFiles($backup));
        $this->assertSame('protected', file_get_contents($this->outsideDir.'/sentinel.txt'));
    }

    public function test_dangling_symlink_target_is_rejected(): void
    {
        $this->linkFixtureTo($this->outsideDir.'/missing-target');
        $package = $this->archive([$this->fixtureName => 'overwritten']);

        $this->assertSymlinkRejected(fn () => $this->runtime()->preflight($this->manifest($package), $package));
    }

    public function test_unix_symlink_archive_member_is_rejected_before_activation(): void
    {
        $entry = $this->fixtureName.'/link.php';
        $package = $this->archive([$entry => 'sentinel.txt'], $entry);

        $this->assertSymlinkRejected(fn () => $this->runtime()->preflight($this->manifest($package), $package));
        $this->assertFileDoesNotExist($this->fixturePath.'/link.php');
    }

    public function test_regular_zip_without_links_still_installs_within_release_root(): void
    {
        $package = $this->archive([$this->fixtureName.'/safe.txt' => 'safe release data']);

        $this->runtime()->preflight($this->manifest($package), $package);
        $this->runtime()->extractRelease($package);

        $this->assertSame('safe release data', file_get_contents($this->fixturePath.'/safe.txt'));
        $this->assertSame('protected', file_get_contents($this->outsideDir.'/sentinel.txt'));
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    private function linkFixtureTo(string $target): void
    {
        if (! function_exists('symlink') || ! @symlink($target, $this->fixturePath)) {
            $this->markTestSkipped('Filesystem symlinks are unavailable on this runner.');
        }
    }

    /** @param array<string, string> $files */
    private function archive(array $files, ?string $symlinkEntry = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-updater-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate updater test archive.');
        }

        $this->temporaryArchives[] = $path;
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot open updater test archive.');
        }

        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }

        if ($symlinkEntry !== null) {
            $archive->setExternalAttributesName($symlinkEntry, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }

        $archive->close();

        return $path;
    }

    private function manifest(string $package): UpdatePackageManifest
    {
        return new UpdatePackageManifest('99.0.0', '1.0.0', (string) hash_file('sha256', $package), 'Symlink safety', false);
    }

    /** @param callable():mixed $operation */
    private function assertSymlinkRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An update must never traverse a symbolic link.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        }
    }
}
