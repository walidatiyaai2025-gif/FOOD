<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductionLaunchController;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductionLaunchPreflight;
use App\Services\ProductionLaunchReport;
use Database\Seeders\CoreReferenceSeeder;
use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionLaunchGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config([
            'foodex.release_source_sha' => str_repeat('c', 40),
            'foodex.release_run_number' => '1388',
        ]);

        Route::middleware('web')->get(
            '/__production-launch-test',
            [ProductionLaunchController::class, 'index'],
        );
        Route::middleware('web')->post(
            '/__production-launch-test',
            [ProductionLaunchController::class, 'store'],
        );
    }

    public function test_only_active_super_admin_can_open_launch_surface(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/__production-launch-test')
            ->assertOk()
            ->assertSee('data-foodex-production-launch="true"', false)
            ->assertSee('Production Launch Gate');

        $regular = User::query()->create([
            'name' => 'Regular Launch User',
            'email' => 'regular-launch@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($regular)
            ->get('/__production-launch-test')
            ->assertForbidden();
    }

    public function test_runtime_surface_is_fail_closed_when_required_evidence_is_missing(): void
    {
        $owner = $this->superAdmin();

        $response = $this->actingAs($owner)->get('/__production-launch-test');

        $response
            ->assertOk()
            ->assertSee('data-launch-blocked="true"', false)
            ->assertSee('Launch remains blocked.')
            ->assertSee('Exact-head Required CI')
            ->assertSee('Customer / Driver / Van builds');

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('launch');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_wrong_password_and_wrong_confirmation_never_create_approval(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
                'current_password' => 'wrong',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($owner)
            ->post('/__production-launch-test', [
                'confirmation' => 'LAUNCH',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_arabic_surface_is_rtl_and_system_copy_is_localized(): void
    {
        $owner = $this->superAdmin('ar');

        $this->actingAs($owner)
            ->get('/__production-launch-test')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('بوابة إطلاق الإنتاج')
            ->assertSee('فحوصات جاهزية الإنتاج')
            ->assertDontSee('Launch remains blocked.');
    }

    public function test_pending_migration_keeps_schema_check_blocked(): void
    {
        $path = database_path('migrations/9999_12_31_235959_launch_gate_pending.php');
        file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}
    public function down(): void {}
};
PHP);

        try {
            $report = app(ProductionLaunchPreflight::class)->runtimeReport();

            $this->assertFalse($report['checks']['schema_migrations']['passed']);
            $this->assertStringContainsString(
                '9999_12_31_235959_launch_gate_pending',
                $report['checks']['schema_migrations']['detail'],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_attestation_must_match_exact_release_source_sha(): void
    {
        $sourceSha = app(ProductionLaunchPreflight::class)->releaseMetadata()['source_commit'];
        $this->assertIsString($sourceSha);

        $directory = storage_path('app/private/launch-gate');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/product-image-quality.json';

        try {
            file_put_contents($path, json_encode([
                'status' => 'passed',
                'source_sha' => str_repeat('a', 40),
            ], JSON_THROW_ON_ERROR));

            $stale = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertFalse($stale['checks']['product_image_quality']['passed']);

            file_put_contents($path, json_encode([
                'status' => 'passed',
                'source_sha' => $sourceSha,
            ], JSON_THROW_ON_ERROR));

            $current = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertTrue($current['checks']['product_image_quality']['passed']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_official_seed_requires_runtime_readiness_even_with_matching_attestation(): void
    {
        $sourceSha = app(ProductionLaunchPreflight::class)->releaseMetadata()['source_commit'];
        $this->assertIsString($sourceSha);

        $directory = storage_path('app/private/launch-gate');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/official-seed.json';

        try {
            file_put_contents($path, json_encode([
                'status' => 'passed',
                'source_sha' => $sourceSha,
            ], JSON_THROW_ON_ERROR));

            $this->app->instance(FoodexLaunchSeeder::class, new class
            {
                public function preflight(): array
                {
                    return [
                        'ready' => false,
                        'fingerprint' => hash('sha256', 'not-ready'),
                    ];
                }
            });

            $blocked = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertFalse($blocked['checks']['official_seed']['passed']);

            $this->app->instance(FoodexLaunchSeeder::class, new class
            {
                public function preflight(): array
                {
                    return [
                        'ready' => true,
                        'fingerprint' => hash('sha256', 'ready'),
                    ];
                }
            });

            $ready = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertTrue($ready['checks']['official_seed']['passed']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_provider_readiness_requires_runtime_opt_in_and_exact_release_attestation(): void
    {
        $sourceSha = app(ProductionLaunchPreflight::class)->releaseMetadata()['source_commit'];
        $this->assertIsString($sourceSha);

        $directory = storage_path('app/private/launch-gate');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/provider-readiness.json';

        try {
            file_put_contents($path, json_encode([
                'status' => 'passed',
                'source_sha' => $sourceSha,
            ], JSON_THROW_ON_ERROR));

            config(['foodex.launch.provider_ready' => false]);
            $disabled = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertFalse($disabled['checks']['provider_readiness']['passed']);

            config(['foodex.launch.provider_ready' => true]);
            $enabled = app(ProductionLaunchPreflight::class)->runtimeReport();
            $this->assertTrue($enabled['checks']['provider_readiness']['passed']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_successful_owner_approval_audits_release_lineage(): void
    {
        $owner = $this->superAdmin();
        $service = app(ProductionLaunchPreflight::class);
        $checks = [];

        foreach (ProductionLaunchPreflight::REQUIRED_CHECKS as $code) {
            $checks[$code] = [
                'passed' => true,
                'detail' => 'passed',
                'source' => 'test-evidence',
            ];
        }

        $request = Request::create('/admin/settings/production-launch', 'POST');
        $request->setUserResolver(static fn (): User => $owner);

        $service->approve(
            $owner,
            $request,
            $service->decide($checks),
            ProductionLaunchPreflight::LAUNCH_CONFIRMATION,
            app(AuditLogger::class),
        );

        $audit = AuditLog::query()
            ->where('event', 'foodex.production_launch.approved')
            ->firstOrFail();

        $this->assertSame(
            ProductionLaunchPreflight::REQUIRED_CHECKS,
            $audit->after['required_checks'],
        );
        $this->assertArrayHasKey('release', $audit->after);
        $this->assertArrayHasKey('version', $audit->after['release']);
        $this->assertArrayHasKey('source_commit', $audit->after['release']);
        $this->assertSame($owner->id, $audit->user_id);
    }

    public function test_real_settings_route_exposes_launch_gate_to_super_admin(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee(route('admin.production-launch.index'), false);

        $this->actingAs($owner)
            ->get('/admin/settings/production-launch')
            ->assertOk()
            ->assertSee('data-foodex-production-launch="true"', false);
    }

    public function test_green_launch_gate_publishes_fresh_reset_preflight_contract(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-launch-reset-');
        $this->assertIsString($path);
        config(['foodex_reset.preflight_path' => $path]);

        $fingerprint = hash('sha256', 'launch-reset-contract');
        $this->app->instance(FoodexLaunchSeeder::class, new class($fingerprint)
        {
            public function __construct(private readonly string $fingerprint) {}

            public function preflight(): array
            {
                return [
                    'ready' => true,
                    'fingerprint' => $this->fingerprint,
                ];
            }
        });

        $checks = [];
        foreach (ProductionLaunchPreflight::REQUIRED_CHECKS as $code) {
            $checks[$code] = [
                'passed' => true,
                'detail' => 'passed',
                'source' => 'test-evidence',
            ];
        }

        try {
            $service = app(ProductionLaunchPreflight::class);
            $service->publishResetPreflightEvidence($service->decide($checks));

            $evidence = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($evidence);
            $this->assertTrue($evidence['ready']);
            $this->assertSame(DB::connection()->getDatabaseName(), $evidence['database']);
            $this->assertSame($fingerprint, $evidence['catalog_fingerprint']);
            $this->assertIsInt($evidence['checked_at']);
            $this->assertGreaterThanOrEqual(now()->subMinute()->timestamp, $evidence['checked_at']);
            $this->assertLessThanOrEqual(now()->timestamp, $evidence['checked_at']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_1_0_77_reversible_launch_checks_are_mandatory_and_fail_closed(): void
    {
        $report = app(ProductionLaunchPreflight::class)->decide([]);

        foreach ([
            'customer_frozen_lineage',
            'catalog_provenance',
            'reconstructed_image_quality',
            'prelaunch_snapshot',
            'restore_verification',
            'dataset_switch_readiness',
        ] as $code) {
            $this->assertContains($code, ProductionLaunchPreflight::REQUIRED_CHECKS);
            $this->assertFalse($report['checks'][$code]['passed']);
            $this->assertContains($code, $report['blockers']);
        }

        $this->assertFalse($report['ready']);
    }

    public function test_launch_report_discloses_catalog_inferences_and_is_source_bound(): void
    {
        $payload = app(ProductionLaunchReport::class)
            ->build(app(ProductionLaunchPreflight::class));

        $this->assertSame(trim((string) file_get_contents(base_path('../VERSION'))), $payload['release']);
        $this->assertTrue($payload['stale_if_source_sha_changes']);
        $this->assertArrayHasKey('source_sha', $payload);
        $this->assertArrayHasKey('all_fields', $payload['catalog']);
        $this->assertNotEmpty($payload['catalog']['inferred_fields']);
        $this->assertGreaterThan(0, $payload['catalog']['inferred_count']);
        $this->assertArrayHasKey('snapshot', $payload['rollback']);
        $this->assertArrayHasKey('blockers', $payload);
    }

    public function test_release_metadata_uses_exact_candidate_source_and_current_release_identity(): void
    {
        $release = app(ProductionLaunchPreflight::class)->releaseMetadata();
        $version = trim((string) file_get_contents(base_path('../VERSION')));
        $customer = $this->mobileIdentity('customer');
        $driver = $this->mobileIdentity('driver');
        $van = $this->mobileIdentity('van');

        $this->assertSame($version, $release['version']);
        $this->assertSame($version, $release['build_info_version']);

        $buildInfoPath = base_path('../Release/BUILD_INFO.json');
        $buildInfo = is_file($buildInfoPath)
            ? json_decode((string) file_get_contents($buildInfoPath), true)
            : null;
        $publishedCurrentRelease = is_array($buildInfo)
            && ($buildInfo['version'] ?? null) === $version;

        if ($publishedCurrentRelease) {
            $this->assertSame($buildInfo['source_commit'] ?? null, $release['source_commit']);
            $this->assertSame((string) ($buildInfo['github_run_number'] ?? ''), $release['github_run_number']);
            $this->assertSame('published-build-info', $release['lineage_source']);
        } else {
            $this->assertSame(str_repeat('c', 40), $release['source_commit']);
            $this->assertSame('1388', $release['github_run_number']);
            $this->assertSame('candidate-source', $release['lineage_source']);
        }

        if ($version === '1.0.77') {
            $this->assertSame('1.0.76', $release['customer_version']);
            $this->assertSame(76, $release['customer_build_number']);
            $this->assertSame('1.0.76+76', $release['customer_frozen_identity']);
            $this->assertSame(
                '4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d',
                $release['customer_frozen_baseline'],
            );
        } else {
            $this->assertSame($customer['version'], $release['customer_version']);
            $this->assertSame($customer['build'], $release['customer_build_number']);
            $this->assertNull($release['customer_frozen_identity']);
            $this->assertNull($release['customer_frozen_baseline']);
        }

        $this->assertSame($driver['version'], $release['driver_version']);
        $this->assertSame($driver['build'], $release['driver_build_number']);
        $this->assertSame($van['version'], $release['van_version']);
        $this->assertSame($van['build'], $release['van_build_number']);

        $report = app(ProductionLaunchPreflight::class)->runtimeReport();
        $this->assertTrue($report['checks']['release_lineage']['passed']);
        $this->assertTrue($report['checks']['customer_frozen_lineage']['passed']);
    }

    /** @return array{version:string,build:int} */
    private function mobileIdentity(string $app): array
    {
        $contents = (string) file_get_contents(base_path("../apps/{$app}_app/pubspec.yaml"));
        $this->assertSame(1, preg_match('/^version:\\s*(\\d+\\.\\d+\\.\\d+)\\+(\\d+)\\s*$/m', $contents, $matches));

        return [
            'version' => $matches[1],
            'build' => (int) $matches[2],
        ];
    }

    private function superAdmin(string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => 'FOODEX Launch Owner',
            'email' => 'launch-owner-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
