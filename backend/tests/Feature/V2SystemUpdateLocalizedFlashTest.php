<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdateRuntime;
use App\Models\Role;
use App\Models\SystemVersion;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\UpdateZipFixture;
use Tests\TestCase;

final class V2SystemUpdateLocalizedFlashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->app->instance(UpdateRuntime::class, new V2LocalizedFlashRecordingUpdateRuntime);
    }

    public function test_arabic_admin_receives_localized_success_after_authorized_update(): void
    {
        $this->assertLocalizedUpdateSuccess(
            'ar',
            'تم تثبيت تحديث النظام إلى الإصدار 1.1.0 بنجاح.',
        );
    }

    public function test_english_admin_receives_localized_success_after_authorized_update(): void
    {
        $this->assertLocalizedUpdateSuccess(
            'en',
            'System update to version 1.1.0 completed successfully.',
        );
    }

    public function test_unprivileged_admin_cannot_upload_zip_or_receive_success_flash(): void
    {
        $this->actingAs($this->userWithRole('B2B_ADMIN', 'ar'))
            ->post(route('admin.system-update.store'), $this->updateForm())
            ->assertForbidden()
            ->assertSessionMissing('status');

        $this->assertDatabaseMissing('system_versions', ['version' => '1.1.0']);
    }

    private function assertLocalizedUpdateSuccess(string $locale, string $expected): void
    {
        SystemVersion::query()->create([
            'version' => '1.0.0',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        $user = $this->userWithRole('SUPER_ADMIN', $locale);
        $this->actingAs($user)
            ->post(route('admin.system-update.store'), $this->updateForm())
            ->assertRedirect(route('admin.system-update.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', $expected);

        $this->assertDatabaseHas('system_versions', ['version' => '1.1.0']);

        $this->actingAs($user)
            ->get(route('admin.system-update.index'))
            ->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee($expected);
    }

    /** @return array<string, mixed> */
    private function updateForm(): array
    {
        $package = UploadedFile::fake()->createWithContent(
            'v2-localized-success.zip',
            UpdateZipFixture::bytes(),
        );

        return [
            'package' => $package,
            'target_version' => '1.1.0',
            'minimum_current_version' => '1.0.0',
            'sha256' => hash_file('sha256', $package->getRealPath()),
            'release_notes' => 'Localized response HTTP test',
        ];
    }

    private function userWithRole(string $roleCode, string $locale): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-i18n-'.$locale.'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}

final class V2LocalizedFlashRecordingUpdateRuntime implements UpdateRuntime
{
    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void {}

    public function backupFiles(string $packagePath): string
    {
        return 'synthetic-file-backup';
    }

    public function backupDatabase(bool $required): ?string
    {
        return 'synthetic-database-backup';
    }

    public function verifyDatabaseBackup(string $backupReference): void {}

    public function enterMaintenanceMode(): void {}

    public function extractRelease(string $packagePath): void {}

    public function runMigrations(): void {}

    public function rebuildCaches(): void {}

    public function healthCheck(): void {}

    public function rollbackFiles(string $backupReference): void {}

    public function rollbackDatabase(string $backupReference): void {}

    public function exitMaintenanceMode(): void {}
}
