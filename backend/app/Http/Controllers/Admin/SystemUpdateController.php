<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Updater\SignedUpdateEnvelope;
use App\Domain\Updater\TrustedUpdateSignatureVerifier;
use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdateVersion;
use App\Http\Controllers\Controller;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use App\Services\MobileReleaseArtifactMirror;
use App\Services\SystemAdministrationRuntime;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZipArchive;

final class SystemUpdateController extends Controller
{
    public function __construct(
        private readonly UpdateManager $manager,
        private readonly TrustedUpdateSignatureVerifier $signatureVerifier,
        private readonly MobileReleaseArtifactMirror $mobileArtifacts,
        private readonly SystemAdministrationRuntime $systemRuntime,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeSuperAdminUpdate($request);

        // An updater may rebuild the in-process auth/config caches. The Gate
        // has already authorized this exact request identity; pass it to the
        // view rather than resolving a potentially reset global auth guard.
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $currentVersion = $this->currentVersion();

        // The feature's own first patch is executed by the old PHP request.
        // Its redirect is the first request using the new code, so the existing
        // success flash is the bootstrap signal for the new local APK mirror.
        if ($request->session()->has('status')) {
            try {
                $this->mobileArtifacts->ensureCurrentAppsScheduled();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $statusCounts = UpdateHistory::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        return view('admin.system-update', [
            'user' => $actor,
            // optimize:clear inside the updater may reset the view factory's
            // middleware-shared globals for the next request in the same PHP
            // process. Retain the current session's validation error bag.
            'errors' => $request->session()->get('errors', new ViewErrorBag),
            'currentVersion' => $currentVersion,
            'historyRows' => UpdateHistory::query()->latest('id')->paginate(20)->withQueryString(),
            'updateStatusCounts' => $statusCounts,
            'runtimeHealth' => $this->systemRuntime->healthSnapshot(),
            'backupRows' => $this->systemRuntime->backups(50),
            'recoveryOperations' => $this->systemRuntime->recentOperations(50),
            'isProduction' => app()->environment('production'),
            'hostingChecks' => $this->hostingPermissionChecks(),
            'signatureVerificationConfigured' => $this->hasConfiguredTrustedPublisher(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdminUpdate($request);

        $request->validate([
            'package' => ['required', 'file', 'max:512000'],
            'signed_manifest' => ['required', 'file', 'max:16'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $upload = $request->file('package');
        $signedManifestFile = $request->file('signed_manifest');
        abort_if($upload === null || $signedManifestFile === null, 422, 'Signed update package is required.');

        // The signed sidecar, not user-entered fields or a ZIP member, is
        // authoritative. Verify against an independently pinned host key
        // BEFORE entering UpdateManager or touching any release files.
        try {
            $rawManifest = $signedManifestFile->get();
            if (! is_string($rawManifest)) {
                throw new RuntimeException('Signed update manifest cannot be read.');
            }
            $signed = SignedUpdateEnvelope::parse($rawManifest);
            $signed->verify($this->signatureVerifier, $this->trustedPublisherKeys());
        } catch (Throwable) {
            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => __('update_signed_manifest.invalid_signature')]);
        }

        $directory = storage_path('app/private/updates/incoming');
        if (! $this->hostPathWritableWithoutMutation($directory)) {
            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => __('update_signed_manifest.upload_staging_unavailable')]);
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => __('update_signed_manifest.upload_staging_unavailable')]);
        }

        if (! $this->hostPathWritableAfterFilesystemChange($directory)) {
            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => __('update_signed_manifest.upload_staging_unavailable')]);
        }

        $fileName = 'update-'.bin2hex(random_bytes(12)).'.zip';
        $packagePath = $directory.'/'.$fileName;

        try {
            $upload->move($directory, $fileName);
        } catch (Throwable) {
            // On shared hosting a move may fail after writing part of the ZIP.
            // Never expose raw filesystem paths or leave a partial executable
            // package in private staging when no update has been started.
            @unlink($packagePath);

            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => __('update_signed_manifest.upload_staging_unavailable')]);
        }

        try {
            $manifest = $signed->manifest;
            // A signed "no migrations" declaration cannot mask migration
            // files. Never derive a more permissive declaration from the ZIP.
            if (! $manifest->containsMigrations && $this->packageContainsMigrations($packagePath)) {
                throw new RuntimeException(__('update_signed_manifest.invalid_signature'));
            }

            $history = $this->manager->execute(
                $manifest,
                $this->currentVersion(),
                $packagePath,
                $user,
            );

            try {
                $this->mobileArtifacts->ensureCurrentAppsScheduled();
            } catch (Throwable $exception) {
                report($exception);
            }

            return redirect()
                ->route('admin.system-update.index')
                ->with('status', __('admin.system_update_surface.update_completed', [
                    'version' => $history->to_version,
                ]));
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['update' => $exception->getMessage()]);
        } finally {
            @unlink($packagePath);
        }
    }

    public function backup(Request $request): RedirectResponse
    {
        $this->authorizeSuperAdminUpdate($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        try {
            $backup = $this->systemRuntime->createDatabaseBackup(
                $actor,
                $request,
                (string) $validated['reason'],
                $this->currentVersion(),
            );

            return redirect()
                ->route('admin.system-update.index')
                ->with('status', __('admin.system_update_surface.backup_created', [
                    'reference' => (string) $backup['reference'],
                ]));
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['backup' => __('admin.system_update_surface.backup_failed')]);
        }
    }

    public function restore(Request $request, string $backupReference): RedirectResponse
    {
        $this->authorizeSuperAdminUpdate($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_reference' => ['required', 'string', 'max:80'],
            'production_confirmation' => ['nullable', 'string', 'max:80'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        try {
            $this->systemRuntime->restoreDatabaseBackup(
                $actor,
                $request,
                $backupReference,
                (string) $validated['reason'],
                (string) $validated['confirm_reference'],
                isset($validated['production_confirmation'])
                    ? (string) $validated['production_confirmation']
                    : null,
                $this->currentVersion(),
            );

            return redirect()
                ->route('admin.system-update.index')
                ->with('status', __('admin.system_update_surface.restore_completed', [
                    'reference' => $backupReference,
                ]));
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('admin.system-update.index')
                ->withErrors(['restore' => __('admin.system_update_surface.restore_failed')]);
        }
    }

    /**
     * The updater, manual backups and recovery mutate platform-wide files
     * and databases. system.update alone can be granted to a SYSTEM_ADMINISTRATOR,
     * but these high-risk operations require an active global Super Admin.
     * Retain the existing Gate so explicit permission checks are not bypassed.
     */
    private function authorizeSuperAdminUpdate(Request $request): void
    {
        // A ZIP update rebuilds configuration caches inside an HTTP request.
        // Bind Gate to the authenticated request actor explicitly, so an
        // Artisan bootstrap cannot replace the implicit Gate user resolver.
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('system.update');
        abort_unless($actor->is_active && $actor->hasRole('SUPER_ADMIN'), 403);
    }

    /**
     * @return array<string, string>
     */
    private function trustedPublisherKeys(): array
    {
        $keyId = trim((string) config('update_signatures.publisher_key_id', ''));
        $publicKey = trim((string) config('update_signatures.publisher_public_key_b64', ''));

        if ($keyId === '' || $publicKey === '') {
            return [];
        }

        return [$keyId => $publicKey];
    }

    private function hasConfiguredTrustedPublisher(): bool
    {
        $keys = $this->trustedPublisherKeys();
        if (count($keys) !== 1 || ! function_exists('sodium_crypto_sign_verify_detached')
            || ! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            return false;
        }

        $id = array_key_first($keys);
        if (preg_match('/\\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}\\z/D', $id) !== 1) {
            return false;
        }

        $base64 = $keys[$id];
        $decoded = base64_decode($base64, true);

        return is_string($decoded)
            && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && hash_equals(base64_encode($decoded), $base64);
    }

    /**
     * cPanel/shared-host preflight: inspect only, never create probes, chmod
     * files or mutate the configured environment during a GET request.
     * A positive result is a permission hint, NOT an update/restore signoff.
     *
     * @return array<string, bool>
     */
    private function hostingPermissionChecks(): array
    {
        return [
            'zip_extension' => extension_loaded('zip') && class_exists(ZipArchive::class),
            // Ed25519 is a hosting prerequisite only; it does NOT certify
            // that the update pipeline authenticates the signed publisher.
            'sodium_signature' => extension_loaded('sodium')
                && function_exists('sodium_crypto_sign_verify_detached')
                && defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES'),
            // A readable php.ini value is not an upload test; check only the
            // effective PHP temporary directory and never create a probe here.
            'upload_temporary' => $this->hostPathWritableWithoutMutation(
                trim((string) ini_get('upload_tmp_dir')) ?: sys_get_temp_dir(),
            ),
            // The GET never invokes shells, probes the database or uploads
            // artifacts. These are prerequisite hints, not verified backups.
            'process_execution' => function_exists('proc_open') && function_exists('proc_close'),
            'backup_cli_tools' => $this->databaseBackupToolsPresent(),
            'app_storage' => $this->hostPathWritableWithoutMutation(storage_path()),
            'cache' => $this->hostPathWritableWithoutMutation(base_path('bootstrap/cache')),
            'update_upload' => $this->hostPathWritableWithoutMutation(storage_path('app/private/updates/incoming')),
            'update_lock' => $this->hostPathWritableWithoutMutation(
                (string) config('foodex.updater_execution_lock', storage_path('app/private/updates/execution.lock')),
                true,
            ),
            'backup_directory' => $this->hostPathWritableWithoutMutation(
                (string) config('foodex.update_backup_path', storage_path('app/system/update-backups')),
            ),
            'release_directory' => $this->hostPathWritableWithoutMutation(dirname(base_path())),
        ];
    }

    /**
     * Check tool discovery only. File permissions, credentials, ability to run
     * subprocesses and restoration are proven by an actual disposable backup
     * test, not by merely finding a binary on the web host.
     */
    private function databaseBackupToolsPresent(): bool
    {
        $driver = (string) config('database.default', '');
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return true;
        }

        $commands = $driver === 'pgsql'
            ? [(string) config('foodex.pg_dump_binary', 'pg_dump'), (string) config('foodex.pg_restore_binary', 'pg_restore')]
            : [(string) config('foodex.mysql_dump_binary', 'mysqldump'), (string) config('foodex.mysql_client_binary', 'mysql')];
        $finder = new ExecutableFinder;

        foreach ($commands as $binary) {
            if ($binary === '' || $finder->find($binary) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refuse symlinks, non-directory ancestors and unwritable lock files.
     * A missing nested directory may be created later if the nearest existing
     * ancestor is writable; this check intentionally does NOT create it.
     */
    private function hostPathWritableWithoutMutation(string $path, bool $file = false): bool
    {
        if ($path === '' || (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1)) {
            return false;
        }

        for ($candidate = $path; ; $candidate = dirname($candidate)) {
            if (is_link($candidate)) {
                return false;
            }
            if (dirname($candidate) === $candidate) {
                break;
            }
        }

        if ($file && file_exists($path) && (! is_file($path) || ! is_writable($path))) {
            return false;
        }

        $directory = $file ? dirname($path) : $path;
        while (! is_dir($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                return false;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return false;
            }
            $directory = $parent;
        }

        return is_writable($directory);
    }

    /**
     * Recheck a path after creating its directory so cached pre-creation
     * filesystem results cannot hide a newly introduced link or blocker.
     */
    private function hostPathWritableAfterFilesystemChange(string $path): bool
    {
        clearstatcache(true);

        return $this->hostPathWritableWithoutMutation($path);
    }

    private function packageContainsMigrations(string $packagePath): bool
    {
        // The Dashboard calls this before UpdateManager validates the ZIP.
        // Bound this scan independently: the validator's ZIP member budget
        // cannot protect an earlier upload-time member enumeration.
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('admin.system_update_surface.not_applied'));
        }

        $archive = new ZipArchive;

        if ($archive->open($packagePath) !== true) {
            throw new RuntimeException(__('admin.system_update_surface.not_applied'));
        }

        try {
            $maxMembers = max(1, (int) config('foodex.update_max_zip_members', 50000));
            if ($archive->numFiles > $maxMembers) {
                throw new RuntimeException(__('admin.system_update_surface.not_applied'));
            }

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);

                if (! is_string($name)) {
                    continue;
                }

                // Match validator migration classification on case-insensitive
                // hosts as well as canonical Linux release layouts.
                $relativePath = strtolower(str_replace('\\', '/', trim($name)));
                if (str_starts_with($relativePath, 'backend/database/migrations/')
                    && str_ends_with($relativePath, '.php')) {
                    return true;
                }
            }
        } finally {
            $archive->close();
        }

        return false;
    }

    private function currentVersion(): string
    {
        $installed = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->first();

        if ($installed instanceof SystemVersion && $installed->version !== '') {
            $version = UpdateVersion::normalizeInstalled($installed->version);

            if ($version !== $installed->version && UpdateVersion::isCanonical($version)) {
                $this->repairLegacyInstalledVersion($installed, $version);
            }

            return $version;
        }

        $version = UpdateVersion::normalizeInstalled(trim((string) @file_get_contents(base_path('../VERSION'))));

        if ($version === '' || ! UpdateVersion::isCanonical($version)) {
            throw new RuntimeException('Current FOODEX version could not be determined.');
        }

        return $version;
    }

    private function repairLegacyInstalledVersion(SystemVersion $installed, string $canonicalVersion): void
    {
        $legacyVersion = (string) $installed->version;

        DB::transaction(function () use ($installed, $legacyVersion, $canonicalVersion): void {
            $canonical = SystemVersion::query()
                ->where('version', $canonicalVersion)
                ->first();

            if ($canonical instanceof SystemVersion) {
                $canonical->forceFill([
                    'installed_at' => $installed->installed_at,
                    'package_hash' => $installed->package_hash,
                ])->save();

                $installed->delete();
            } else {
                $installed->forceFill(['version' => $canonicalVersion])->save();
            }

            UpdateHistory::query()
                ->where('from_version', $legacyVersion)
                ->update(['from_version' => $canonicalVersion]);

            UpdateHistory::query()
                ->where('to_version', $legacyVersion)
                ->update(['to_version' => $canonicalVersion]);
        });
    }
}
