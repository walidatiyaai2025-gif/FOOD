<?php

namespace Tests\Feature;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdatePackageManifest;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterStaticPublicAssetScriptGuardTest extends TestCase
{
    private string $slug;

    private string $backupRoot;

    /** @var array<int, string> */
    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for executable public asset preflight tests.');
        }

        $this->slug = 'v2-static-zip-'.bin2hex(random_bytes(7));
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
            foreach (['assets', 'brand', 'demo', 'preview'] as $root) {
                File::deleteDirectory(dirname(base_path()).'/'.$root.'/'.$this->slug);
            }
        }

        if (isset($this->backupRoot)) {
            File::deleteDirectory($this->backupRoot);
        }

        parent::tearDown();
    }

    public function test_single_executable_or_server_config_member_is_rejected_at_preflight(): void
    {
        foreach ([
            'assets/'.$this->slug.'/bad.php',
            'assets/'.$this->slug.'/bad.PHP.jpg',
            'brand/'.$this->slug.'/bad.PHP7',
            'demo/'.$this->slug.'/run.phtml',
            'preview/'.$this->slug.'/payload.phar',
            'brand/'.$this->slug.'/script.cgi',
            'preview/'.$this->slug.'/.htaccess',
            'assets/'.$this->slug.'/web.config',
            'demo/'.$this->slug.'/.user.ini',
        ] as $name) {
            $package = $this->package([$name => 'synthetic forbidden content']);

            $this->assertRejected(
                fn (): mixed => $this->runtime()->preflight($this->manifest($package), $package),
            );

            $this->assertFileDoesNotExist(dirname(base_path()).'/'.$name);
            $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
        }
    }

    public function test_mixed_zip_cannot_backup_or_install_valid_first_file_before_rejecting_script(): void
    {
        $folder = dirname(base_path()).'/assets/'.$this->slug;
        File::ensureDirectoryExists($folder);
        $sentinel = $folder.'/first.css';
        $this->assertNotFalse(file_put_contents($sentinel, 'original'));

        $package = $this->package([
            'assets/'.$this->slug.'/first.css' => 'should not replace',
            'brand/'.$this->slug.'/payload.php.png' => 'synthetic forbidden content',
        ]);

        $this->assertRejected(fn (): mixed => $this->runtime()->backupFiles($package));
        $this->assertRejected(fn (): mixed => $this->runtime()->extractRelease($package));

        $this->assertSame('original', file_get_contents($sentinel));
        $this->assertFileDoesNotExist(dirname(base_path()).'/brand/'.$this->slug.'/payload.php.png');
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    public function test_static_css_js_svg_png_files_still_install_and_roll_back(): void
    {
        $folder = dirname(base_path()).'/assets/'.$this->slug;
        File::ensureDirectoryExists($folder);
        $this->assertNotFalse(file_put_contents($folder.'/style.css', 'old stylesheet'));

        $package = $this->package([
            'assets/'.$this->slug.'/style.css' => 'new stylesheet',
            'brand/'.$this->slug.'/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'demo/'.$this->slug.'/main.js' => 'const version = 2;',
            'preview/'.$this->slug.'/thumb.png' => 'synthetic png fixture',
        ]);

        $runtime = $this->runtime();
        $runtime->preflight($this->manifest($package), $package);
        $backup = $runtime->backupFiles($package);
        $runtime->extractRelease($package);

        $this->assertSame('new stylesheet', file_get_contents($folder.'/style.css'));
        $this->assertSame('const version = 2;', file_get_contents(dirname(base_path()).'/demo/'.$this->slug.'/main.js'));

        $runtime->rollbackFiles($backup);

        $this->assertSame('old stylesheet', file_get_contents($folder.'/style.css'));
        foreach (['brand/'.$this->slug.'/logo.svg', 'demo/'.$this->slug.'/main.js', 'preview/'.$this->slug.'/thumb.png'] as $name) {
            $this->assertFileDoesNotExist(dirname(base_path()).'/'.$name);
        }
    }

    public function test_laravel_public_bootstrap_entries_are_not_misclassified_as_static_mirror_assets(): void
    {
        $package = $this->package(['backend/public/index.php' => '<?php // synthetic fixture']);

        $this->runtime()->preflight($this->manifest($package), $package);

        // A validation-only control: never activate or overwrite the real Laravel entrypoint.
        $this->assertSame([], glob($this->backupRoot.'/*') ?: []);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-static-public-');
        if ($path === false) {
            throw new RuntimeException('Could not allocate synthetic ZIP.');
        }

        $this->packages[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
        }

        $this->assertTrue($zip->close());

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
            'Synthetic public assets restriction test',
            false,
        );
    }

    /** @param  callable(): mixed  $callback */
    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Executable or server-configuration public asset entries must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Update archive targets an executable public asset path.', $exception->getMessage());
        }
    }
}
