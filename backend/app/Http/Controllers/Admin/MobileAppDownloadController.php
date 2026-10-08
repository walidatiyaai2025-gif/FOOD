<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MobileAppDownloadController extends Controller
{
    private const RELEASE_BASE_URL = 'https://github.com/walidatiyaai2025-gif/FOOD/releases/download';

    private const APPS = [
        'customer' => 'Customer',
        'driver' => 'Driver',
        'van' => 'Van',
    ];

    public function customer(): RedirectResponse
    {
        Gate::authorize('platform.manage');

        return redirect()->route('public.mobile-apps.latest', ['app' => 'customer']);
    }

    public function driver(): RedirectResponse
    {
        Gate::authorize('platform.manage');

        return redirect()->route('public.mobile-apps.latest', ['app' => 'driver']);
    }

    public function van(): RedirectResponse
    {
        Gate::authorize('platform.manage');

        return redirect()->route('public.mobile-apps.latest', ['app' => 'van']);
    }

    public function latest(string $app): BinaryFileResponse
    {
        return $this->download($app, $this->currentVersion());
    }

    public function versioned(string $app, string $version): BinaryFileResponse
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            abort(404);
        }

        return $this->download($app, $version);
    }

    private function download(string $app, string $version): BinaryFileResponse
    {
        $label = self::APPS[$app] ?? null;
        if ($label === null) {
            abort(404);
        }

        $manifestResponse = Http::acceptJson()
            ->timeout(30)
            ->retry(2, 250)
            ->get(self::RELEASE_BASE_URL.'/v'.$version.'/LATEST_RELEASE.json');

        if (! $manifestResponse->successful()) {
            abort(502, 'FOODEX release manifest is unavailable.');
        }

        $manifest = $manifestResponse->json();
        $entry = is_array($manifest) ? ($manifest[$app] ?? null) : null;
        $filename = 'FOODEX-'.$label.'-'.$version.'.apk';

        if (
            ! is_array($entry)
            || ($manifest['version'] ?? null) !== $version
            || ($entry['file'] ?? null) !== $filename
            || ! is_string($entry['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $entry['sha256']) !== 1
        ) {
            abort(502, 'FOODEX release manifest does not match the requested APK.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'foodex-apk-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not allocate temporary APK download storage.');
        }

        try {
            $artifactResponse = Http::timeout(180)
                ->retry(2, 500)
                ->withOptions(['sink' => $temporaryPath])
                ->get(self::RELEASE_BASE_URL.'/v'.$version.'/'.$filename);

            if (! $artifactResponse->successful()) {
                @unlink($temporaryPath);
                abort(502, 'FOODEX APK artifact is unavailable.');
            }

            if (filesize($temporaryPath) === 0 && $artifactResponse->body() !== '') {
                file_put_contents($temporaryPath, $artifactResponse->body());
            }

            $actualSha256 = hash_file('sha256', $temporaryPath);
            if (! is_string($actualSha256) || ! hash_equals($entry['sha256'], $actualSha256)) {
                @unlink($temporaryPath);
                abort(502, 'FOODEX APK checksum verification failed.');
            }

            if (isset($entry['bytes']) && is_numeric($entry['bytes']) && filesize($temporaryPath) !== (int) $entry['bytes']) {
                @unlink($temporaryPath);
                abort(502, 'FOODEX APK size verification failed.');
            }

            return response()
                ->download($temporaryPath, $filename, [
                    'Content-Type' => 'application/vnd.android.package-archive',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-FOODEX-Release-Version' => $version,
                    'X-FOODEX-Artifact-SHA256' => $actualSha256,
                    'Cache-Control' => 'public, max-age=300',
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $exception) {
            @unlink($temporaryPath);
            throw $exception;
        }
    }

    private function currentVersion(): string
    {
        $version = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (! is_string($version) || $version === '') {
            $version = trim((string) @file_get_contents(base_path('../VERSION')));
        }

        if ($version === '' || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('Current FOODEX version could not be determined for APK download.');
        }

        return $version;
    }
}
