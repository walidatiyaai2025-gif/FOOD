<?php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Performance\Support\HttpJourneyBenchmarkRunner;
use Tests\TestCase;

final class HttpJourneyBenchmarkRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_smoke_profile_measures_http_journey_queries_memory_and_percentiles(): void
    {
        Route::get('/__performance/runner-smoke', function () {
            DB::table('store_types')->count();

            return response()->json(['ok' => true]);
        });

        $runner = new HttpJourneyBenchmarkRunner;
        $result = $runner->run(
            'runner.smoke',
            'lightweight_authenticated_read',
            function (): void {
                $this->getJson('/__performance/runner-smoke')->assertOk()->assertJson(['ok' => true]);
            },
            'smoke',
        );

        $this->assertSame('runner.smoke', $result['id']);
        $this->assertSame('smoke', $result['profile']);
        $this->assertSame(3, $result['rounds']);
        $this->assertSame(9, $result['sample_count']);
        $this->assertGreaterThanOrEqual(1, $result['queries']['count']);
        $this->assertGreaterThan(0.0, $result['metrics']['p50_ms']);
        $this->assertGreaterThanOrEqual($result['metrics']['p50_ms'], $result['metrics']['p95_ms']);
        $this->assertGreaterThanOrEqual($result['metrics']['p95_ms'], $result['metrics']['p99_ms']);
        $this->assertGreaterThan(0, $result['memory']['peak_bytes']);
        $this->assertCount(3, $result['round_detail']);
    }

    public function test_every_profile_uses_at_least_three_rounds(): void
    {
        foreach (HttpJourneyBenchmarkRunner::profiles() as $profile) {
            $this->assertGreaterThanOrEqual(3, $profile['rounds']);
            $this->assertGreaterThan(0, $profile['samples_per_round']);
        }
    }

    public function test_unknown_profile_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new HttpJourneyBenchmarkRunner)->run('bad', 'dashboard', static fn () => null, 'unknown');
    }
}
