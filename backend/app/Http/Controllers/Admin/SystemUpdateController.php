<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Updater\UpdateManager;
use App\Domain\Updater\UpdatePackageManifest;
use App\Http\Controllers\Controller;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class SystemUpdateController extends Controller
{
    public function __construct(private readonly UpdateManager $manager) {}

    public function index(Request $request): View
    {
        Gate::authorize('system.update');

        return view('admin.system-update', [
            'currentVersion' => $this->currentVersion(),
            'historyRows' => UpdateHistory::query()->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('system.update');

        $validated = $request->validate([
            'package' => ['required', 'file', 'max:512000'],
            'target_version' => ['required', 'string', 'max:64'],
            'minimum_current_version' => ['required', 'string', 'max:64'],
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
            $request->boolean('contains_migrations'),
        );

        try {
            $history = $this->manager->execute(
                $manifest,
                $this->currentVersion(),
                $packagePath,
                $user,
            );

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

    private function currentVersion(): string
    {
        $version = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $version = trim((string) @file_get_contents(base_path('../VERSION')));

        if ($version === '') {
            throw new RuntimeException('Current FOODEX version could not be determined.');
        }

        return $version;
    }
}
