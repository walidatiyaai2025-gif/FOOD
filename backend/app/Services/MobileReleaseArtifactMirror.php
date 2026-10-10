<?php

namespace App\Services;

use App\Jobs\MirrorMobileReleaseArtifacts;
use App\Models\MobileReleaseArtifact;
use App\Models\SystemVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class MobileReleaseArtifactMirror
{
    private const RELEASE_BASE_URL = 'https://github.com/walidatiyaai2025-gif/FOOD/releases/download';

    private const APPS = [
        'customer' => 'Customer',
        'driver' => 'Driver',
        'van' => 'Van',
    ];

    public function currentVersion(): string
    {
        $version = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (! is_string($version) || $version === '') {
            $version = trim((string) @file_get_contents(base_path('../VERSION')));
        }

        $this->assertVersion($version);

        return $version;
    }

    public function ensureScheduled(?string $version = null): string
    {
        $version ??= $this->currentVersion();
        $this->assertVersion($version);
        $this->ensureRecords($version);

        MobileReleaseArtifact::query()
            ->where('version', $version)
            ->whereIn('status', ['downloading', 'verifying'])
            ->whereNotNull('started_at')
            ->where('started_at', '<', now()->subMinutes(20))
            ->update([
                'status' => 'failed',
                'last_error' => 'Previous APK mirror attempt became stale and will be retried.',
                'updated_at' => now(),
            ]);

        MobileReleaseArtifact::query()
            ->where('version', $version)
            ->where('status', 'ready')
            ->get()
            ->each(function (MobileReleaseArtifact $artifact): void {
                if (! is_string($artifact->local_path)
                    || $artifact->local_path === ''
                    || ! is_file(storage_path('app/private/'.$artifact->local_path))) {
                    $artifact->forceFill([
                        'status' => 'failed',
                        'local_path' => null,
                        'last_error' => 'Verified local APK is missing and will be mirrored again.',
                    ])->save();
                }
            });

        $needsSync = MobileReleaseArtifact::query()
            ->where('version', $version)
            ->whereIn('status', ['pending', 'failed'])
            ->exists();

        if ($needsSync) {
            MirrorMobileReleaseArtifacts::dispatch($version);
        }

        return $version;
    }

    /** @return Collection<int, MobileReleaseArtifact> */
    public function artifactsForVersion(string $version): Collection
    {
        $this->assertVersion($version);
        $this->ensureRecords($version);

        return MobileReleaseArtifact::query()
            ->where('version', $version)
            ->orderByRaw("CASE app WHEN 'customer' THEN 1 WHEN 'driver' THEN 2 WHEN 'van' THEN 3 ELSE 4 END")
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function statusPayload(string $version): array
    {
        return $this->artifactsForVersion($version)
            ->map(fn (MobileReleaseArtifact $artifact): array => $this->serialize($artifact))
            ->values()
            ->all();
    }

    public function retryVersion(?string $version = null): string
    {
        $version ??= $this->currentVersion();
        $this->assertVersion($version);
        $this->ensureRecords($version);

        MobileReleaseArtifact::query()
            ->where('version', $version)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'last_error' => null,
                'downloaded_bytes' => 0,
                'updated_at' => now(),
            ]);

        return $this->ensureScheduled($version);
    }

    public function syncVersion(string $version): void
    {
        $this->assertVersion($version);
        $this->ensureRecords($version);

        $manifestResponse = Http::acceptJson()
            ->timeout(30)
            ->retry(2, 500)
            ->get(self::RELEASE_BASE_URL.'/v'.$version.'/LATEST_RELEASE.json');

        if (! $manifestResponse->successful()) {
            $this->failVersion($version, 'FOODEX release manifest is unavailable.');
            throw new RuntimeException('FOODEX release manifest is unavailable.');
        }

        $manifest = $manifestResponse->json();
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== $version) {
            $this->failVersion($version, 'FOODEX release manifest does not match the installed version.');
            throw new RuntimeException('FOODEX release manifest does not match the installed version.');
        }

        $manifestApps = $manifest['android_apps'] ?? null;
        if (! is_array($manifestApps)) {
            $this->failVersion($version, 'FOODEX release manifest does not contain Android artifacts.');
            throw new RuntimeException('FOODEX release manifest does not contain Android artifacts.');
        }

        $sourceCommit = is_string($manifest['source_commit'] ?? null)
            ? $manifest['source_commit']
            : null;

        foreach (self::APPS as $app => $label) {
            $entry = collect($manifestApps)->first(
                static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['app'] ?? null) === $app,
            );

            $filename = 'FOODEX-'.$label.'-'.$version.'.apk';

            if (
                ! is_array($entry)
                || ($entry['version'] ?? null) !== $version
                || ($entry['file'] ?? null) !== $filename
                || ! is_numeric($entry['bytes'] ?? null)
                || (int) $entry['bytes'] <= 0
                || ! is_string($entry['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $entry['sha256']) !== 1
            ) {
                $this->failArtifact($app, $version, 'Release manifest entry is invalid or incomplete.');
                throw new RuntimeException('Release manifest entry is invalid for '.$app.'.');
            }

            $this->syncArtifact(
                $app,
                $version,
                $filename,
                (int) $entry['bytes'],
                $entry['sha256'],
                $sourceCommit,
            );
        }
    }

    private function syncArtifact(
        string $app,
        string $version,
        string $filename,
        int $expectedBytes,
        string $expectedSha256,
        ?string $sourceCommit,
    ): void {
        $artifact = MobileReleaseArtifact::query()
            ->where('app', $app)
            ->where('version', $version)
            ->firstOrFail();

        $relativePath = 'mobile-releases/'.$version.'/'.$filename;
        $directory = storage_path('app/private/mobile-releases/'.$version);
        $finalPath = storage_path('app/private/'.$relativePath);
        $partialPath = $finalPath.'.part';

        if (
            $artifact->status === 'ready'
            && is_file($finalPath)
            && filesize($finalPath) === $expectedBytes
            && hash_file('sha256', $finalPath) === $expectedSha256
        ) {
            return;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not prepare FOODEX mobile release storage.');
        }

        @unlink($partialPath);

        $artifact->forceFill([
            'filename' => $filename,
            'status' => 'downloading',
            'expected_bytes' => $expectedBytes,
            'downloaded_bytes' => 0,
            'sha256' => $expectedSha256,
            'source_commit' => $sourceCommit,
            'local_path' => null,
            'attempts' => ((int) $artifact->attempts) + 1,
            'last_error' => null,
            'started_at' => now(),
            'ready_at' => null,
        ])->save();

        $lastSavedPercent = -1;

        try {
            $response = Http::timeout(600)
                ->withOptions([
                    'sink' => $partialPath,
                    'progress' => function (
                        int|float $downloadTotal,
                        int|float $downloadedBytes
                    ) use ($artifact, $expectedBytes, &$lastSavedPercent): void {
                        $total = $downloadTotal > 0 ? (int) $downloadTotal : $expectedBytes;
                        $downloaded = max(0, (int) $downloadedBytes);
                        $percent = $total > 0
                            ? min(99, (int) floor(($downloaded / $total) * 100))
                            : 0;

                        if ($percent === $lastSavedPercent && $downloaded < $expectedBytes) {
                            return;
                        }

                        if ($lastSavedPercent >= 0 && $percent < $lastSavedPercent + 2 && $downloaded < $expectedBytes) {
                            return;
                        }

                        $lastSavedPercent = $percent;
                        $artifact->forceFill([
                            'downloaded_bytes' => min($downloaded, $expectedBytes),
                        ])->save();
                    },
                ])
                ->get(self::RELEASE_BASE_URL.'/v'.$version.'/'.$filename);

            if (! $response->successful()) {
                throw new RuntimeException('FOODEX APK artifact is unavailable.');
            }

            if ((! is_file($partialPath) || filesize($partialPath) === 0) && $response->body() !== '') {
                if (file_put_contents($partialPath, $response->body()) === false) {
                    throw new RuntimeException('Could not persist downloaded FOODEX APK bytes.');
                }
            }

            if (! is_file($partialPath)) {
                throw new RuntimeException('FOODEX APK artifact was not persisted locally.');
            }

            clearstatcache(true, $partialPath);
            $artifact->forceFill([
                'status' => 'verifying',
                'downloaded_bytes' => is_file($partialPath) ? filesize($partialPath) : 0,
            ])->save();

            $actualBytes = filesize($partialPath);
            $actualSha256 = hash_file('sha256', $partialPath);

            if ($actualBytes !== $expectedBytes) {
                throw new RuntimeException('FOODEX APK size verification failed.');
            }

            if (! is_string($actualSha256) || ! hash_equals($expectedSha256, $actualSha256)) {
                throw new RuntimeException('FOODEX APK checksum verification failed.');
            }

            if (! @rename($partialPath, $finalPath)) {
                throw new RuntimeException('Could not publish the verified FOODEX APK locally.');
            }

            $artifact->forceFill([
                'status' => 'ready',
                'downloaded_bytes' => $expectedBytes,
                'local_path' => $relativePath,
                'last_error' => null,
                'ready_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            @unlink($partialPath);
            $artifact->forceFill([
                'status' => 'failed',
                'local_path' => null,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();

            throw $exception;
        }
    }

    private function ensureRecords(string $version): void
    {
        foreach (self::APPS as $app => $label) {
            MobileReleaseArtifact::query()->firstOrCreate(
                ['app' => $app, 'version' => $version],
                [
                    'filename' => 'FOODEX-'.$label.'-'.$version.'.apk',
                    'status' => 'pending',
                    'downloaded_bytes' => 0,
                ],
            );
        }
    }

    private function failVersion(string $version, string $message): void
    {
        MobileReleaseArtifact::query()
            ->where('version', $version)
            ->where('status', '!=', 'ready')
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr($message, 0, 4000),
                'updated_at' => now(),
            ]);
    }

    private function failArtifact(string $app, string $version, string $message): void
    {
        MobileReleaseArtifact::query()
            ->where('app', $app)
            ->where('version', $version)
            ->update([
                'status' => 'failed',
                'last_error' => mb_substr($message, 0, 4000),
                'updated_at' => now(),
            ]);
    }

    /** @return array<string, mixed> */
    private function serialize(MobileReleaseArtifact $artifact): array
    {
        $expected = (int) ($artifact->expected_bytes ?? 0);
        $downloaded = (int) ($artifact->downloaded_bytes ?? 0);
        $progress = match ($artifact->status) {
            'ready' => 100,
            'verifying' => 99,
            default => $expected > 0
                ? min(99, (int) floor(($downloaded / $expected) * 100))
                : 0,
        };

        return [
            'app' => $artifact->app,
            'version' => $artifact->version,
            'filename' => $artifact->filename,
            'status' => $artifact->status,
            'progress_percent' => $progress,
            'expected_bytes' => $artifact->expected_bytes,
            'downloaded_bytes' => $artifact->downloaded_bytes,
            'sha256' => $artifact->sha256,
            'source_commit' => $artifact->source_commit,
            'attempts' => $artifact->attempts,
            'last_error' => $artifact->last_error,
            'ready_at' => $artifact->ready_at?->toIso8601String(),
            'download_url' => $artifact->isReady()
                && is_file(storage_path('app/private/'.$artifact->local_path))
                ? route('public.mobile-apps.versioned', [
                    'app' => $artifact->app,
                    'version' => $artifact->version,
                ])
                : null,
        ];
    }

    private function assertVersion(string $version): void
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('FOODEX mobile release version is invalid.');
        }
    }
}
