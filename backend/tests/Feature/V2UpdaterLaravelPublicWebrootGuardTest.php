<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterLaravelPublicWebrootGuardTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for update public webroot tests.');
        }

        $this->slug = 'v2-public-zip-'.bin2hex(random_bytes(7));
        $this->backupRoot = storage_path('framework/testing/'.$this->slug.'-backups');
        File::ensureDirectoryExists($this->backupRoot);

        config(['foodex.update_backup_path' => $this->backupRoot]);
    }

    protected function tearDown(): void
    {
        foreach ($this->packages as $package) {
            @unlink($package);
        }

        if (isset($this->slug)) {
            File::deleteDirectory(public_path($this->slug));
        }

        if (isset($this->backupRoot)) {
            File::deleteDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_server_executable_zip_members_in_laravel_webroot_fail_closed_before_any_file_change(): void
    {
        foreach ([
            'backend/public/'.$this->slug.'/shell.php',
            'backend/public/'.$this->slug.'/run.PHP.jpg',
            'backend/public/'.$this->slug.'/invoke.PHP8',
            'backend/public/'.$this->slug.'/nested/payload.phtml',
            'backend/public/'.$this->slug.'/nested/payload.phar',
            'backend/public/'.$this->slug.'/nested/job.cgi',
            'backend/public/'.$this->slug.'/nested/helper.py',
            'backend/public/'.$this->slug.'/.htaccess',
            'backend/public/'.$this->slug.'/nested/.user.ini',
            'backend/public/'.$this->slug.'/nested/web.config',
            'backend/public/assets/'.$this->slug.'/bad.php.jpg',
            'backend/public/INDEX.PHP',
            'backend/public/.HTACCESS',
            'BACKEND/PUBLIC/'.$this->slug.'/handler.php',
        ] as $entry) {
            $archive = $this->package([$entry => 'synthetic denied content']);

            $this->assertRejected(fn (): mixed => $this->runtime()->preflight($this->manifest($archive), $archive));
            $this->assertRejected(fn (): mixed => $this->runtime()->backupFiles($archive));
            $this->assertRejected(fn (): mixed => $this->runtime()->extractRelease($archive));

            $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
            if (str_contains($entry, $this->slug)) {
                $this->assertFileDoesNotExist(dirname(base_path()).'/'.$entry);
            }
        }
    }

    public function test_rejects_mixed_archive_before_overwriting_an_existing_public_asset(): void
    {
        $folder = public_path($this->slug);
        File::ensureDirectoryExists($folder);
        $sentinel = $folder.'/existing.css';

        $this->assertNotFalse(file_put_contents($sentinel, 'original stylesheet'));

        $archive = $this->package([
            'backend/public/'.$this->slug.'/existing.css' => 'overwritten stylesheet',
            'backend/public/'.$this->slug.'/override.php' => 'synthetic denied content',
        ]);

        $this->assertRejected(fn (): mixed => $this->runtime()->preflight($this->manifest($archive), $archive));
        $this->assertRejected(fn (): mixed => $this->runtime()->backupFiles($archive));
        $this->assertRejected(fn (): mixed => $this->runtime()->extractRelease($archive));

        $this->assertSame('original stylesheet', file_get_contents($sentinel));
        $this->assertFileDoesNotExist($folder.'/override.php');
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    public function test_canonical_laravel_bootstrap_and_benign_public_assets_are_accepted_at_preflight(): void
    {
        $archive = $this->package([
            'backend/public/index.php' => '<?php // synthetic bootstrap; never installed',
            'backend/public/.htaccess' => '# synthetic rewrite; never installed',
            'backend/public/'.$this->slug.'/main.css' => 'body { color: black; }',
            'backend/public/'.$this->slug.'/app.js' => 'const ok = true;',
            'backend/public/'.$this->slug.'/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ]);

        $this->runtime()->preflight($this->manifest($archive), $archive);

        // This test must never overwrite Laravel's actual front controller or .htaccess.
        $this->assertDirectoryDoesNotExist(public_path($this->slug));
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    /** @param array<string, string> $entries */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v2-public-webroot-');
        if ($path === false) {
            throw new RuntimeException('Cannot allocate synthetic update ZIP.');
        }

        $this->packages[] = $path;

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $value) {
            $this->assertTrue($archive->addFromString($name, $value));
        }

        $this->assertTrue($archive->close());

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
            'Synthetic Laravel public webroot archive safety test',
            false,
        );
    }

    /** @param callable(): mixed $callback */
    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Unexpected executable webroot entry was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Update archive targets an executable public asset path.', $exception->getMessage());
        }
    }
}
