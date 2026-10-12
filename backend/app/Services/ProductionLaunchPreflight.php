<?php

namespace App\Services;

use App\Models\User;
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
        $fingerprint = $seed['fingerprint'];
        if ($seed['ready'] !== true || $fingerprint === '') {
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
     *     driver_version:?string,
     *     van_version:?string,
     *     customer_build_number:?int,
     *     driver_build_number:?int,
     *     van_build_number:?int
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

        $sourceCommit = is_string($buildInfo['source_commit'] ?? null)
            && preg_match('/^[a-f0-9]{40,64}$/', $buildInfo['source_commit']) === 1
                ? $buildInfo['source_commit']
                : null;

        return [
            'version' => $version,
            'build_info_version' => is_string($buildInfo['version'] ?? null)
                ? trim($buildInfo['version'])
                : null,
            'source_commit' => $sourceCommit,
            'github_run_number' => is_scalar($buildInfo['github_run_number'] ?? null)
                ? (string) $buildInfo['github_run_number']
                : null,
            'customer_version' => is_string($buildInfo['customer']['version'] ?? null)
                ? trim($buildInfo['customer']['version'])
                : null,
            'driver_version' => is_string($buildInfo['driver']['version'] ?? null)
                ? trim($buildInfo['driver']['version'])
                : null,
            'van_version' => is_string($buildInfo['van']['version'] ?? null)
                ? trim($buildInfo['van']['version'])
                : null,
            'customer_build_number' => is_numeric($buildInfo['customer']['build_number'] ?? null)
                ? (int) $buildInfo['customer']['build_number']
                : null,
            'driver_build_number' => is_numeric($buildInfo['driver']['build_number'] ?? null)
                ? (int) $buildInfo['driver']['build_number']
                : null,
            'van_build_number' => is_numeric($buildInfo['van']['build_number'] ?? null)
                ? (int) $buildInfo['van']['build_number']
                : null,
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

        $attestation = $this->attestationCheck(
            storage_path('app/private/launch-gate/official-seed.json'),
            '#1330',
            $expectedSourceSha,
        );

        return [
            'passed' => $attestation['passed'],
            'detail' => $attestation['passed']
                ? 'Authoritative FOODEX seed completeness is attested for the exact release source.'
                : 'FOODEX seed exists, but exact-release completeness evidence is missing or stale.',
            'source' => '#1330',
        ];
    }

    /**
     * @param array{
     *     version:?string,
     *     build_info_version:?string,
     *     source_commit:?string,
     *     github_run_number:?string,
     *     customer_version:?string,
     *     driver_version:?string,
     *     van_version:?string,
     *     customer_build_number:?int,
     *     driver_build_number:?int,
     *     van_build_number:?int
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
        $appVersionsMatch = $versionMatches
            && $release['customer_version'] === $release['version']
            && $release['driver_version'] === $release['version']
            && $release['van_version'] === $release['version'];
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
                ? 'Release version, source commit, run and all three mobile builds share a valid lineage.'
                : 'Release lineage metadata is missing, inconsistent or incomplete.',
            'source' => 'Release/BUILD_INFO.json + VERSION',
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
                        ->orWhere('order_number', 'like', 'EVIDENCE-%');
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

        $count = $demoOrders + $demoCustomers + $demoUsers;

        return [
            'passed' => $count === 0,
            'detail' => $count === 0
                ? 'No known FOODEX demo/test customer, user or transaction markers are present.'
                : $count.' known demo/test rows are still present.',
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
