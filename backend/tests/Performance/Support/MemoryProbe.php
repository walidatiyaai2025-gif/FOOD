<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;

final class MemoryProbe
{
    private const MIB = 1024 * 1024;

    private int $startBytes = 0;

    public function start(): void
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $this->startBytes = memory_get_usage(true);
    }

    /**
     * @return array{start_bytes: int, peak_bytes: int, peak_mib: float}
     */
    public function snapshot(): array
    {
        $peakBytes = memory_get_peak_usage(true);

        return [
            'start_bytes' => $this->startBytes,
            'peak_bytes' => $peakBytes,
            'peak_mib' => $peakBytes / self::MIB,
        ];
    }

    public static function parseMemoryLimit(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return null;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?)b?$/i', $value, $matches)) {
            throw new InvalidArgumentException('Unsupported PHP memory_limit value: '.$value);
        }

        $number = (float) $matches[1];
        $unit = strtolower($matches[2]);
        $multiplier = match ($unit) {
            'k' => 1024,
            'm' => self::MIB,
            'g' => self::MIB * 1024,
            't' => self::MIB * 1024 * 1024,
            default => 1,
        };

        return (int) round($number * $multiplier);
    }

    /**
     * @return array{
     *     target_mib: int,
     *     target_pass: bool,
     *     configured_limit_bytes: int|null,
     *     configured_limit_fraction: float|null,
     *     configured_limit_fraction_pass: bool
     * }
     */
    public static function evaluate(int $peakBytes, ?int $configuredLimitBytes, bool $backgroundJob = false): array
    {
        $targetMib = $backgroundJob ? 128 : 96;
        $fraction = $configuredLimitBytes === null || $configuredLimitBytes <= 0
            ? null
            : $peakBytes / $configuredLimitBytes;

        return [
            'target_mib' => $targetMib,
            'target_pass' => $peakBytes <= ($targetMib * self::MIB),
            'configured_limit_bytes' => $configuredLimitBytes,
            'configured_limit_fraction' => $fraction,
            'configured_limit_fraction_pass' => $fraction === null || $fraction <= 0.75,
        ];
    }
}
