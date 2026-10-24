<?php

namespace App\Services;

use App\Http\Controllers\Admin\ProductionDatasetSwitchController;
use App\Models\User;
use Database\Seeders\FoodexCustomerVNextFixtureSeeder;
use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProductionLaunchPreflight
{
    public const LAUNCH_CONFIRMATION = 'LAUNCH FOODEX PRODUCTION';

    /** @var list<string> */
    public const REQUIRED_CHECKS = [
        'schema_migrations',
        'environment_configuration',
        'release_lineage',
        'customer_frozen_lineage',
        'catalog_provenance',
        'reconstructed_image_quality',
        'prelaunch_snapshot',
        'restore_verification',
        'dataset_switch_readiness',
        'official_seed',
        'product_image_quality',
        'clean_production_data',
        'api_customer_contract',
        'customer_ar_en_registry',
        'customer_e2e',
        'provider_readiness',
        'mobile_artifacts',
        'backup_rollback',
        'exact_head_required_ci',
    ];

    /**
     * @param  array<string,mixed>  $checks
     * @return array{ready:bool,checks:array<string,array{passed:bool,detail:string,source?:string}>,blockers:list<string>}
     */
    public function decide(array $checks): array
    {
        $normalized = [];
        $blockers = [];

        foreach (self::REQUIRED_CHECKS as $code) {
            $candidate = $checks[$code] ?? null;
            $passed = is_array($candidate) && ($candidate['passed'] ?? false) === true;
            $normalizedEntry = [
                'passed' => $passed,
                'detail' => is_array($candidate) && is_string($candidate['detail'] ?? null)
                    ? $candidate['detail']
                    : 'Required evidence is missing.',
            ];
            if (is_array($candidate) && is_string($candidate['source'] ?? null)) {
                $normalizedEntry['source'] = $candidate['source'];
            }
            $normalized[$code] = $normalizedEntry;

            if ($passed === false) {
                $blockers[] = $code;
            }
        }

        return [
            'ready' => $blockers === [],
            'checks' => $normalized,
            'blockers' => $blockers,
        ];
    }

    /**
     * Runtime-visible checks only. CI, E2E, image-quality and backup evidence are
     * intentionally file/config attestations produced by their owning lanes.
     *
     * @return array{ready:bool,checks:array<string,array{passed:bool,detail:string,source?:string}>,blockers:list<string>}
     */
    public function runtimeReport(): array
    {
        $release = $this->releaseMetadata();
        $sourceCommit = $release['source_commit'];

        return $this->decide([
            'schema_migrations' => $this->schemaCheck(),
            'environment_configuration' => $this->environmentCheck(),
            'release_lineage' => $this->releaseLineageCheck($release),
            'customer_frozen_lineage' => $this->customerReleaseLineageCheck($sourceCommit, $release['version']),
            'catalog_provenance' => $this->catalogProvenanceCheck(),
            'reconstructed_image_quality' => $this->attestationCheck(
                storage_path('app/private/launch-gate/reconstructed-image-quality.json'),
                '#1382 image manifest',
                $sourceCommit,
            ),
            'prelaunch_snapshot' => $this->snapshotCheck(),
            'restore_verification' => $this->attestationCheck(
                storage_path('app/private/launch-gate/restore-verification.json'),
                '#1384 restore verification',
                $sourceCommit,
            ),
            'dataset_switch_readiness' => $this->datasetSwitchReadinessCheck(),
            'official_seed' => $this->officialSeedCheck($sourceCommit),
            'product_image_quality' => $this->attestationCheck(
                storage_path('app/private/launch-gate/product-image-quality.json'),
                '#1330',
                $sourceCommit,
            ),
            'clean_production_data' => $this->productionDataCheck(),
            'api_customer_contract' => $this->attestationCheck(
                storage_path('app/private/launch-gate/api-customer-contract.json'),
                '#1324-#1329',
                $sourceCommit,
            ),
            'customer_ar_en_registry' => $this->attestationCheck(
                storage_path('app/private/launch-gate/customer-ar-en-registry.json'),
                '#1324-#1329',
                $sourceCommit,
            ),
            'customer_e2e' => $this->attestationCheck(
                storage_path('app/private/launch-gate/customer-e2e.json'),
                '#1325-#1329',
                $sourceCommit,
            ),
            'provider_readiness' => $this->providerReadinessCheck($sourceCommit),
            'mobile_artifacts' => $this->attestationCheck(
                storage_path('app/private/launch-gate/mobile-artifacts.json'),
                'release pipeline',
                $sourceCommit,
            ),
            'backup_rollback' => $this->attestationCheck(
                storage_path('app/private/launch-gate/backup-rollback.json'),
                'production operations',
                $sourceCommit,
            ),
            'exact_head_required_ci' => $this->attestationCheck(
                storage_path('app/private/launch-gate/exact-head-required-ci.json'),
                'GitHub Required CI',
                $sourceCommit,
            ),
        ]);
    }

    /** @param array{ready:bool,checks:array<string,mixed>,blockers:list<string>} $report */
    public function assertLaunchable(array $report): void
    {
        if ($report['ready'] !== true || $report['blockers'] !== []) {
            throw ValidationException::withMessages([
                'launch' => __('production_launch.errors.blocked'),
            ]);
        }
    }

    /**
     * Owner approval is audited, but this service deliberately does not deploy
     * code or synthesize missing evidence. The dashboard wiring owns promotion.
     *
     * @param  array{ready:bool,checks:array<string,mixed>,blockers:list<string>}  $report
     */
    public function approve(
        User $actor,
        Request $request,
        array $report,
        string $confirmation,
        AuditLogger $audit,
    ): void {
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);

        if ($confirmation !== self::LAUNCH_CONFIRMATION) {
            throw ValidationException::withMessages([
                'confirmation' => __('production_launch.errors.confirmation'),
            ]);
        }

        $this->assertLaunchable($report);

        $audit->record(
            'foodex.production_launch.approved',
            $actor,
            null,
            null,
            [
                'required_checks' => self::REQUIRED_CHECKS,
                'evidence_sources' => collect($report['checks'])
                    ->mapWithKeys(static fn (array $check, string $code): array => [
                        $code => $check['source'] ?? null,
                    ])
                    ->all(),
                'release' => $this->releaseMetadata(),
            ],
            $request,
        );
    }

    /**
     * Publish the short-lived trusted pre-reset evidence consumed by #1331.
     * This never performs a reset; #1331 keeps ownership of maintenance mode,
     * backup and deletion safeguards.
     *
     * @param  array{ready:bool,checks:array<string,mixed>,blockers:list<string>}  $report
     */
    public function publishResetPreflightEvidence(array $report): void
    {
        $this->assertLaunchable($report);

        $seed = app(FoodexLaunchSeeder::class)->preflight();
        $fingerprint = trim($seed['fingerprint']);
        if ($seed['ready'] !== true || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw ValidationException::withMessages([
                'launch' => __('production_launch.errors.blocked'),
            ]);
        }

        $path = (string) config('foodex_reset.preflight_path');
        if ($path === '') {
            throw ValidationException::withMessages([
                'launch' => __('production_launch.errors.blocked'),
            ]);
        }

        File::ensureDirectoryExists(dirname($path), 0750, true);
        $written = File::put($path, json_encode([
            'ready' => true,
            'database' => DB::connection()->getDatabaseName(),
            'catalog_fingerprint' => $fingerprint,
            'checked_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        if ($written === false) {
            throw ValidationException::withMessages([
                'launch' => __('production_launch.errors.blocked'),
            ]);
        }

        File::chmod($path, 0600);
    }

    /**
     * @return array{
     *     version:?string,
     *     build_info_version:?string,
     *     source_commit:?string,
     *     github_run_number:?string,
     *     customer_version:?string,
     *     customer_frozen_identity:?string,
     *     customer_frozen_baseline:?string,
     *     driver_version:?string,
     *     van_version:?string,
     *     customer_build_number:?int,
     *     driver_build_number:?int,
     *     van_build_number:?int,
     *     lineage_source:string
     * }
     */
    public function releaseMetadata(): array
    {
        $version = null;
        $versionPath = base_path('../VERSION');
        if (is_file($versionPath)) {
            $candidate = trim((string) file_get_contents($versionPath));
            $version = $candidate !== '' ? $candidate : null;
        }

        $buildInfo = [];
        $buildInfoPath = base_path('../Release/BUILD_INFO.json');
        if (is_file($buildInfoPath)) {
            $decoded = json_decode((string) file_get_contents($buildInfoPath), true);
            if (is_array($decoded)) {
                $buildInfo = $decoded;
            }
        }

        $freeze = [];
        $freezePath = base_path('../docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json');
        if (is_file($freezePath)) {
            $decodedFreeze = json_decode((string) file_get_contents($freezePath), true);
            if (is_array($decodedFreeze) && is_array($decodedFreeze['frozen_customer'] ?? null)) {
                $freeze = $decodedFreeze['frozen_customer'];
            }
        }

        $buildInfoVersion = is_string($buildInfo['version'] ?? null)
            ? trim($buildInfo['version'])
            : null;
        $candidateMode = is_string($version) && $buildInfoVersion !== $version;

        $lineageSource = 'published-build-info';
        $sourceCommit = is_string($buildInfo['source_commit'] ?? null)
            && preg_match('/^[a-f0-9]{40,64}$/', $buildInfo['source_commit']) === 1
                ? $buildInfo['source_commit']
                : null;
        $githubRunNumber = is_scalar($buildInfo['github_run_number'] ?? null)
            ? (string) $buildInfo['github_run_number']
            : null;
        $driverVersion = is_string($buildInfo['driver']['version'] ?? null)
            ? trim($buildInfo['driver']['version'])
            : null;
        $vanVersion = is_string($buildInfo['van']['version'] ?? null)
            ? trim($buildInfo['van']['version'])
            : null;
        $driverBuildNumber = is_numeric($buildInfo['driver']['build_number'] ?? null)
            ? (int) $buildInfo['driver']['build_number']
            : null;
        $vanBuildNumber = is_numeric($buildInfo['van']['build_number'] ?? null)
            ? (int) $buildInfo['van']['build_number']
            : null;

        $customerVersion = is_string($buildInfo['customer']['version'] ?? null)
            ? trim($buildInfo['customer']['version'])
            : null;
        $customerBuildNumber = is_numeric($buildInfo['customer']['build_number'] ?? null)
            ? (int) $buildInfo['customer']['build_number']
            : null;

        if ($candidateMode) {
            $lineageSource = 'candidate-source';
            $buildInfoVersion = $version;

            $candidateSource = config('foodex.release_source_sha');
            $sourceCommit = is_string($candidateSource)
                && preg_match('/^[a-f0-9]{40,64}$/', $candidateSource) === 1
                    ? $candidateSource
                    : null;

            $candidateRun = config('foodex.release_run_number');
            $githubRunNumber = is_scalar($candidateRun) && trim((string) $candidateRun) !== ''
                ? trim((string) $candidateRun)
                : null;

            $customer = $this->mobileSourceIdentity('customer_app');
            $driver = $this->mobileSourceIdentity('driver_app');
            $van = $this->mobileSourceIdentity('van_app');
            $customerVersion = $customer['version'];
            $driverVersion = $driver['version'];
            $vanVersion = $van['version'];
            $customerBuildNumber = $customer['build_number'];
            $driverBuildNumber = $driver['build_number'];
            $vanBuildNumber = $van['build_number'];
        }

        $freezeApplies = $version === '1.0.77';

        return [
            'version' => $version,
            'build_info_version' => $buildInfoVersion,
            'source_commit' => $sourceCommit,
            'github_run_number' => $githubRunNumber,
            'customer_version' => $freezeApplies && is_string($freeze['source_version'] ?? null)
                ? trim($freeze['source_version'])
                : $customerVersion,
            'customer_frozen_identity' => $freezeApplies && is_string($freeze['source_identity'] ?? null)
                ? trim($freeze['source_identity'])
                : null,
            'customer_frozen_baseline' => $freezeApplies && is_string($freeze['baseline_commit'] ?? null)
                ? trim($freeze['baseline_commit'])
                : null,
            'driver_version' => $driverVersion,
            'van_version' => $vanVersion,
            'customer_build_number' => $freezeApplies && is_numeric($freeze['source_build_number'] ?? null)
                ? (int) $freeze['source_build_number']
                : $customerBuildNumber,
            'driver_build_number' => $driverBuildNumber,
            'van_build_number' => $vanBuildNumber,
            'lineage_source' => $lineageSource,
        ];
    }

    /** @return array{version:?string,build_number:?int} */
    private function mobileSourceIdentity(string $appDirectory): array
    {
        $path = base_path('../apps/'.$appDirectory.'/pubspec.yaml');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        if (preg_match('/^version:\\s*(\\d+\\.\\d+\\.\\d+)\\+(\\d+)\\s*$/m', $contents, $matches) !== 1) {
            return ['version' => null, 'build_number' => null];
        }

        return [
            'version' => $matches[1],
            'build_number' => (int) $matches[2],
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function customerReleaseLineageCheck(?string $expectedSourceSha, ?string $version): array
    {
        if ($version !== '1.0.77') {
            return [
                'passed' => true,
                'detail' => 'The 1.0.77 Customer freeze contract does not apply to this release.',
                'source' => 'release version policy',
            ];
        }

        return $this->customerFrozenLineageCheck($expectedSourceSha);
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function customerFrozenLineageCheck(?string $expectedSourceSha): array
    {
        $path = base_path('../docs/release/FOODEX_1_0_77_CUSTOMER_FREEZE.json');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $frozen = is_array($manifest) && is_array($manifest['frozen_customer'] ?? null)
            ? $manifest['frozen_customer']
            : [];
        $contract = is_array($manifest)
            && ($manifest['release_version'] ?? null) === '1.0.77'
            && ($frozen['baseline_commit'] ?? null) === '4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d'
            && ($frozen['policy'] ?? null) === 'zero-diff'
            && ($frozen['lineage_mode'] ?? null) === 'carried_forward'
            && ($frozen['source_identity'] ?? null) === '1.0.76+76';

        $attestation = $this->attestationCheck(
            storage_path('app/private/launch-gate/customer-frozen-lineage.json'),
            'FOODEX 1.0.77 Customer zero-diff certification',
            $expectedSourceSha,
        );
        $passed = $contract && $attestation['passed'];

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Customer is certified byte-identical to frozen baseline 4de5a24b (1.0.76+76 carried forward).'
                : 'Customer frozen-lineage contract or exact-source zero-diff attestation is missing/stale.',
            'source' => 'FOODEX_1_0_77_CUSTOMER_FREEZE.json + exact-source attestation',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function catalogProvenanceCheck(): array
    {
        $path = database_path('seeders/fixtures/foodex-launch-catalog-provenance.json');
        $ledger = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $entries = is_array($ledger) && is_array($ledger['entries'] ?? null) ? $ledger['entries'] : [];
        $products = array_values(array_unique(array_filter(array_map(
            static fn ($entry): ?string => is_array($entry) && is_string($entry['product_identifier'] ?? null)
                ? $entry['product_identifier']
                : null,
            $entries,
        ))));
        $inferred = array_values(array_filter(
            $entries,
            static fn ($entry): bool => is_array($entry) && ($entry['provenance_state'] ?? null) === 'inferred',
        ));
        $inferredComplete = $inferred !== [] && collect($inferred)->every(
            static fn (array $entry): bool => array_key_exists('final_value', $entry)
                && is_string($entry['rationale'] ?? null)
                && trim($entry['rationale']) !== '',
        );
        $passed = is_array($ledger)
            && ($ledger['release'] ?? null) === '1.0.77'
            && count($products) === 25
            && $inferredComplete;

        return [
            'passed' => $passed,
            'detail' => $passed
                ? count($entries).' catalog field records cover 25 products; '.count($inferred).' inferred fields are explicitly disclosed.'
                : 'Catalog provenance ledger is missing, incomplete, wrong-release, or hides inferred values.',
            'source' => 'foodex-launch-catalog-provenance.json',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function snapshotCheck(): array
    {
        try {
            $status = app(ProductionDatasetSnapshot::class)->status();
        } catch (\Throwable) {
            $status = [];
        }
        $state = $status['state'] ?? ProductionDatasetSnapshot::NO_SNAPSHOT;
        $passed = ($status['verified'] ?? false) === true
            && is_string($status['snapshot_id'] ?? null)
            && in_array($state, [
                ProductionDatasetSnapshot::SNAPSHOT_READY,
                ProductionDatasetSnapshot::LEGACY_RESTORED,
            ], true);

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Verified pre-launch snapshot is available; state='.$state.'.'
                : 'A verified pre-launch snapshot is required before owner approval.',
            'source' => 'ProductionDatasetSnapshot runtime metadata',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function datasetSwitchReadinessCheck(): array
    {
        $snapshot = $this->snapshotCheck();
        $controller = class_exists(ProductionDatasetSwitchController::class);
        $passed = $controller && $snapshot['passed'];

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Reversible Production/Legacy switch is installed and backed by a verified snapshot.'
                : 'Dataset switch is unavailable or has no verified rollback snapshot.',
            'source' => 'ProductionDatasetSwitchController + ProductionDatasetSnapshot',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function officialSeedCheck(?string $expectedSourceSha): array
    {
        if (! class_exists(FoodexLaunchSeeder::class)) {
            return [
                'passed' => false,
                'detail' => 'Authoritative FOODEX launch seed is not installed.',
                'source' => '#1330',
            ];
        }

        try {
            $runtime = app(FoodexLaunchSeeder::class)->preflight();
        } catch (\Throwable) {
            return [
                'passed' => false,
                'detail' => 'Authoritative FOODEX seed runtime preflight failed.',
                'source' => '#1330',
            ];
        }

        $fingerprint = trim($runtime['fingerprint']);
        $runtimeReady = $runtime['ready'] === true
            && preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1;

        $attestation = $this->attestationCheck(
            storage_path('app/private/launch-gate/official-seed.json'),
            '#1330',
            $expectedSourceSha,
        );
        $passed = $runtimeReady && $attestation['passed'];

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Authoritative FOODEX seed runtime preflight and exact-release evidence both pass.'
                : 'FOODEX seed runtime preflight is not ready or exact-release evidence is missing or stale.',
            'source' => '#1330 runtime preflight + attestation',
        ];
    }

    /**
     * @param array{
     *     version:?string,
     *     build_info_version:?string,
     *     source_commit:?string,
     *     github_run_number:?string,
     *     customer_version:?string,
     *     customer_frozen_identity:?string,
     *     customer_frozen_baseline:?string,
     *     driver_version:?string,
     *     van_version:?string,
     *     customer_build_number:?int,
     *     driver_build_number:?int,
     *     van_build_number:?int,
     *     lineage_source:string
     * } $release
     * @return array{passed:bool,detail:string,source:string}
     */
    private function releaseLineageCheck(array $release): array
    {
        $versionMatches = is_string($release['version'])
            && $release['version'] !== ''
            && $release['version'] === $release['build_info_version'];
        $hasSource = is_string($release['source_commit']) && $release['source_commit'] !== '';
        $hasRun = is_string($release['github_run_number']) && $release['github_run_number'] !== '';
        $freezeApplies = $release['version'] === '1.0.77';
        $mobileVersions = [
            $release['customer_version'],
            $release['driver_version'],
            $release['van_version'],
        ];
        $mobileVersionsSynchronized = collect($mobileVersions)->every(
            static fn (?string $mobileVersion): bool => is_string($mobileVersion) && $mobileVersion !== '',
        ) && count(array_unique($mobileVersions)) === 1;
        $dashboardUpdateNotes = is_string($release['version']) && $release['version'] !== ''
            ? base_path('../docs/release/UPDATE_NOTES_'.$release['version'].'.md')
            : null;
        $dashboardUpdateApplies = ! $freezeApplies
            && $release['lineage_source'] === 'candidate-source'
            && is_string($dashboardUpdateNotes)
            && is_file($dashboardUpdateNotes)
            && $mobileVersionsSynchronized
            && $release['customer_version'] !== $release['version'];

        $customerMatches = $freezeApplies
            ? (($release['customer_frozen_identity'] ?? null) === '1.0.76+76'
                && ($release['customer_frozen_baseline'] ?? null) === '4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d')
            : ($dashboardUpdateApplies
                ? $mobileVersionsSynchronized
                : $release['customer_version'] === $release['version']);
        $appVersionsMatch = $versionMatches
            && $customerMatches
            && ($dashboardUpdateApplies
                ? $mobileVersionsSynchronized
                : ($release['driver_version'] === $release['version']
                    && $release['van_version'] === $release['version']));
        $builds = [
            $release['customer_build_number'],
            $release['driver_build_number'],
            $release['van_build_number'],
        ];
        $hasBuilds = count(array_filter(
            $builds,
            static fn (?int $build): bool => is_int($build) && $build > 0,
        )) === 3;

        $passed = $versionMatches && $appVersionsMatch && $hasSource && $hasRun && $hasBuilds;

        return [
            'passed' => $passed,
            'detail' => $passed
                ? ($freezeApplies
                    ? 'Release 1.0.77 lineage is valid; frozen Customer 1.0.76+76 is carried forward without version mutation.'
                    : ($dashboardUpdateApplies
                        ? 'Dashboard-only release lineage is valid; Customer, Driver and Van identities remain synchronized and intentionally unchanged.'
                        : 'Release lineage is synchronized across Dashboard, Customer, Driver and Van identities.'))
                : 'Release lineage metadata is missing, inconsistent or incomplete.',
            'source' => $release['lineage_source'] === 'candidate-source'
                ? ($freezeApplies
                    ? 'exact candidate source + mobile source identities + frozen Customer contract'
                    : ($dashboardUpdateApplies
                        ? 'exact Dashboard candidate source + synchronized unchanged mobile identities + update notes'
                        : 'exact candidate source + synchronized mobile source identities'))
                : 'Release/BUILD_INFO.json + VERSION',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function schemaCheck(): array
    {
        $required = ['migrations', 'users', 'stores', 'products', 'product_images', 'orders'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $table): bool => Schema::hasTable($table) === false,
        ));

        if ($missing !== []) {
            return [
                'passed' => false,
                'detail' => 'Missing schema tables: '.implode(', ', $missing),
                'source' => 'database schema',
            ];
        }

        $migrationFiles = collect(File::allFiles(database_path('migrations')))
            ->filter(static fn ($file): bool => $file->getExtension() === 'php')
            ->map(static fn ($file): string => $file->getFilenameWithoutExtension())
            ->values()
            ->all();
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_values(array_diff($migrationFiles, $ran));

        return [
            'passed' => $pending === [],
            'detail' => $pending === []
                ? 'Required production schema is present and no migrations are pending.'
                : 'Pending migrations: '.implode(', ', $pending),
            'source' => 'database schema + migration ledger',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function environmentCheck(): array
    {
        $checks = [
            'production_environment' => app()->environment('production'),
            'launch_enabled' => config('foodex.launch.enabled', false) === true,
            'app_key' => is_string(config('app.key')) && trim((string) config('app.key')) !== '',
            'debug_disabled' => config('app.debug') === false,
            'mobile_trial_username_login_disabled' => config('foodex.mobile_trial_username_login') === false,
            'database_configured' => is_string(config('database.default'))
                && trim((string) config('database.default')) !== '',
        ];

        $failed = array_keys(array_filter(
            $checks,
            static fn (bool $passed): bool => $passed === false,
        ));

        return [
            'passed' => $failed === [],
            'detail' => $failed === []
                ? 'Required production environment safeguards are configured.'
                : 'Missing production environment safeguards: '.implode(', ', $failed),
            'source' => 'runtime configuration',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function providerReadinessCheck(?string $expectedSourceSha): array
    {
        $runtimeReady = config('foodex.launch.provider_ready', false) === true;
        $attestation = $this->attestationCheck(
            storage_path('app/private/launch-gate/provider-readiness.json'),
            'production providers',
            $expectedSourceSha,
        );
        $passed = $runtimeReady && $attestation['passed'];

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Production provider readiness is explicitly enabled and attested for the exact release source.'
                : 'Production providers require explicit runtime opt-in and exact-release readiness evidence.',
            'source' => 'runtime configuration + provider-readiness attestation',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function productionDataCheck(): array
    {
        $demoOrders = Schema::hasTable('orders')
            ? DB::table('orders')
                ->where(function ($query): void {
                    $query->where('order_number', 'like', 'FOODEX-DEMO-%')
                        ->orWhere('order_number', 'like', 'EVIDENCE-%')
                        ->orWhere('order_number', 'like', FoodexCustomerVNextFixtureSeeder::ORDER_PREFIX.'%');
                })
                ->count()
            : 0;

        $demoCustomers = Schema::hasTable('customers')
            ? DB::table('customers')
                ->where(function ($query): void {
                    $query->where('email', 'like', '%@demo.foodex.test')
                        ->orWhere('email', 'like', '%@example.test');
                })
                ->count()
            : 0;

        $demoUsers = Schema::hasTable('users')
            ? DB::table('users')
                ->where(function ($query): void {
                    $query->where('email', 'like', '%@demo.foodex.test')
                        ->orWhere('email', 'like', '%@example.test');
                })
                ->count()
            : 0;

        $qaProducts = Schema::hasTable('products')
            ? DB::table('products')
                ->where('sku', 'like', FoodexCustomerVNextFixtureSeeder::PRODUCT_PREFIX.'%')
                ->count()
            : 0;
        $qaStores = Schema::hasTable('stores')
            ? DB::table('stores')
                ->where('code', 'like', FoodexCustomerVNextFixtureSeeder::STORE_PREFIX.'%')
                ->count()
            : 0;
        $qaTranslations = Schema::hasTable('translations')
            ? DB::table('translations')
                ->where('key', 'like', FoodexCustomerVNextFixtureSeeder::TRANSLATION_PREFIX.'%')
                ->count()
            : 0;
        $qaCategories = Schema::hasTable('categories')
            ? DB::table('categories')->where('slug', 'like', 'fdx-qa-c%')->count()
            : 0;

        $count = $demoOrders
            + $demoCustomers
            + $demoUsers
            + $qaProducts
            + $qaStores
            + $qaTranslations
            + $qaCategories;

        return [
            'passed' => $count === 0,
            'detail' => $count === 0
                ? 'No known FOODEX demo/test identity, transaction or QA fixture markers are present.'
                : $count.' known demo/test or QA fixture rows are still present.',
            'source' => 'database scan',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function attestationCheck(
        string $path,
        string $source,
        ?string $expectedSourceSha = null,
    ): array {
        if (is_file($path) === false) {
            return [
                'passed' => false,
                'detail' => 'Required signed/generated attestation is missing.',
                'source' => $source,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $sourceSha = is_array($decoded) && is_string($decoded['source_sha'] ?? null)
            ? $decoded['source_sha']
            : null;
        $passed = is_array($decoded)
            && ($decoded['status'] ?? null) === 'passed'
            && is_string($sourceSha)
            && preg_match('/^[a-f0-9]{40,64}$/', $sourceSha) === 1
            && is_string($expectedSourceSha)
            && hash_equals($expectedSourceSha, $sourceSha);

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Attestation passed for the exact production release source SHA.'
                : 'Attestation is missing, invalid, stale or belongs to a different source SHA.',
            'source' => $source,
        ];
    }
}
