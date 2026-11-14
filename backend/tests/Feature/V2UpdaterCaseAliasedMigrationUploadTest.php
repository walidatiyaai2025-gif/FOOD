<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SystemUpdateController;
use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class V2UpdaterCaseAliasedMigrationUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension is required for update migration upload tests.');
        }

        $this->seed(CoreReferenceSeeder::class);
        SystemVersion::query()->create([
            'version' => '1.1.0',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_dashboard_detector_rejects_case_aliased_migrations_and_retains_nonmigration_uploads(): void
    {
        foreach ([
            'Backend/Database/Migrations/2026_11_14_000001_probe.PHP',
            " backend\\DATABASE\\Migrations\\2026_11_14_000002_probe.Php \t",
            'BACKEND/database/MIGRATIONS/2026_11_14_000003_probe.php',
        ] as $entry) {
            $this->assertTrue($this->scan($this->archive([$entry])), $entry);
        }

        $this->assertFalse($this->scan($this->archive([
            'backend/database/migration_templates/reference.PHP',
            'backend/app/ReadOnlyProbe.php',
        ])));
    }

    public function test_authenticated_upload_records_case_aliased_migration_flag_before_checksum_denial_without_live_mutation(): void
    {
        $path = $this->archive(['Backend/Database/Migrations/2026_11_14_000001_probe.PHP']);
        $beforeFiles = $this->incomingFiles();

        // The deliberately incorrect SHA stops UpdateManager at validate_package:
        // no maintenance, backup, extraction or database migration may run.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.system-update.store'), [
                'package' => new UploadedFile($path, 'update.zip', 'application/zip', null, true),
                'target_version' => '1.2.0',
                'minimum_current_version' => '1.0.0',
                'sha256' => str_repeat('0', 64),
                'release_notes' => 'Synthetic alias detection before checksum denial',
                'contains_migrations' => false,
            ])
            ->assertRedirect(route('admin.system-update.index'))
            ->assertSessionHasErrors('update');

        $history = UpdateHistory::query()->sole();
        $this->assertSame('failed', $history->status);
        $this->assertSame('validate_package', $history->failed_stage);
        $this->assertSame('pending', $history->execution_evidence['migrations']);
        $this->assertSame('not_created', $history->backup_status);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertSame($beforeFiles, $this->incomingFiles());
        $this->assertSame(0, SystemVersion::query()->where('version', '1.2.0')->count());
    }

    public function test_unauthenticated_upload_does_not_enter_migration_scan_or_history(): void
    {
        $path = $this->archive(['Backend/Database/Migrations/2026_11_14_000001_probe.PHP']);

        $response = $this->post(route('admin.system-update.store'), [
            'package' => new UploadedFile($path, 'update.zip', 'application/zip', null, true),
            'target_version' => '1.2.0',
            'minimum_current_version' => '1.0.0',
            'sha256' => (string) hash_file('sha256', $path),
            'contains_migrations' => false,
        ]);

        $this->assertContains($response->status(), [302, 401, 403]);
        $this->assertSame(0, UpdateHistory::query()->count());
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Case Alias Test Owner',
            'email' => 'zip-case-alias-owner@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function scan(string $path): bool
    {
        $method = new ReflectionMethod(SystemUpdateController::class, 'packageContainsMigrations');

        return (bool) $method->invoke(app(SystemUpdateController::class), $path);
    }

    /** @param list<string> $entries */
    private function archive(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-case-alias-upload-');
        if ($path === false) {
            throw new RuntimeException('Could not create ZIP fixture.');
        }

        $this->temporaryPaths[] = $path;
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);

        foreach ($entries as $entry) {
            $this->assertTrue($archive->addFromString($entry, '<?php // no execution in tests'));
        }

        $this->assertTrue($archive->close());

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
