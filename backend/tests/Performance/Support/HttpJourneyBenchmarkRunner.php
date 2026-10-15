<?php

namespace Tests\Performance\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class HttpJourneyBenchmarkRunner
{
    /**
     * @var array<string, array{warmups:int, rounds:int, samples_per_round:int}>
     */
    private const PROFILES = [
        'smoke' => ['warmups' => 1, 'rounds' => 3, 'samples_per_round' => 3],
        'standard' => ['warmups' => 2, 'rounds' => 3, 'samples_per_round' => 10],
        'heavy' => ['warmups' => 3, 'rounds' => 3, 'samples_per_round' => 20],
        'cpanel' => ['warmups' => 3, 'rounds' => 3, 'samples_per_round' => 20],
    ];

    /**
     * @param callable(): mixed $journey
     * @return array<string, mixed>
     */
    public function run(
        string $id,
        string $workloadClass,
        callable $journey,
        string $profileName = 'smoke',
    ): array {
        $profile = self::PROFILES[$profileName] ?? null;

        if ($profile === null) {
            throw new InvalidArgumentException('Unsupported benchmark profile: '.$profileName);
        }

        if ($id === '') {
            throw new InvalidArgumentException('Benchmark scenario id is required.');
        }

        for ($warmup = 0; $warmup < $profile['warmups']; $warmup++) {
            $journey();
        }

        $allDurations = [];
        $allQueryCounts = [];
        $allMemoryPeaks = [];
        $roundDurations = [];
        $rounds = [];
        $slowQueries = new SlowQueryProbe;

        for ($round = 1; $round <= $profile['rounds']; $round++) {
            $durations = [];
            $queryCounts = [];
            $memoryPeaks = [];

            for ($sample = 1; $sample <= $profile['samples_per_round']; $sample++) {
                DB::flushQueryLog();
                DB::enableQueryLog();

                $memory = new MemoryProbe;
                $memory->start();
                $startedAt = hrtime(true);

                try {
                    $journey();
                } finally {
                    $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
                    $queryLog = DB::getQueryLog();
                    DB::disableQueryLog();
                }

                $queryProbe = new QueryCountProbe;

                foreach ($queryLog as $query) {
                    $sql = (string) ($query['query'] ?? '');
                    $timeMs = (float) ($query['time'] ?? 0.0);
                    $queryProbe->record($sql);
                    $slowQueries->record($sql, $timeMs, (string) config('database.default', 'default'));
                }

                $memorySnapshot = $memory->snapshot();
                $querySnapshot = $queryProbe->snapshot();

                $durations[] = $durationMs;
                $queryCounts[] = $querySnapshot['count'];
                $memoryPeaks[] = $memorySnapshot['peak_bytes'];

                $allDurations[] = $durationMs;
                $allQueryCounts[] = $querySnapshot['count'];
                $allMemoryPeaks[] = $memorySnapshot['peak_bytes'];
            }

            $roundSummary = PercentileStatistics::summarize($durations);
            $roundDurations[] = $durations;
            $rounds[] = [
                'round' => $round,
                'sample_count' => count($durations),
                'p50_ms' => $roundSummary['p50'],
                'p95_ms' => $roundSummary['p95'],
                'p99_ms' => $roundSummary['p99'],
                'max_queries' => max($queryCounts),
                'max_peak_bytes' => max($memoryPeaks),
            ];
        }

        if ($allDurations === []) {
            throw new RuntimeException('Benchmark produced no measured samples.');
        }

        $durationSummary = PercentileStatistics::summarize($allDurations);
        $querySummary = PercentileStatistics::summarize($allQueryCounts);
        $peakBytes = max($allMemoryPeaks);
        $memoryLimitBytes = MemoryProbe::parseMemoryLimit((string) ini_get('memory_limit'));

        return [
            'id' => $id,
            'workload_class' => $workloadClass,
            'profile' => $profileName,
            'warmups' => $profile['warmups'],
            'rounds' => $profile['rounds'],
            'samples_per_round' => $profile['samples_per_round'],
            'sample_count' => count($allDurations),
            'metrics' => [
                'p50_ms' => $durationSummary['p50'],
                'p95_ms' => $durationSummary['p95'],
                'p99_ms' => $durationSummary['p99'],
                'median_round_p95_ms' => PercentileStatistics::medianRoundPercentile(
                    $roundDurations,
                    95.0,
                ),
            ],
            'queries' => [
                'count' => (int) max($allQueryCounts),
                'p50_count' => $querySummary['p50'],
                'max_count' => (int) max($allQueryCounts),
                ...$slowQueries->snapshot(),
            ],
            'memory' => [
                'peak_bytes' => $peakBytes,
                'peak_mib' => $peakBytes / (1024 * 1024),
                ...MemoryProbe::evaluate($peakBytes, $memoryLimitBytes),
            ],
            'round_detail' => $rounds,
        ];
    }

    /**
     * @return array<string, array{warmups:int, rounds:int, samples_per_round:int}>
     */
    public static function profiles(): array
    {
        return self::PROFILES;
    }
}
