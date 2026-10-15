<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;

final class QueueSchedulerProbe
{
    /**
     * @param  array<int, int>  $pendingEnqueuedAt
     * @return array{
     *     pending_count:int,
     *     failed_count:int,
     *     oldest_pending_age_seconds:int,
     *     last_successful_execution:int|null,
     *     last_error_summary:string|null
     * }
     */
    public static function backlog(
        array $pendingEnqueuedAt,
        int $failedCount,
        ?int $lastSuccessfulExecution = null,
        ?string $lastErrorSummary = null,
        ?int $now = null,
    ): array {
        if ($failedCount < 0) {
            throw new InvalidArgumentException('Failed queue count must be non-negative.');
        }

        $now ??= time();
        $oldestAge = 0;

        foreach ($pendingEnqueuedAt as $enqueuedAt) {
            if ($enqueuedAt < 0) {
                throw new InvalidArgumentException('Queue timestamps must be non-negative epoch seconds.');
            }

            $oldestAge = max($oldestAge, max(0, $now - $enqueuedAt));
        }

        return [
            'pending_count' => count($pendingEnqueuedAt),
            'failed_count' => $failedCount,
            'oldest_pending_age_seconds' => $oldestAge,
            'last_successful_execution' => $lastSuccessfulExecution,
            'last_error_summary' => $lastErrorSummary,
        ];
    }

    /**
     * @return array{
     *     runtime_seconds:float,
     *     max_runtime_seconds:float,
     *     peak_bytes:int,
     *     peak_mib:float,
     *     status:string
     * }
     */
    public static function measureSlice(callable $work, float $maxRuntimeSeconds = 45.0): array
    {
        if ($maxRuntimeSeconds <= 0.0) {
            throw new InvalidArgumentException('Maximum runtime must be positive.');
        }

        $memory = new MemoryProbe;
        $memory->start();
        $startedAt = hrtime(true);

        $work();

        $runtimeSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $memorySnapshot = $memory->snapshot();

        return [
            'runtime_seconds' => $runtimeSeconds,
            'max_runtime_seconds' => $maxRuntimeSeconds,
            'peak_bytes' => $memorySnapshot['peak_bytes'],
            'peak_mib' => $memorySnapshot['peak_mib'],
            'status' => $runtimeSeconds <= $maxRuntimeSeconds ? 'PASS' : 'FAIL',
        ];
    }
}
