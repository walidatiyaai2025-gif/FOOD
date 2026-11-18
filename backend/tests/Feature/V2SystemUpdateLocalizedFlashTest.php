<?php

namespace Tests\Feature;

use App\Domain\Updater\TrustedUpdateSignatureVerifier;
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
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Ed25519 extension required for signed update translation test.');
        }

        $bytes = UpdateZipFixture::bytes();
        $keypair = sodium_crypto_sign_keypair();
        $keyId = 'g005-i18n-disposable';
        config([
            'update_signatures.publisher_key_id' => $keyId,
            'update_signatures.publisher_public_key_b64' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ]);

        $manifest = new UpdatePackageManifest(
            '1.1.0', '1.0.0', hash('sha256', $bytes),
            'Localized response HTTP test', false,
        );
        $canonical = app(TrustedUpdateSignatureVerifier::class)->canonicalPayload($manifest, $keyId);
        $signed = json_encode([
            'format' => 'foodex-signed-update-v1',
            'signer_key_id' => $keyId,
            'target_version' => $manifest->targetVersion,
            'minimum_current_version' => $manifest->minimumCurrentVersion,
            'archive_sha256' => $manifest->sha256,
            'contains_migrations' => $manifest->containsMigrations,
            'release_notes' => $manifest->releaseNotes,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $canonical, sodium_crypto_sign_secretkey($keypair),
            )),
        ], JSON_THROW_ON_ERROR);

        return [
            'package' => UploadedFile::fake()->createWithContent('v2-localized-success.zip', $bytes),
            'signed_manifest' => UploadedFile::fake()->createWithContent('update.signed.json', $signed),
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
