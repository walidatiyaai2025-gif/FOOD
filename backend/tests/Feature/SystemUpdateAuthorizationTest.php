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
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Tests\TestCase;

class SystemUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_system_update_center(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('System Update')
            ->assertSee('Runtime health & recovery')
            ->assertSee('Backup Center')
            ->assertSee('Recent failed jobs')
            ->assertSee('Validated update package');

        $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('admin.administration.index').'"', false)
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    public function test_system_update_center_repairs_legacy_compact_installed_version(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN');

        SystemVersion::query()->create([
            'version' => '1.084',
            'installed_at' => now(),
            'package_hash' => str_repeat('a', 64),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.083',
            'to_version' => '1.084',
            'status' => 'success',
            'package_hash' => str_repeat('a', 64),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        UpdateHistory::query()->create([
            'from_version' => '1.084',
            'to_version' => '1.0.85',
            'status' => 'failed',
            'package_hash' => str_repeat('b', 64),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('1.0.84');

        $this->assertDatabaseMissing('system_versions', ['version' => '1.084']);
        $this->assertDatabaseHas('system_versions', ['version' => '1.0.84']);
        $this->assertDatabaseHas('update_history', [
            'to_version' => '1.0.84',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('update_history', [
            'from_version' => '1.0.84',
            'to_version' => '1.0.85',
            'status' => 'failed',
        ]);
    }

    public function test_non_update_admin_cannot_open_system_update_center(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = $this->userWithRole('B2B_ADMIN');

        $this->actingAs($user)
            ->get('/admin/settings/system-update')
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.system-update.backups.store'), ['reason' => 'unauthorized backup'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [
                'reason' => 'unauthorized restore',
                'confirm_reference' => '20261017-120000-database-aaaaaaaaaa',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('href="'.route('admin.system-update.index').'"', false);
    }

    public function test_system_administrator_with_system_update_permission_cannot_access_high_risk_update_or_recovery_routes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $administrator = $this->userWithRole('SYSTEM_ADMINISTRATOR');
        $this->assertTrue($administrator->hasPermission('system.update'));
        $this->assertFalse($administrator->hasRole('SUPER_ADMIN'));

        $this->actingAs($administrator)
            ->get(route('admin.system-update.index'))
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.store'), [])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.backups.store'), ['reason' => 'forbidden backup'])
            ->assertForbidden();

        $this->actingAs($administrator)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [
                'reason' => 'forbidden restore',
                'confirm_reference' => '20261017-120000-database-aaaaaaaaaa',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('update_history', 0);
    }

    public function test_super_admin_reaches_validation_for_update_backup_and_restore_actions(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $superAdmin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.store'), [])
            ->assertSessionHasErrors(['package', 'signed_manifest']);

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.backups.store'), [])
            ->assertSessionHasErrors(['reason']);

        $this->actingAs($superAdmin)
            ->post(route('admin.system-update.backups.restore', [
                'backupReference' => '20261017-120000-database-aaaaaaaaaa',
            ]), [])
            ->assertSessionHasErrors(['reason', 'confirm_reference']);
    }

    public function test_update_center_shows_bilingual_nonmutating_cpanel_permission_remediation(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $directory = storage_path('framework/testing/update-host-preflight-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0750, true);
        $blockingFile = $directory.'/not-a-directory';
        file_put_contents($blockingFile, 'preserve-host-sentinel');

        config([
            'foodex.update_backup_path' => $blockingFile.'/backups',
            'foodex.updater_execution_lock' => $blockingFile.'/locks/execution.lock',
        ]);

        try {
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);
                $response = $this->actingAs($admin->fresh())
                    ->get('/admin/settings/system-update')
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__('update_host_readiness.title', [], $locale))
                    ->assertSee(__('update_host_readiness.action_required', [], $locale))
                    ->assertSee(__('update_signed_manifest.signature_caveat', [], $locale))
                    ->assertSee(__('update_signed_manifest.host_key_missing', [], $locale))
                    ->assertSee(__('update_signed_manifest.manifest_label', [], $locale))
                    ->assertSee(__('update_host_readiness.manual_verification', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.sodium_signature.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.sodium_signature.help', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.upload_temporary.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.upload_temporary.help', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.process_execution.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.process_execution.help', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.backup_cli_tools.label', [], $locale))
                    ->assertSee(__('update_host_readiness.checks.backup_cli_tools.help', [], $locale))
                    ->assertSee('data-host-requirement="process_execution"', false)
                    ->assertSee('data-host-requirement="backup_cli_tools"', false)
                    ->assertSee('data-host-requirement="upload_temporary"', false)
                    ->assertSee(
                        'data-host-requirement="sodium_signature" data-host-status="'
                        .(extension_loaded('sodium')
                            && function_exists('sodium_crypto_sign_verify_detached')
                            && defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
                            ? 'precheck-ok' : 'host-action-required').'"',
                        false,
                    )
                    ->assertSee('data-host-requirement="backup_directory" data-host-status="host-action-required"', false)
                    ->assertSee('data-host-requirement="update_lock" data-host-status="host-action-required"', false);

                foreach (['zip_extension', 'app_storage', 'cache', 'update_upload', 'update_lock', 'backup_directory', 'release_directory'] as $check) {
                    $response
                        ->assertSee(__('update_host_readiness.checks.'.$check.'.label', [], $locale))
                        ->assertSee(__('update_host_readiness.checks.'.$check.'.help', [], $locale));
                }

                $this->assertStringNotContainsString($directory, (string) $response->getContent());
            }

            // A GET readiness diagnostic must not create private update/lock
            // directories, mutate the sentinel, or claim verified host setup.
            $this->assertSame('preserve-host-sentinel', file_get_contents($blockingFile));
            $this->assertFileDoesNotExist($blockingFile.'/backups');
            $this->assertFileDoesNotExist($blockingFile.'/locks/execution.lock');
        } finally {
            app()->setLocale('en');
            @unlink($blockingFile);
            @rmdir($directory);
        }
    }

    public function test_missing_mysql_backup_cli_is_reported_without_mutating_the_host(): void
    {
        // This is a read-only preflight; a missing PHP-accessible DB client is
        // an explicit cPanel operator action, not a reason to weaken signing.
        $originalDriver = config('database.default');
        config([
            'database.default' => 'mysql',
            'foodex.mysql_dump_binary' => '/g005-no-such-tools/mysqldump',
            'foodex.mysql_client_binary' => '/g005-no-such-tools/mysql',
        ]);

        try {
            $method = new ReflectionMethod(SystemUpdateController::class, 'hostingPermissionChecks');
            $checks = $method->invoke(app(SystemUpdateController::class));

            $this->assertFalse($checks['backup_cli_tools']);
            $this->assertSame(
                function_exists('proc_open') && function_exists('proc_close'),
                $checks['process_execution'],
            );
        } finally {
            // RefreshDatabase teardown must retain the connection it opened.
            config(['database.default' => $originalDriver]);
        }
    }

    public function test_update_center_preflight_does_not_create_creatable_host_directories(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $directory = storage_path('framework/testing/update-host-uncreated-'.bin2hex(random_bytes(6)));
        config([
            'foodex.update_backup_path' => $directory.'/backups',
            'foodex.updater_execution_lock' => $directory.'/locks/execution.lock',
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/system-update')
            ->assertOk()
            ->assertSee('data-host-requirement="backup_directory" data-host-status="precheck-ok"', false)
            ->assertSee('data-host-requirement="update_lock" data-host-status="precheck-ok"', false)
            ->assertSee(__('update_host_readiness.precheck_ok'));

        $this->assertDirectoryDoesNotExist($directory);
    }

    public function test_unsigned_update_is_denied_before_update_history_or_file_mutation(): void
    {
        $actor = $this->userWithRole('SUPER_ADMIN');
        $this->actingAs($actor)
            ->post(route('admin.system-update.store'), [
                'package' => UploadedFile::fake()->createWithContent('unsigned.zip', 'untrusted ZIP bytes'),
            ])
            ->assertSessionHasErrors(['signed_manifest']);

        $this->assertDatabaseCount('update_history', 0);
    }

    public function test_unpinned_and_tampered_signed_zip_is_denied_before_manager(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium unavailable on this CI worker.');
        }

        $actor = $this->userWithRole('SUPER_ADMIN');
        $bytes = 'not-a-zip-but-signed-by-ci-fixture';
        [$payload, $public] = $this->signedManifestForBytes($bytes);
        config([
            'update_signatures.publisher_key_id' => '',
            'update_signatures.publisher_public_key_b64' => '',
        ]);

        $this->actingAs($actor)->post(route('admin.system-update.store'), [
            'package' => UploadedFile::fake()->createWithContent('patch.zip', $bytes),
            'signed_manifest' => UploadedFile::fake()->createWithContent('signed.json', $payload),
        ])->assertSessionHasErrors(['update']);
        $this->assertDatabaseCount('update_history', 0);

        config([
            'update_signatures.publisher_key_id' => 'ci-only-ephemeral',
            'update_signatures.publisher_public_key_b64' => $public,
        ]);
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $data['target_version'] = '99.0.1';
        $this->actingAs($actor)->post(route('admin.system-update.store'), [
            'package' => UploadedFile::fake()->createWithContent('patch.zip', $bytes),
            'signed_manifest' => UploadedFile::fake()->createWithContent(
                'tampered.json', json_encode($data, JSON_THROW_ON_ERROR),
            ),
        ])->assertSessionHasErrors(['update']);

        $this->assertDatabaseCount('update_history', 0);
    }

    public function test_signed_update_failing_file_move_cleans_partial_private_zip_and_returns_localized_host_error(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Sodium support is required.');
        }

        $actor = $this->userWithRole('SUPER_ADMIN');
        $source = tempnam(sys_get_temp_dir(), 'foodex-move-source-');
        $this->assertIsString($source);
        $bytes = 'signed non-ZIP fixture; the move must fail before ZIP validation';
        file_put_contents($source, $bytes);
        [$signedManifest, $public] = $this->signedManifestForBytes($bytes);

        config([
            'update_signatures.publisher_key_id' => 'ci-only-ephemeral',
            'update_signatures.publisher_public_key_b64' => $public,
        ]);

        $oldStoragePath = storage_path();
        $privateStorage = sys_get_temp_dir().'/foodex-move-failure-'.bin2hex(random_bytes(8));
        mkdir($privateStorage, 0750, true);
        app()->useStoragePath($privateStorage);
        $incoming = storage_path('app/private/updates/incoming');
        mkdir($incoming, 0750, true);

        try {
            $failingUpload = new class($source, 'update.zip', 'application/zip', null, true) extends UploadedFile
            {
                public function move(string $directory, ?string $name = null): \Symfony\Component\HttpFoundation\File\File
                {
                    // Simulate a cPanel filesystem failure AFTER a partial
                    // private ZIP has been written, not merely a blocked preflight.
                    file_put_contents($directory.'/'.$name, 'incomplete upload');
                    throw new FileException(
                        'Unable to write /home/private-operator/secret-path',
                    );
                }
            };

            $this->actingAs($actor)
                ->post(route('admin.system-update.store'), [
                    'package' => $failingUpload,
                    'signed_manifest' => UploadedFile::fake()->createWithContent('signed.json', $signedManifest),
                ])
                ->assertRedirect(route('admin.system-update.index'))
                ->assertSessionHasErrors([
                    'update' => __('update_signed_manifest.upload_staging_unavailable'),
                ]);

            $this->assertDatabaseCount('update_history', 0);
            $this->assertSame([], array_values(array_diff(scandir($incoming) ?: [], ['.', '..'])));
            $this->assertSame($bytes, file_get_contents($source));
        } finally {
            app()->useStoragePath($oldStoragePath);
            File::deleteDirectory($privateStorage);
            @unlink($source);
        }
    }

    public function test_symlinked_update_upload_staging_is_refused_before_package_move(): void
    {
        if (! function_exists('symlink') || ! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('Symlink and Sodium support are required.');
        }

        $actor = $this->userWithRole('SUPER_ADMIN');
        $root = sys_get_temp_dir().'/foodex-update-upload-'.bin2hex(random_bytes(8));
        mkdir($root, 0750, true);
        mkdir($root.'/outside', 0750, true);

        $oldStoragePath = storage_path();
        $storage = $root.'/storage';
        app()->useStoragePath($storage);
        $incoming = storage_path('app/private/updates/incoming');
        mkdir(dirname($incoming), 0750, true);

        try {
            if (! @symlink($root.'/outside', $incoming) || ! is_link($incoming)) {
                $this->markTestSkipped('This test runner cannot create directory symlinks.');
            }

            $bytes = 'signed disposable package bytes';
            [$payload, $public] = $this->signedManifestForBytes($bytes);
            config([
                'update_signatures.publisher_key_id' => 'ci-only-ephemeral',
                'update_signatures.publisher_public_key_b64' => $public,
            ]);

            $this->actingAs($actor)->post(route('admin.system-update.store'), [
                'package' => UploadedFile::fake()->createWithContent('patch.zip', $bytes),
                'signed_manifest' => UploadedFile::fake()->createWithContent('signed.json', $payload),
            ])->assertSessionHasErrors(['update']);

            $this->assertDatabaseCount('update_history', 0);
            $this->assertSame([], array_values(array_diff(scandir($root.'/outside') ?: [], ['.', '..'])));
            $this->assertTrue(is_link($incoming));
        } finally {
            app()->useStoragePath($oldStoragePath);
            if (is_link($incoming)) {
                @unlink($incoming);
            }
            File::deleteDirectory($root);
        }
    }

    public function test_valid_test_signature_reaches_zip_validation_and_rejects_zip_slip_with_data_untouched(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair') || ! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Sodium and ZIP extensions are required.');
        }

        $actor = $this->userWithRole('SUPER_ADMIN');
        $marker = storage_path('app/v2-update-signed-preservation-test.txt');
        file_put_contents($marker, 'KEEP_UPLOAD_BYTES');
        $zipPath = tempnam(sys_get_temp_dir(), 'foodex-signed-zip-');
        if (! is_string($zipPath)) {
            $this->fail('Unable to stage disposable ZIP fixture.');
        }

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
            $this->assertTrue($zip->addFromString('../escape.txt', 'attempted traversal'));
            $this->assertTrue($zip->close());
            $bytes = (string) file_get_contents($zipPath);
            [$payload, $public] = $this->signedManifestForBytes($bytes);
            config([
                'update_signatures.publisher_key_id' => 'ci-only-ephemeral',
                'update_signatures.publisher_public_key_b64' => $public,
            ]);

            $this->actingAs($actor)->post(route('admin.system-update.store'), [
                'package' => UploadedFile::fake()->createWithContent('patch.zip', $bytes),
                'signed_manifest' => UploadedFile::fake()->createWithContent('signed.json', $payload),
            ])->assertSessionHasErrors(['update']);

            $this->assertSame('KEEP_UPLOAD_BYTES', file_get_contents($marker));
            $this->assertDatabaseMissing('system_versions', ['version' => '99.0.0']);
            $this->assertDatabaseHas('update_history', ['to_version' => '99.0.0', 'status' => 'failed']);
            $this->assertFileDoesNotExist(storage_path('app/private/updates/escape.txt'));
        } finally {
            @unlink($zipPath);
            @unlink($marker);
        }
    }

    /** @return array{string, string} */
    private function signedManifestForBytes(string $archive): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $manifest = new UpdatePackageManifest('99.0.0', '0.0.0', hash('sha256', $archive), 'Disposable CI-only acceptance', false);
        $id = 'ci-only-ephemeral';
        $canonical = app(TrustedUpdateSignatureVerifier::class)->canonicalPayload($manifest, $id);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, sodium_crypto_sign_secretkey($keypair)));

        return [
            json_encode([
                'format' => 'foodex-signed-update-v1',
                'signer_key_id' => $id,
                'target_version' => $manifest->targetVersion,
                'minimum_current_version' => $manifest->minimumCurrentVersion,
                'archive_sha256' => $manifest->sha256,
                'contains_migrations' => $manifest->containsMigrations,
                'release_notes' => $manifest->releaseNotes,
                'signature' => $signature,
            ], JSON_THROW_ON_ERROR),
            base64_encode(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-update@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
