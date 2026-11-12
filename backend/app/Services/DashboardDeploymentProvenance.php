<?php

namespace App\Services;

use App\Models\SystemVersion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only installed Dashboard provenance. Version metadata is not a signed
 * runtime attestation: never report a live deployment as verified by a GitHub
 * commit lookup alone.
 */
final class DashboardDeploymentProvenance
{
    /**
     * @return array{installed_version:?string,source_version:?string,build_version:?string,source_commit:?string,metadata_state:string,github_state:string}
     */
    public function inspect(
        bool $verifyGithub = false,
        ?string $versionFile = null,
        ?string $buildInfoFile = null,
    ): array {
        $installedVersion = null;

        try {
            if (Schema::hasTable('system_versions')) {
                $installed = SystemVersion::query()
                    ->orderByDesc('installed_at')
                    ->orderByDesc('id')
                    ->value('version');

                if (is_string($installed)) {
                    $installedVersion = trim($installed) ?: null;
                }
            }
        } catch (Throwable) {
            // Broken or unavailable database state is unknown, never verified.
        }

        $versionPath = $versionFile ?? base_path('../VERSION');
        $sourceContents = $this->readBoundedFile($versionPath, 128);
        $sourceVersion = is_string($sourceContents) ? trim($sourceContents) : null;
        if ($sourceVersion === '' || ! is_string($sourceVersion)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $sourceVersion) !== 1) {
            $sourceVersion = null;
        }

        $buildPath = $buildInfoFile ?? base_path('../Release/BUILD_INFO.json');
        $build = [];
        $buildContents = $this->readBoundedFile($buildPath, 131072);
        if (is_string($buildContents)) {
            $decoded = json_decode($buildContents, true);
            if (is_array($decoded)) {
                $build = $decoded;
            }
        }

        $buildVersion = is_string($build['version'] ?? null)
            && preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', trim($build['version'])) === 1
                ? trim($build['version']) : null;
        $commit = is_string($build['source_commit'] ?? null)
            ? strtolower(trim($build['source_commit'])) : null;
        if ($commit === null || preg_match('/\A[a-f0-9]{40}\z/D', $commit) !== 1) {
            $commit = null;
        }

        $knownVersions = array_values(array_filter(
            [$installedVersion, $sourceVersion, $buildVersion],
            static fn (?string $version): bool => $version !== null,
        ));
        $hasConflict = count(array_unique($knownVersions)) > 1;
        $complete = count($knownVersions) === 3 && $commit !== null;
        $metadataState = $hasConflict ? 'mismatch'
            : ($complete ? 'consistent' : 'unknown');

        $githubState = 'not_checked';
        if ($verifyGithub) {
            $githubState = $commit === null ? 'unknown' : $this->verifyCommit($commit);
        }

        return [
            'installed_version' => $installedVersion,
            'source_version' => $sourceVersion,
            'build_version' => $buildVersion,
            'source_commit' => $commit,
            'metadata_state' => $metadataState,
            'github_state' => $githubState,
        ];
    }

    /**
     * Treat packaged metadata as untrusted local data. An accidental
     * symlink-to-.env or an oversized manifest must never become an
     * Inspector response, even for privileged accounts.
     */
    private function readBoundedFile(string $path, int $maxBytes): ?string
    {
        try {
            if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $root = realpath(base_path('..'));
            $resolved = realpath($path);
            if ($root === false || $resolved === false
                || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                return null;
            }

            $size = filesize($resolved);
            if ($size === false || $size < 1 || $size > $maxBytes) {
                return null;
            }

            $contents = file_get_contents($resolved);

            return is_string($contents) && strlen($contents) <= $maxBytes
                ? $contents : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function verifyCommit(string $commit): string
    {
        try {
            // A fixed public GitHub repository URL prevents user-driven SSRF.
            // HTTP lookup proves commit existence, NOT integrity of live files.
            $response = Http::timeout(3)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'FOODEX-Dashboard-Provenance',
                ])
                ->get('https://api.github.com/repos/walidatiyaai2025-gif/FOOD/commits/'.$commit);

            if ($response->status() === 404) {
                return 'not_found';
            }

            if (! $response->successful()) {
                return 'unknown';
            }

            return strtolower((string) $response->json('sha')) === $commit
                ? 'found' : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
