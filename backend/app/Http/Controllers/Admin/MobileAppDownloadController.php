<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MobileReleaseArtifact;
use App\Services\MobileReleaseArtifactMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MobileAppDownloadController extends Controller
{
    private const APPS = [
        'customer' => 'Customer',
        'driver' => 'Driver',
        'van' => 'Van',
    ];

    public function __construct(private readonly MobileReleaseArtifactMirror $mirror) {}

    public function customer(): RedirectResponse
    {
        return $this->adminDownload('customer');
    }

    public function driver(): RedirectResponse
    {
        return $this->adminDownload('driver');
    }

    public function van(): RedirectResponse
    {
        return $this->adminDownload('van');
    }

    public function status(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $app = $this->requestedApp($request);
        if ($app !== null) {
            $artifact = $this->mirror->statusPayloadForApp($app);

            return response()->json([
                'version' => $artifact['version'] ?? $this->mirror->currentVersionForApp($app),
                'artifacts' => [$artifact],
            ]);
        }

        $version = $this->mirror->currentVersion();

        return response()->json([
            'version' => $version,
            'artifacts' => $this->mirror->statusPayload($version),
        ]);
    }

    public function prepare(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $app = $this->requestedApp($request);
        if ($app !== null) {
            $version = $this->mirror->currentVersionForApp($app);
            $this->mirror->ensureScheduled($version);

            return response()->json([
                'version' => $version,
                'artifacts' => [$this->mirror->statusPayloadForApp($app)],
            ], 202);
        }

        $version = $this->mirror->currentVersionForApp($app);
        $this->mirror->ensureScheduled($version);

        return response()->json([
            'version' => $version,
            'artifacts' => $this->mirror->statusPayload($version),
        ], 202);
    }

    public function retry(Request $request): JsonResponse
    {
        Gate::authorize('platform.manage');

        $app = $this->requestedApp($request);
        if ($app !== null) {
            $version = $this->mirror->currentVersionForApp($app);
            $this->mirror->retryVersion($version);

            return response()->json([
                'version' => $version,
                'artifacts' => [$this->mirror->statusPayloadForApp($app)],
            ], 202);
        }

        $version = $this->mirror->retryVersion();

        return response()->json([
            'version' => $version,
            'artifacts' => $this->mirror->statusPayload($version),
        ], 202);
    }

    public function latest(string $app): BinaryFileResponse
    {
        return $this->download($app, $this->mirror->currentVersionForApp($app));
    }

    public function versioned(string $app, string $version): BinaryFileResponse
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            abort(404);
        }

        return $this->download($app, $version);
    }

    private function adminDownload(string $app): RedirectResponse
    {
        Gate::authorize('platform.manage');

        $version = $this->mirror->ensureScheduled();
        $artifact = MobileReleaseArtifact::query()
            ->where('app', $app)
            ->where('version', $version)
            ->first();

        if ($artifact instanceof MobileReleaseArtifact && $this->isLocallyReady($artifact)) {
            return redirect()->route('public.mobile-apps.versioned', [
                'app' => $app,
                'version' => $version,
            ]);
        }

        return redirect()->route('admin.administration.index', [
            'download_app' => $app,
        ])->with('status', __('admin.mobile_apps.preparing'));
    }

    private function download(string $app, string $version): BinaryFileResponse
    {
        $label = self::APPS[$app] ?? null;
        if ($label === null) {
            abort(404);
        }

        $artifact = MobileReleaseArtifact::query()
            ->where('app', $app)
            ->where('version', $version)
            ->first();

        if (! $artifact instanceof MobileReleaseArtifact || ! $this->isLocallyReady($artifact)) {
            $this->mirror->ensureScheduled($version);

            abort(503, 'FOODEX APK is still being prepared on this server.');
        }

        $path = storage_path('app/private/'.$artifact->local_path);
        $expectedBytes = (int) ($artifact->expected_bytes ?? 0);

        if (! is_file($path) || $expectedBytes <= 0 || filesize($path) !== $expectedBytes) {
            $artifact->forceFill([
                'status' => 'failed',
                'local_path' => null,
                'last_error' => 'Local APK file is missing or has the wrong size.',
            ])->save();

            $this->mirror->retryVersion($version);

            abort(503, 'FOODEX APK local mirror requires repair.');
        }

        $filename = 'FOODEX-'.$label.'-'.$version.'.apk';
        if ($artifact->filename !== $filename) {
            throw new RuntimeException('Local FOODEX APK metadata does not match the requested release.');
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'X-Content-Type-Options' => 'nosniff',
            'X-FOODEX-Release-Version' => $version,
            'X-FOODEX-Artifact-SHA256' => (string) $artifact->sha256,
            'X-FOODEX-Artifact-Source' => 'local-mirror',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function requestedApp(Request $request): ?string
    {
        $app = $request->input('app');

        if ($app === null || $app === '') {
            return null;
        }

        if (! is_string($app) || ! array_key_exists($app, self::APPS)) {
            abort(422, 'Unsupported FOODEX mobile application.');
        }

        return $app;
    }

    private function isLocallyReady(MobileReleaseArtifact $artifact): bool
    {
        if (! $artifact->isReady()) {
            return false;
        }

        $path = storage_path('app/private/'.$artifact->local_path);

        return is_file($path);
    }
}
