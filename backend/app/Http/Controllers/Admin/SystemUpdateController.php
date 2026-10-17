<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
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
use RuntimeException;
use Throwable;
use ZipArchive;

final class SystemUpdateController extends Controller
{
    public function __construct(
        private readonly UpdateManager $manager,
        private readonly MobileReleaseArtifactMirror $mobileArtifacts,
        private readonly SystemAdministrationRuntime $systemRuntime,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('system.update');

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
            'currentVersion' => $currentVersion,
            'historyRows' => UpdateHistory::query()->latest('id')->paginate(20)->withQueryString(),
            'updateStatusCounts' => $statusCounts,
            'runtimeHealth' => $this->systemRuntime->healthSnapshot(),
            'backupRows' => $this->systemRuntime->backups(50),
            'recoveryOperations' => $this->systemRuntime->recentOperations(50),
            'isProduction' => app()->environment('production'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('system.update');

        $validated = $request->validate([
            'package' => ['required', 'file', 'max:512000'],
            'target_version' => ['required', 'string', 'max:64', 'regex:/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/'],
            'minimum_current_version' => ['required', 'string', 'max:64', 'regex:/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/'],
            'sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
            'release_notes' => ['nullable', 'string', 'max:5000'],
            'contains_migrations' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $upload = $request->file('package');
        abort_if($upload === null, 422, 'Update package is required.');

        $directory = storage_path('app/private/updates/incoming');
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Update upload directory could not be prepared.');
        }

        $fileName = 'update-'.bin2hex(random_bytes(12)).'.zip';
        $upload->move($directory, $fileName);
        $packagePath = $directory.'/'.$fileName;

        $manifest = new UpdatePackageManifest(
            (string) $validated['target_version'],
            (string) $validated['minimum_current_version'],
            strtolower((string) $validated['sha256']),
            (string) ($validated['release_notes'] ?? ''),
            $this->packageContainsMigrations($packagePath) || $request->boolean('contains_migrations'),
        );

        try {
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
                ->with('status', 'Update '.$history->to_version.' completed successfully.');
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
        Gate::authorize('system.update');

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
        Gate::authorize('system.update');

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

    private function packageContainsMigrations(string $packagePath): bool
    {
        $archive = new ZipArchive;

        if ($archive->open($packagePath) !== true) {
            return false;
        }

        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);

                if (is_string($name)
                    && str_starts_with(str_replace('\\\\', '/', $name), 'backend/database/migrations/')
                    && str_ends_with($name, '.php')) {
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
