<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;

final class PercentileStatistics
{
    /**
     * Uses linear interpolation between closest ranks:
     * rank = (percentile / 100) * (n - 1).
     *
     * @param  array<int, float|int>  $samples
     */
    public static function percentile(array $samples, float $percentile): float
    {
        self::assertSamples($samples);

        if ($percentile < 0.0 || $percentile > 100.0) {
            throw new InvalidArgumentException('Percentile must be between 0 and 100.');
        }

        $values = array_map(static fn (float|int $value): float => (float) $value, $samples);
        sort($values, SORT_NUMERIC);

        if (count($values) === 1) {
            return $values[0];
        }

        $rank = ($percentile / 100.0) * (count($values) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $values[$lower];
        }

        $weight = $rank - $lower;

        return $values[$lower] + (($values[$upper] - $values[$lower]) * $weight);
    }

    /**
     * @param  array<int, float|int>  $samples
     * @return array{count: int, min: float, max: float, mean: float, p50: float, p95: float, p99: float}
     */
    public static function summarize(array $samples): array
    {
        self::assertSamples($samples);

        $values = array_map(static fn (float|int $value): float => (float) $value, $samples);

        return [
            'count' => count($values),
            'min' => min($values),
            'max' => max($values),
            'mean' => array_sum($values) / count($values),
            'p50' => self::percentile($values, 50.0),
            'p95' => self::percentile($values, 95.0),
            'p99' => self::percentile($values, 99.0),
        ];
    }

    /**
     * Contract helper for cPanel noisy-host handling: calculate the requested
     * percentile per round, then return the median across those round values.
     *
     * @param  array<int, array<int, float|int>>  $rounds
     */
    public static function medianRoundPercentile(array $rounds, float $percentile): float
    {
        if ($rounds === []) {
            throw new InvalidArgumentException('At least one benchmark round is required.');
        }

        $roundValues = [];

        foreach ($rounds as $round) {
            $roundValues[] = self::percentile($round, $percentile);
        }

        return self::percentile($roundValues, 50.0);
    }

    /**
     * @param  array<int, float|int>  $samples
     */
    private static function assertSamples(array $samples): void
    {
        if ($samples === []) {
            throw new InvalidArgumentException('At least one sample is required.');
        }

        foreach ($samples as $sample) {
            $value = (float) $sample;

            if (! is_finite($value)) {
                throw new InvalidArgumentException('Samples must contain only finite numeric values.');
            }
        }
    }
}
