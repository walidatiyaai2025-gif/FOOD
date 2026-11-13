<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class V2UpdaterRollbackPreflightTest extends TestCase
{
    private string $releaseName;

    private string $releaseDirectory;

    private string $backupDirectory;

    private ?string $outsideSentinel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseName = 'v2-rollback-'.bin2hex(random_bytes(8));
        $this->releaseDirectory = dirname(base_path()).'/'.$this->releaseName;
        $this->backupDirectory = storage_path('framework/testing/v2-rollback-backup-'.bin2hex(random_bytes(8)));

        File::ensureDirectoryExists($this->releaseDirectory);
        File::ensureDirectoryExists($this->backupDirectory);

        // Production restores only accept backups inside the configured root.
        // Keep these real-filesystem rollback fixtures inside that same guard.
        config()->set('foodex.update_backup_path', dirname($this->backupDirectory));
    }

    protected function tearDown(): void
    {
        if (isset($this->backupDirectory)) {
            $linkedSource = $this->backupDirectory.'/existing/'.$this->releaseName.'/original.txt';
            if (is_link($linkedSource)) {
                @unlink($linkedSource);
            }

            File::deleteDirectory($this->backupDirectory);
        }

        if (isset($this->releaseDirectory)) {
            File::deleteDirectory($this->releaseDirectory);
        }

        if ($this->outsideSentinel !== null) {
            @unlink($this->outsideSentinel);
        }

        parent::tearDown();
    }

    public function test_missing_second_original_backup_fails_before_removing_first_target(): void
    {
        $first = $this->relative('first.txt');
        $second = $this->relative('second.txt');
        $this->writeLive('first.txt', 'first-upgraded');
        $this->writeLive('second.txt', 'second-upgraded');
        $this->writeManifest([$first, $second], [$first, $second], [$first => 'first-original']);

        $this->assertRejects(fn () => $this->runtime()->rollbackFiles($this->backupDirectory));

        $this->assertSame('first-upgraded', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertSame('second-upgraded', file_get_contents($this->releaseDirectory.'/second.txt'));
    }

    public function test_existing_entry_not_listed_among_targets_is_rejected_without_deletion(): void
    {
        $target = $this->relative('first.txt');
        $unrelated = $this->relative('unrelated.txt');
        $this->writeLive('first.txt', 'still-upgraded');
        $this->writeManifest([$target], [$unrelated], [$unrelated => 'unrelated-original']);

        $this->assertRejects(fn () => $this->runtime()->rollbackFiles($this->backupDirectory));

        $this->assertSame('still-upgraded', file_get_contents($this->releaseDirectory.'/first.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/unrelated.txt');
    }

    public function test_linked_backup_source_is_rejected_without_reading_foreign_file_or_mutation(): void
    {
        $target = $this->relative('original.txt');
        $this->writeLive('original.txt', 'still-upgraded');
        $this->writeManifest([$target], [$target]);

        $this->outsideSentinel = sys_get_temp_dir().'/foodex-rollback-sentinel-'.bin2hex(random_bytes(8));
        file_put_contents($this->outsideSentinel, 'foreign-private-content');

        $source = $this->backupDirectory.'/existing/'.$target;
        File::ensureDirectoryExists(dirname($source));
        if (! function_exists('symlink') || ! @symlink($this->outsideSentinel, $source)) {
            $this->markTestSkipped('Symlink support is unavailable in this test environment.');
        }

        $this->assertRejects(fn () => $this->runtime()->rollbackFiles($this->backupDirectory));

        $this->assertSame('still-upgraded', file_get_contents($this->releaseDirectory.'/original.txt'));
        $this->assertSame('foreign-private-content', file_get_contents($this->outsideSentinel));
    }

    public function test_duplicate_manifest_targets_are_rejected_before_any_mutation(): void
    {
        $target = $this->relative('first.txt');
        $this->writeLive('first.txt', 'still-upgraded');
        $this->writeManifest([$target, $target], [$target], [$target => 'original']);

        $this->assertRejects(fn () => $this->runtime()->rollbackFiles($this->backupDirectory));

        $this->assertSame('still-upgraded', file_get_contents($this->releaseDirectory.'/first.txt'));

        // A malformed list must not be silently reduced to an empty set.
        file_put_contents($this->backupDirectory.'/files.json', json_encode([
            'targets' => [$target],
            'existing' => 'invalid-list',
        ], JSON_THROW_ON_ERROR));

        $this->assertRejects(fn () => $this->runtime()->rollbackFiles($this->backupDirectory));
        $this->assertSame('still-upgraded', file_get_contents($this->releaseDirectory.'/first.txt'));
    }

    public function test_complete_backup_restores_existing_and_removes_new_files(): void
    {
        $old = $this->relative('old.txt');
        $empty = $this->relative('empty.txt');
        $created = $this->relative('created.txt');

        $this->writeLive('old.txt', 'upgraded');
        $this->writeLive('empty.txt', 'upgraded-empty-file');
        $this->writeLive('created.txt', 'new');
        $this->writeManifest(
            [$old, $empty, $created],
            [$old, $empty],
            [$old => 'original', $empty => ''],
        );

        $this->runtime()->rollbackFiles($this->backupDirectory);

        $this->assertSame('original', file_get_contents($this->releaseDirectory.'/old.txt'));
        $this->assertSame('', file_get_contents($this->releaseDirectory.'/empty.txt'));
        $this->assertFileDoesNotExist($this->releaseDirectory.'/created.txt');
    }

    private function relative(string $name): string
    {
        return $this->releaseName.'/'.$name;
    }

    private function writeLive(string $name, string $contents): void
    {
        file_put_contents($this->releaseDirectory.'/'.$name, $contents);
    }

    /**
     * @param  array<int, string>  $targets
     * @param  array<int, string>  $existing
     * @param  array<string, string>  $backupContents
     */
    private function writeManifest(array $targets, array $existing, array $backupContents = []): void
    {
        foreach ($backupContents as $relativePath => $contents) {
            $source = $this->backupDirectory.'/existing/'.$relativePath;
            File::ensureDirectoryExists(dirname($source));
            file_put_contents($source, $contents);
        }

        file_put_contents($this->backupDirectory.'/files.json', json_encode([
            'targets' => $targets,
            'existing' => $existing,
        ], JSON_THROW_ON_ERROR));
    }

    private function runtime(): LaravelUpdateRuntime
    {
        return app(LaravelUpdateRuntime::class);
    }

    /** @param  callable(): mixed  $operation */
    private function assertRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Incomplete, aliased or unsafe rollback backups must fail before live mutation.');
        } catch (RuntimeException $exception) {
            $this->assertContains($exception->getMessage(), [
                'File rollback backup is incomplete or unsafe.',
                'File rollback manifest is invalid.',
            ]);
        }
    }
}
