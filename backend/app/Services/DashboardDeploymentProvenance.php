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
        $sourceVersion = is_file($versionPath) && is_readable($versionPath)
            ? trim((string) file_get_contents($versionPath))
            : null;
        if ($sourceVersion === '') {
            $sourceVersion = null;
        }

        $buildPath = $buildInfoFile ?? base_path('../Release/BUILD_INFO.json');
        $build = [];
        if (is_file($buildPath) && is_readable($buildPath)) {
            $decoded = json_decode((string) file_get_contents($buildPath), true);
            if (is_array($decoded)) {
                $build = $decoded;
            }
        }

        $buildVersion = is_string($build['version'] ?? null)
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
