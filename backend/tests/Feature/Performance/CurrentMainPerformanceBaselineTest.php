<?php

namespace Tests\Feature\Performance;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\EnterprisePerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Performance\Support\EnterprisePerformanceLineage;
use Tests\Performance\Support\HttpJourneyBenchmarkRunner;
use Tests\TestCase;

final class CurrentMainPerformanceBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_SOURCE_HEAD = '23ef53fc81b4fb2d3dfa1e77824afad9f4113d0c';

    public function test_current_main_smoke_baseline_emits_canonical_numeric_evidence(): void
    {
        putenv('FOODEX_PERF_PROFILE=smoke');
        $_ENV['FOODEX_PERF_PROFILE'] = 'smoke';
        $_SERVER['FOODEX_PERF_PROFILE'] = 'smoke';

        $lineage = EnterprisePerformanceLineage::resolve(self::BASE_SOURCE_HEAD);

        $this->seed(EnterprisePerformanceSeeder::class);

        $admin = User::query()->create([
            'name' => 'Enterprise Performance Super Admin',
            'email' => 'enterprise-performance-super-admin@example.test',
            'password' => 'performance-only',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAs($admin);

        $storeId = (int) DB::table('stores')
            ->where('code', 'like', 'PERF-%')
            ->orderBy('id')
            ->value('id');

        $this->assertGreaterThan(0, $storeId);

        $runner = new HttpJourneyBenchmarkRunner;

        $dashboard = $runner->run(
            'admin.dashboard',
            'dashboard',
            function (mixed $context): void {
                $this->assertNull($context);
                $this->get('/admin/b2b/dashboard')->assertOk();
            },
            'smoke',
        );

        $orders = $runner->run(
            'admin.orders.index',
            'paginated_crud',
            function (mixed $context) use ($storeId): void {
                $this->assertNull($context);
                $this->get('/admin/operations/orders?channel=b2c&store_id='.$storeId.'&per_page=25')
                    ->assertOk();
            },
            'smoke',
        );

        $report = $runner->run(
            'admin.reports.orders',
            'bounded_interactive_report',
            function (mixed $context) use ($storeId): void {
                $this->assertNull($context);
                $this->get(
                    '/admin/reports?report=orders&store_id='.$storeId
                    .'&from=2026-01-01&to=2026-12-31&per_page=25'
                )->assertOk();
            },
            'smoke',
        );

        $fixtureManifestPath = storage_path(
            'app/private/enterprise-performance/fixture-manifest.json'
        );
        $this->assertFileExists($fixtureManifestPath);

        $fixtureManifest = json_decode(
            File::get($fixtureManifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $evidence = [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'source_head' => $lineage['source_head'],
            'harness_head' => $this->harnessHead($lineage['source_head']),
            'baseline_head' => $lineage['baseline_head'],
            'profile' => 'smoke',
            'environment' => [
                'php' => PHP_VERSION,
                'database' => DB::getDriverName().' '.$this->databaseVersion(),
                'runner' => getenv('GITHUB_ACTIONS') === 'true'
                    ? 'github-actions'
                    : 'phpunit-local',
                'memory_limit' => (string) ini_get('memory_limit'),
                'cpanel_reference' => false,
            ],
            'dataset' => $fixtureManifest['actual'],
            'scenarios' => array_map(
                fn (array $result): array => $this->scenarioEvidence($result, $lineage['mode']),
                [$dashboard, $orders, $report],
            ),
            'summary' => [
                'status' => 'PASS',
                'failed_scenarios' => [],
                'warnings' => [
                    $lineage['mode'] === 'baseline'
                        ? 'Regression anchor only: absolute cPanel guardrails are not asserted.'
                        : 'Candidate smoke comparison only: absolute cPanel guardrails are not asserted.',
                    'Smoke fixture only: representative heavy certification remains mandatory.',
                ],
            ],
        ];

        $path = storage_path(
            'app/private/enterprise-performance/current-main-smoke-evidence.json'
        );
        File::ensureDirectoryExists(dirname($path));
        File::put(
            $path,
            json_encode(
                $evidence,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL,
        );

        $this->assertSame($lineage['source_head'], $evidence['source_head']);
        $this->assertSame($lineage['baseline_head'], $evidence['baseline_head']);
        $this->assertSame('smoke', $evidence['profile']);
        $this->assertFalse($evidence['environment']['cpanel_reference']);
        $this->assertCount(3, $evidence['scenarios']);

        fwrite(
            STDOUT,
            PHP_EOL.'FOODEX_PERF_EVIDENCE_JSON='
            .json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .PHP_EOL,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function scenarioEvidence(array $result, string $mode): array
    {
        return [
            'id' => $result['id'],
            'workload_class' => $result['workload_class'],
            'rounds' => $result['rounds'],
            'metrics' => $result['metrics'],
            'queries' => [
                'count' => $result['queries']['count'],
                'slowest' => $result['queries']['slowest'],
                'investigation_threshold_ms' => $result['queries']['investigation_threshold_ms'],
                'flagged_query_count' => $result['queries']['flagged_query_count'],
            ],
            'memory' => [
                'peak_bytes' => $result['memory']['peak_bytes'],
                'peak_mib' => $result['memory']['peak_mib'],
            ],
            'baseline' => null,
            'queue' => null,
            'verdict' => [
                'status' => 'PASS',
                'reasons' => [$mode === 'baseline' ? 'baseline_observation_only' : 'candidate_measurement'],
                'exception' => null,
            ],
        ];
    }

    private function harnessHead(string $fallback): string
    {
        foreach (['FOODEX_PERF_HARNESS_HEAD', 'GITHUB_SHA'] as $key) {
            $value = strtolower(trim((string) getenv($key)));

            if (preg_match('/^[0-9a-f]{40,64}$/', $value) === 1) {
                return $value;
            }
        }

        return $fallback;
    }

    private function databaseVersion(): string
    {
        $row = DB::getDriverName() === 'sqlite'
            ? DB::selectOne('select sqlite_version() as version')
            : DB::selectOne('select version() as version');

        return (string) ($row->version ?? 'unknown');
    }
}
