<?php

namespace App\Services;

final class ProductionLaunchReport
{
    public function build(ProductionLaunchPreflight $preflight): array
    {
        $release = $preflight->releaseMetadata();
        $gate = $preflight->runtimeReport();
        $ledgerPath = database_path('seeders/fixtures/foodex-launch-catalog-provenance.json');
        $ledger = is_file($ledgerPath) ? json_decode((string) file_get_contents($ledgerPath), true) : null;
        $entries = is_array($ledger) && is_array($ledger['entries'] ?? null) ? $ledger['entries'] : [];
        $inferred = array_values(array_filter(
            $entries,
            static fn ($entry): bool => is_array($entry) && ($entry['provenance_state'] ?? null) === 'inferred',
        ));

        $imageManifestPath = database_path('seeders/fixtures/foodex-launch-image-manifest.json');
        $imageManifest = is_file($imageManifestPath)
            ? json_decode((string) file_get_contents($imageManifestPath), true)
            : null;

        try {
            $snapshot = app(ProductionDatasetSnapshot::class)->status();
        } catch (\Throwable) {
            $snapshot = ['state' => ProductionDatasetSnapshot::NO_SNAPSHOT, 'verified' => false];
        }

        $sourceSha = $release['source_commit'] ?? null;

        return [
            'schema_version' => 1,
            'release' => $release['version'],
            'source_sha' => $sourceSha,
            'generated_at' => now()->toIso8601String(),
            'stale_if_source_sha_changes' => true,
            'release_identity' => $release,
            'customer_frozen_identity' => $release['version'] === '1.0.77'
                ? $this->jsonFile(base_path('../docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json'))
                : null,
            'gate' => $gate,
            'catalog' => [
                'release' => is_array($ledger) ? ($ledger['release'] ?? null) : null,
                'all_fields' => $entries,
                'inferred_fields' => $inferred,
                'inferred_count' => count($inferred),
            ],
            'images' => [
                'manifest_present' => is_array($imageManifest),
                'manifest' => $imageManifest,
                'quality_gate' => $gate['checks']['reconstructed_image_quality'] ?? null,
            ],
            'rollback' => [
                'snapshot' => $snapshot,
                'prelaunch_snapshot_gate' => $gate['checks']['prelaunch_snapshot'] ?? null,
                'restore_verification_gate' => $gate['checks']['restore_verification'] ?? null,
                'dataset_switch_gate' => $gate['checks']['dataset_switch_readiness'] ?? null,
            ],
            'blockers' => $gate['blockers'],
        ];
    }

    private function jsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
