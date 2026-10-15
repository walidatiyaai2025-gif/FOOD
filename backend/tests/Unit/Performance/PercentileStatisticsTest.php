<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\PercentileStatistics;

final class PercentileStatisticsTest extends TestCase
{
    public function test_it_summarizes_linear_interpolated_percentiles(): void
    {
        $summary = PercentileStatistics::summarize([10, 20, 30, 40]);

        $this->assertSame(4, $summary['count']);
        $this->assertSame(10.0, $summary['min']);
        $this->assertSame(40.0, $summary['max']);
        $this->assertSame(25.0, $summary['mean']);
        $this->assertSame(25.0, $summary['p50']);
        $this->assertEqualsWithDelta(38.5, $summary['p95'], 0.000001);
        $this->assertEqualsWithDelta(39.7, $summary['p99'], 0.000001);
    }

    public function test_single_sample_is_stable_for_all_percentiles(): void
    {
        foreach ([0.0, 50.0, 95.0, 99.0, 100.0] as $percentile) {
            $this->assertSame(123.45, PercentileStatistics::percentile([123.45], $percentile));
        }
    }

    public function test_median_round_percentile_matches_cpanel_contract_shape(): void
    {
        $rounds = [
            [100, 110, 120, 130, 140],
            [95, 105, 115, 125, 135],
            [105, 115, 125, 135, 145],
        ];

        $roundP95 = array_map(
            static fn (array $round): float => PercentileStatistics::percentile($round, 95.0),
            $rounds
        );

        $this->assertSame(
            PercentileStatistics::percentile($roundP95, 50.0),
            PercentileStatistics::medianRoundPercentile($rounds, 95.0)
        );
    }

    #[DataProvider('invalidInputProvider')]
    public function test_invalid_input_fails_closed(array $samples, float $percentile): void
    {
        $this->expectException(InvalidArgumentException::class);

        PercentileStatistics::percentile($samples, $percentile);
    }

    /**
     * @return array<string, array{0: array<int, float|int>, 1: float}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'empty samples' => [[], 95.0],
            'negative percentile' => [[1, 2], -0.1],
            'over 100 percentile' => [[1, 2], 100.1],
            'positive infinity' => [[1, INF], 95.0],
        ];
    }
}
