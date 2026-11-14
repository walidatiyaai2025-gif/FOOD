<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SystemUpdateController;
use App\Models\Role;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2SystemUpdateControllerZipBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for real ZIP budget coverage.');
        }

        $this->seed(CoreReferenceSeeder::class);
        config()->set('foodex.update_max_zip_members', 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_over_budget_zip_is_rejected_before_history_backup_or_maintenance_and_upload_is_removed(): void
    {
        $path = $this->archive([
            'assets/' => null,
            'assets/first.css' => '',
            'assets/second.js' => '',
            'assets/third.png' => '',
        ]);
        $before = $this->incomingFiles();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.system-update.store'), $this->uploadPayload($path))
            ->assertRedirect(route('admin.system-update.index'))
            ->assertSessionHasErrors('update');

        $this->assertSame($before, $this->incomingFiles());
        $this->assertSame(0, UpdateHistory::query()->count());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_corrupt_zip_is_rejected_and_cleaned_without_creating_history(): void
    {
        $path = $this->temporaryPath();
        file_put_contents($path, 'not a zip, even with a matching supplied digest');
        $before = $this->incomingFiles();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.system-update.store'), $this->uploadPayload($path))
            ->assertRedirect(route('admin.system-update.index'))
            ->assertSessionHasErrors('update');

        $this->assertSame($before, $this->incomingFiles());
        $this->assertSame(0, UpdateHistory::query()->count());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_exact_member_boundary_and_canonical_migration_alias_are_detected(): void
    {
        $path = $this->archive([
            'backend/public/styles.css' => 'body{}',
            'backend/public/app.js' => 'void 0;',
            'backend/public/logo.svg' => '<svg/>',
        ]);

        $this->assertFalse($this->scan($path));

        $migration = $this->archive([
            '  backend\\database\\migrations\\2026_11_14_000000_probe.PHP  ' => '<?php // isolated fixture',
        ]);
        config()->set('foodex.update_max_zip_members', 1);

        $this->assertTrue($this->scan($migration));
    }

    public function test_nonpositive_configuration_still_rejects_a_two_member_zip(): void
    {
        $path = $this->archive([
            'first.txt' => '',
            'second.txt' => '',
        ]);

        foreach ([0, -10] as $configured) {
            config()->set('foodex.update_max_zip_members', $configured);

            try {
                $this->scan($path);
                $this->fail('The ZIP scan must clamp the member budget to at least one.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    __('admin.system_update_surface.not_applied'),
                    $exception->getMessage(),
                );
            }
        }
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'ZIP Preflight Owner',
            'email' => 'zip-budget-owner@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    /** @return array<string,mixed> */
    private function uploadPayload(string $path): array
    {
        return [
            'package' => new UploadedFile($path, 'update.zip', 'application/zip', null, true),
            'target_version' => '99.0.0',
            'minimum_current_version' => '1.0.0',
            'sha256' => (string) hash_file('sha256', $path),
            'release_notes' => 'Synthetic ZIP prevalidation bound',
            'contains_migrations' => false,
        ];
    }

    private function scan(string $path): bool
    {
        $method = new ReflectionMethod(SystemUpdateController::class, 'packageContainsMigrations');

        return (bool) $method->invoke(app(SystemUpdateController::class), $path);
    }

    /** @param array<string, string|null> $members */
    private function archive(array $members): string
    {
        $path = $this->temporaryPath();
        $archive = new ZipArchive;

        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($members as $name => $content) {
            $this->assertTrue(
                $content === null
                    ? $archive->addEmptyDir($name)
                    : $archive->addFromString($name, $content),
            );
        }

        $this->assertTrue($archive->close());

        return $path;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-zip-budget-');
        if ($path === false) {
            throw new RuntimeException('Could not create an isolated ZIP fixture.');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function incomingFiles(): array
    {
        $files = glob(storage_path('app/private/updates/incoming/update-*.zip')) ?: [];
        sort($files);

        return $files;
    }
}
