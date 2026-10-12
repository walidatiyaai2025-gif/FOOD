<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProductionLaunchPreflight
{
    public const LAUNCH_CONFIRMATION = 'LAUNCH FOODEX PRODUCTION';

    /** @var list<string> */
    public const REQUIRED_CHECKS = [
        'schema_migrations',
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
     * @param array<string,array{passed:bool,detail:string,source?:string}> $checks
     * @return array{ready:bool,checks:array<string,array{passed:bool,detail:string,source?:string}>,blockers:list<string>}
     */
    public function decide(array $checks): array
    {
        $normalized = [];
        $blockers = [];

        foreach (self::REQUIRED_CHECKS as $code) {
            $candidate = $checks[$code] ?? null;
            $passed = is_array($candidate) && ($candidate['passed'] ?? false) === true;
            $normalized[$code] = [
                'passed' => $passed,
                'detail' => is_array($candidate) && is_string($candidate['detail'] ?? null)
                    ? $candidate['detail']
                    : 'Required evidence is missing.',
                ...(
                    is_array($candidate) && is_string($candidate['source'] ?? null)
                        ? ['source' => $candidate['source']]
                        : []
                ),
            ];

            if (!$passed) {
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
        return $this->decide([
            'schema_migrations' => $this->schemaCheck(),
            'official_seed' => [
                'passed' => class_exists(FoodexLaunchSeeder::class),
                'detail' => class_exists(FoodexLaunchSeeder::class)
                    ? 'Authoritative FOODEX launch seed is installed.'
                    : 'Authoritative FOODEX launch seed is not installed.',
                'source' => '#1330',
            ],
            'product_image_quality' => $this->attestationCheck(
                storage_path('app/private/launch-gate/product-image-quality.json'),
                '#1330',
            ),
            'clean_production_data' => $this->productionDataCheck(),
            'api_customer_contract' => $this->attestationCheck(
                storage_path('app/private/launch-gate/api-customer-contract.json'),
                '#1324-#1329',
            ),
            'customer_ar_en_registry' => $this->attestationCheck(
                storage_path('app/private/launch-gate/customer-ar-en-registry.json'),
                '#1324-#1329',
            ),
            'customer_e2e' => $this->attestationCheck(
                storage_path('app/private/launch-gate/customer-e2e.json'),
                '#1325-#1329',
            ),
            'provider_readiness' => [
                'passed' => config('foodex.launch.provider_ready', false) === true,
                'detail' => config('foodex.launch.provider_ready', false) === true
                    ? 'Production provider readiness is attested without exposing credentials.'
                    : 'Production provider readiness has not been attested.',
                'source' => 'runtime configuration',
            ],
            'mobile_artifacts' => $this->attestationCheck(
                storage_path('app/private/launch-gate/mobile-artifacts.json'),
                'release pipeline',
            ),
            'backup_rollback' => $this->attestationCheck(
                storage_path('app/private/launch-gate/backup-rollback.json'),
                'production operations',
            ),
            'exact_head_required_ci' => $this->attestationCheck(
                storage_path('app/private/launch-gate/exact-head-required-ci.json'),
                'GitHub Required CI',
            ),
        ]);
    }

    /** @param array{ready:bool,checks:array<string,mixed>,blockers:list<string>} $report */
    public function assertLaunchable(array $report): void
    {
        if (($report['ready'] ?? false) !== true || ($report['blockers'] ?? []) !== []) {
            throw ValidationException::withMessages([
                'launch' => __('production_launch.errors.blocked'),
            ]);
        }
    }

    /**
     * Owner approval is audited, but this service deliberately does not deploy
     * code or synthesize missing evidence. The dashboard wiring owns promotion.
     *
     * @param array{ready:bool,checks:array<string,mixed>,blockers:list<string>} $report
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
            ],
            $request,
        );
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function schemaCheck(): array
    {
        $required = ['migrations', 'users', 'stores', 'products', 'product_images', 'orders'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $table): bool => !Schema::hasTable($table),
        ));

        return [
            'passed' => $missing === [],
            'detail' => $missing === []
                ? 'Required production schema tables are present.'
                : 'Missing schema tables: '.implode(', ', $missing),
            'source' => 'database schema',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function productionDataCheck(): array
    {
        $demoOrders = Schema::hasTable('orders')
            ? DB::table('orders')->where('order_number', 'like', 'FOODEX-DEMO-%')->count()
            : 0;
        $demoCustomers = Schema::hasTable('customers')
            ? DB::table('customers')->where('email', 'like', '%@demo.foodex.test')->count()
            : 0;

        $count = $demoOrders + $demoCustomers;

        return [
            'passed' => $count === 0,
            'detail' => $count === 0
                ? 'No known FOODEX demo order/customer markers are present.'
                : $count.' known demo rows are still present.',
            'source' => 'database scan',
        ];
    }

    /** @return array{passed:bool,detail:string,source:string} */
    private function attestationCheck(string $path, string $source): array
    {
        if (!is_file($path)) {
            return [
                'passed' => false,
                'detail' => 'Required signed/generated attestation is missing.',
                'source' => $source,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $passed = is_array($decoded)
            && ($decoded['status'] ?? null) === 'passed'
            && is_string($decoded['source_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40,64}$/', $decoded['source_sha']) === 1;

        return [
            'passed' => $passed,
            'detail' => $passed
                ? 'Attestation passed for the recorded source SHA.'
                : 'Attestation exists but is invalid, stale or incomplete.',
            'source' => $source,
        ];
    }
}
