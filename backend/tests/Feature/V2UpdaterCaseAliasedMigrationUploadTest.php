<?php

namespace Tests\Feature;

use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdatePackageManifest;
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

    public function test_signed_upload_rejects_case_aliased_migrations_before_backup_or_live_mutation(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Ed25519 extension required for signed migration alias test.');
        }

        $path = $this->archive(['Backend/Database/Migrations/2026_11_14_000001_probe.PHP']);
        $beforeFiles = $this->incomingFiles();

        // A trusted signer cannot declare "no migrations" while hiding an
        // aliased PHP migration. Reject BEFORE UpdateManager and backups.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.system-update.store'), $this->signedPayload($path))
            ->assertRedirect(route('admin.system-update.index'))
            ->assertSessionHasErrors('update');

        $this->assertDatabaseCount('update_history', 0);
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

    /** @return array<string, mixed> */
    private function signedPayload(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $keypair = sodium_crypto_sign_keypair();
        $keyId = 'g005-migration-alias-ci';
        config([
            'update_signatures.publisher_key_id' => $keyId,
            'update_signatures.publisher_public_key_b64' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ]);
        $manifest = new UpdatePackageManifest(
            '1.2.0', '1.0.0', hash('sha256', $bytes),
            'Detect aliased migration before backup', false,
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
            'package' => new UploadedFile($path, 'update.zip', 'application/zip', null, true),
            'signed_manifest' => UploadedFile::fake()->createWithContent('update.signed.json', $signed),
        ];
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
