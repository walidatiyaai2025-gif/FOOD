<?php

namespace Tests\Deployment;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdateRuntime;
use App\Models\Role;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * Actual Laravel Admin HTTP upload + real MySQL/Redis updater on disposable CI.
 * ZIPs here are test-built fixtures, NOT publisher-approved release artifacts.
 */
final class SignedAdminZipMySqlDeploymentTest extends TestCase
{
    private string $directory;

    private string $originalVersion;

    private string $probe;

    private string $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());
        $this->directory = storage_path('framework/testing/g005-signed-admin-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
        $this->originalVersion = (string) file_get_contents(base_path('../VERSION'));
        $this->probe = public_path('assets/g005-signed-zip-probe.js');
        $this->migration = database_path('migrations/2999_01_01_000001_g005_signed_rollback_probe.php');
        $this->assertFileDoesNotExist($this->migration);
        config([
            'foodex.update_backup_path' => $this->directory.'/backups',
            'foodex.updater_execution_lock' => $this->directory.'/execution.lock',
        ]);

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->seed(CoreReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalVersion)) {
            file_put_contents(base_path('../VERSION'), $this->originalVersion);
            File::delete($this->migration);
            File::delete($this->probe);
            Artisan::call('up');
            Artisan::call('optimize:clear');
        }
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_signed_admin_zip_upgrade_preserves_env_upload_and_data_then_restores_failed_migration(): void
    {
        if (! function_exists('sodium_crypto_sign_detached') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('CI needs real ext-sodium and ext-zip.');
        }

        $user = User::query()->create([
            'name' => 'Original Operator',
            'email' => 'g005-owner@example.test',
            'password' => 'Disposable-CI-Password-Only!',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $pair = sodium_crypto_sign_keypair();
        $id = 'g005-disposable-ci';
        config([
            'update_signatures.publisher_key_id' => $id,
            'update_signatures.publisher_public_key_b64' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);
        $upload = storage_path('app/g005-preserve-upload.bin');
        File::ensureDirectoryExists(dirname($upload));
        file_put_contents($upload, random_bytes(512));
        $originalUpload = hash_file('sha256', $upload);
        $originalEnv = hash_file('sha256', base_path('.env'));

        try {
            $version = $this->buildZip('good-update.zip', [
                'VERSION' => "99.0.0\n",
                'backend/public/assets/g005-signed-zip-probe.js' => 'window.g005SignedTest = true;',
            ]);
            $this->signedPost($user, $pair, $id, $version, '99.0.0', '0.0.0', false)
                ->assertRedirect(route('admin.system-update.index'))
                ->assertSessionHas('status');
            $this->assertDatabaseHas('update_history', ['to_version' => '99.0.0', 'status' => 'success']);
            $this->assertDatabaseHas('users', ['email' => 'g005-owner@example.test', 'name' => 'Original Operator']);
            $this->assertSame($originalEnv, hash_file('sha256', base_path('.env')));
            $this->assertSame($originalUpload, hash_file('sha256', $upload));
            $this->assertSame('99.0.0', trim((string) file_get_contents(base_path('../VERSION'))));
            $this->assertFileExists($this->probe);

            // A second admin HTTP request must load the currently persisted
            // identity after the updater rebuilt config and role caches.
            // Never bypass the original Gate/Super Admin boundary in tests.
            $user = $user->fresh();
            $this->assertNotNull($user);
            $this->assertTrue($user->is_active, 'Updater deactivated the original administrator.');
            $this->assertTrue($user->hasRole('SUPER_ADMIN'), 'Updater removed effective Super Admin role.');
            $this->assertTrue($user->hasPermission('system.update'), 'Updater removed system.update authority.');

            // A real host pins its publisher public key outside the ZIP and
            // persists it across cache rebuilds. This disposable test instead
            // generated its Ed25519 trust anchor only in PHP memory, so restore
            // the exact SAME CI-only pinned public key after the first update.
            // Never accept an untrusted key from the uploaded signed sidecar.
            config([
                'update_signatures.publisher_key_id' => $id,
                'update_signatures.publisher_public_key_b64' => base64_encode(sodium_crypto_sign_publickey($pair)),
            ]);

            // Preserve the actual middleware and Gate; an unhandled 403 here
            // identifies the exact server denial point in MySQL CI logs.
            $this->withoutExceptionHandling();
            $this->actingAs($user)->get(route('admin.system-update.index'))->assertOk();

            $migration = <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public $withinTransaction = false;
    public function up(): void {
        \Illuminate\Support\Facades\DB::table('users')->where('email', 'g005-owner@example.test')
            ->update(['name' => 'Must rollback partial MySQL data']);
        throw new \RuntimeException('Injected disposable failure');
    }
    public function down(): void {}
};
PHP;
            $failed = $this->buildZip('bad-update.zip', [
                'VERSION' => "99.0.1\n",
                'backend/database/migrations/'.basename($this->migration) => $migration,
            ]);
            $this->signedPost($user, $pair, $id, $failed, '99.0.1', '99.0.0', true)
                ->assertRedirect(route('admin.system-update.index'))
                ->assertSessionHasErrors(['update']);

            $this->assertSame('99.0.0', trim((string) file_get_contents(base_path('../VERSION'))));
            $this->assertFileDoesNotExist($this->migration);
            $this->assertDatabaseHas('users', ['email' => 'g005-owner@example.test', 'name' => 'Original Operator']);
            $this->assertDatabaseHas('update_history', ['to_version' => '99.0.1', 'status' => 'failed']);
            $this->assertDatabaseMissing('system_versions', ['version' => '99.0.1']);
            $this->assertSame($originalEnv, hash_file('sha256', base_path('.env')));
            $this->assertSame($originalUpload, hash_file('sha256', $upload));
            $this->assertNotEmpty(File::glob($this->directory.'/backups/*/database.sql'));
            $this->assertFalse(app()->isDownForMaintenance());
        } finally {
            File::delete($upload);
        }
    }

    public function test_actual_source_update_zip_rejects_wrong_key_and_restores_after_health_failure(): void
    {
        if (! function_exists('sodium_crypto_sign_detached') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('CI needs real ext-sodium and ext-zip.');
        }

        $archivePath = getenv('FOODEX_ACTUAL_UPDATE_ZIP');
        $expectedDigest = getenv('FOODEX_ACTUAL_UPDATE_SHA256');
        if (! is_string($archivePath) || $archivePath === '' || ! is_file($archivePath)
            || ! is_string($expectedDigest) || ! preg_match('/^[a-f0-9]{64}$/', $expectedDigest)) {
            $this->markTestSkipped('The source-built Update ZIP is supplied only by its staging workflow.');
        }

        $archivePath = realpath($archivePath) ?: $archivePath;
        $actualDigest = hash_file('sha256', $archivePath);
        $this->assertSame($expectedDigest, $actualDigest);
        $buildManifest = json_decode(
            (string) file_get_contents(dirname($archivePath).'/FOODEX-Update.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($actualDigest, $buildManifest['sha256'] ?? null);
        $this->assertSame('1.0.127', $buildManifest['target_version'] ?? null);
        $this->assertSame('1.0.125', $buildManifest['minimum_current_version'] ?? null);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivePath));
        $this->assertNotFalse($zip->locateName('backend/public/index.php'));
        $zip->close();

        $email = 'g005-actual-zip-owner@example.test';
        $user = User::query()->create([
            'name' => 'Actual ZIP Operator',
            'email' => $email,
            'password' => 'Disposable-CI-Password-Only!',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $pair = sodium_crypto_sign_keypair();
        $keyId = 'g005-actual-zip-ci-only';
        $publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPublicKey = base64_encode(sodium_crypto_sign_publickey($wrongPair));
        $upload = storage_path('app/g005-actual-zip-preserve.bin');
        File::ensureDirectoryExists(dirname($upload));
        file_put_contents($upload, random_bytes(512));
        $originalUpload = hash_file('sha256', $upload);
        $originalEnv = hash_file('sha256', base_path('.env'));

        $versionPath = base_path('../VERSION');
        $targetPath = base_path('../backend/public/index.php');
        $originalVersion = (string) file_get_contents($versionPath);
        $originalTarget = (string) file_get_contents($targetPath);
        $sentinelTarget = "G005 exact-archive rollback sentinel\n";
        $sentinelDigest = hash('sha256', $sentinelTarget);

        try {
            // The archive is the exact source-built 1.0.127 ZIP. Only the
            // disposable installed-version marker is set to its supported
            // v1.0.125 baseline; the package bytes remain immutable.
            $this->assertSame(strlen("1.0.125\n"), file_put_contents($versionPath, "1.0.125\n"));
            $this->assertSame(strlen($sentinelTarget), file_put_contents($targetPath, $sentinelTarget));

            config([
                'update_signatures.publisher_key_id' => $keyId,
                'update_signatures.publisher_public_key_b64' => $wrongPublicKey,
            ]);

            $faultRuntime = $this->failAfterApplyRuntime(
                $email,
                $this->directory.'/backups',
                $this->directory.'/execution.lock',
            );
            app()->instance(UpdateRuntime::class, $faultRuntime);

            $this->signedPost($user, $pair, $keyId, $archivePath, '1.0.127', '1.0.125', true)
                ->assertRedirect(route('admin.system-update.index'))
                ->assertSessionHasErrors(['update']);
            $this->assertSame(0, DB::table('update_history')->where('to_version', '1.0.127')->count());
            $this->assertDirectoryDoesNotExist($this->directory.'/backups');
            $this->assertSame($sentinelDigest, hash_file('sha256', $targetPath));
            $this->assertSame('1.0.125', trim((string) file_get_contents($versionPath)));

            config(['update_signatures.publisher_public_key_b64' => $publicKey]);

            $this->signedPost($user, $pair, $keyId, $archivePath, '1.0.127', '1.0.125', true)
                ->assertRedirect(route('admin.system-update.index'))
                ->assertSessionHasErrors(['update']);

            $history = UpdateHistory::query()->where('to_version', '1.0.127')->first();
            $this->assertNotNull($history);
            $this->assertSame('failed', $history->status);
            $this->assertSame($actualDigest, $history->package_hash);
            $this->assertSame('health_check', $history->failed_stage);
            $this->assertSame(
                'passed',
                $history->execution_evidence['rollback_files'] ?? null,
                sprintf(
                    'File rollback diagnostic: called=%s; %s',
                    $faultRuntime->rollbackFilesCalled ? 'yes' : 'no',
                    $faultRuntime->rollbackFilesFailure ?? 'no exception was captured.',
                ),
            );
            $this->assertSame('passed', $history->execution_evidence['rollback_database'] ?? null);
            $this->assertSame('passed', $history->execution_evidence['rollback_health_check'] ?? null);
            $this->assertSame('exited_after_rollback', $history->execution_evidence['maintenance_mode'] ?? null);
            $this->assertFalse($history->maintenance_mode_remained);
            $this->assertSame('1.0.125', trim((string) file_get_contents($versionPath)));
            $this->assertSame($sentinelDigest, hash_file('sha256', $targetPath));
            $this->assertDatabaseHas('users', ['email' => $email, 'name' => 'Actual ZIP Operator']);
            $this->assertDatabaseMissing('system_versions', ['version' => '1.0.127']);
            $this->assertSame($originalEnv, hash_file('sha256', base_path('.env')));
            $this->assertSame($originalUpload, hash_file('sha256', $upload));
            $this->assertSame($actualDigest, hash_file('sha256', $archivePath));
            $this->assertNotEmpty(File::glob($this->directory.'/backups/*/database.sql'));
            $this->assertFalse(app()->isDownForMaintenance());
        } finally {
            file_put_contents($versionPath, $originalVersion);
            file_put_contents($targetPath, $originalTarget);
            File::delete($upload);
        }
    }

    private function failAfterApplyRuntime(string $email, string $backupRoot, string $executionLock): UpdateRuntime
    {
        $runtime = app(LaravelUpdateRuntime::class);

        return new class($runtime, $email, $backupRoot, $executionLock) implements UpdateRuntime
        {
            private bool $healthFailureInjected = false;

            public bool $rollbackFilesCalled = false;

            public ?string $rollbackFilesFailure = null;

            public function __construct(
                private readonly LaravelUpdateRuntime $runtime,
                private readonly string $email,
                private readonly string $backupRoot,
                private readonly string $executionLock,
            ) {}

            public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
            {
                $this->runtime->preflight($manifest, $packagePath);
            }

            public function backupFiles(string $packagePath): string
            {
                return $this->runtime->backupFiles($packagePath);
            }

            public function backupDatabase(bool $required): ?string
            {
                return $this->runtime->backupDatabase($required);
            }

            public function enterMaintenanceMode(): void
            {
                $this->runtime->enterMaintenanceMode();
            }

            public function extractRelease(string $packagePath): void
            {
                $this->runtime->extractRelease($packagePath);
            }

            public function runMigrations(): void
            {
                $this->runtime->runMigrations();
            }

            public function rebuildCaches(): void
            {
                $this->runtime->rebuildCaches();
                // config:cache replaces the test's in-memory overrides with
                // repository defaults. A real host's .env values survive that
                // command, so restore this disposable path/lock fixture before
                // exercising the post-apply rollback.
                config([
                    'foodex.update_backup_path' => $this->backupRoot,
                    'foodex.updater_execution_lock' => $this->executionLock,
                ]);

                $changed = DB::table('users')->where('email', $this->email)->update([
                    'name' => 'Disposable post-apply fault mutation',
                ]);

                if ($changed !== 1) {
                    throw new RuntimeException('CI recovery fault could not mutate its disposable owner row.');
                }
            }

            public function healthCheck(): void
            {
                if (! $this->healthFailureInjected) {
                    $this->healthFailureInjected = true;
                    throw new RuntimeException('Injected disposable post-apply health failure.');
                }

                $this->runtime->healthCheck();
            }

            public function rollbackFiles(string $backupReference): void
            {
                $this->rollbackFilesCalled = true;

                try {
                    $this->runtime->rollbackFiles($backupReference);
                } catch (Throwable $exception) {
                    $this->rollbackFilesFailure = $exception::class.': '.$exception->getMessage();
                    throw $exception;
                }
            }

            public function verifyDatabaseBackup(string $backupReference): void
            {
                $this->runtime->verifyDatabaseBackup($backupReference);
            }

            public function rollbackDatabase(string $backupReference): void
            {
                $this->runtime->rollbackDatabase($backupReference);
            }

            public function exitMaintenanceMode(): void
            {
                $this->runtime->exitMaintenanceMode();
            }
        };
    }

    /** @param array<string, string> $entries */
    private function buildZip(string $name, array $entries): string
    {
        $path = $this->directory.'/'.$name;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entry => $contents) {
            $this->assertTrue($zip->addFromString($entry, $contents));
        }
        $this->assertTrue($zip->close());

        return $path;
    }

    private function signedPost(
        User $user,
        string $keypair,
        string $keyId,
        string $path,
        string $target,
        string $minimum,
        bool $containsMigrations,
    ): TestResponse {
        $bytes = (string) file_get_contents($path);
        $manifest = new UpdatePackageManifest(
            $target,
            $minimum,
            hash('sha256', $bytes),
            'Disposable MySQL signed ZIP, NOT a release',
            $containsMigrations,
        );
        $canonical = app(TrustedUpdateSignatureVerifier::class)->canonicalPayload($manifest, $keyId);
        $signature = base64_encode(sodium_crypto_sign_detached(
            $canonical, sodium_crypto_sign_secretkey($keypair),
        ));
        $sidecar = json_encode([
            'format' => 'foodex-signed-update-v1',
            'signer_key_id' => $keyId,
            'target_version' => $manifest->targetVersion,
            'minimum_current_version' => $manifest->minimumCurrentVersion,
            'archive_sha256' => $manifest->sha256,
            'contains_migrations' => $containsMigrations,
            'release_notes' => $manifest->releaseNotes,
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR);

        return $this->actingAs($user)->post(route('admin.system-update.store'), [
            'package' => UploadedFile::fake()->createWithContent('update.zip', $bytes),
            'signed_manifest' => UploadedFile::fake()->createWithContent('update.signed.json', $sidecar),
        ]);
    }
}
