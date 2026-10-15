<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\QueueSchedulerProbe;

final class QueueSchedulerProbeTest extends TestCase
{
    public function test_backlog_snapshot_reports_oldest_age_and_health_counts(): void
    {
        $now = 10_000;

        $snapshot = QueueSchedulerProbe::backlog(
            pendingEnqueuedAt: [$now - 10, $now - 90, $now - 30],
            failedCount: 2,
            lastSuccessfulExecution: $now - 5,
            lastErrorSummary: 'provider timeout',
            now: $now,
        );

        $this->assertSame(3, $snapshot['pending_count']);
        $this->assertSame(2, $snapshot['failed_count']);
        $this->assertSame(90, $snapshot['oldest_pending_age_seconds']);
        $this->assertSame($now - 5, $snapshot['last_successful_execution']);
        $this->assertSame('provider timeout', $snapshot['last_error_summary']);
    }

    public function test_empty_backlog_has_zero_oldest_age(): void
    {
        $snapshot = QueueSchedulerProbe::backlog([], 0, now: 10_000);

        $this->assertSame(0, $snapshot['pending_count']);
        $this->assertSame(0, $snapshot['oldest_pending_age_seconds']);
    }

    public function test_job_slice_measurement_uses_45_second_cpanel_target_by_default(): void
    {
        $result = QueueSchedulerProbe::measureSlice(static function (): void {
            usleep(1_000);
        });

        $this->assertSame(45.0, $result['max_runtime_seconds']);
        $this->assertSame('PASS', $result['status']);
        $this->assertGreaterThanOrEqual(0.0, $result['runtime_seconds']);
        $this->assertGreaterThan(0.0, $result['peak_mib']);
    }

    public function test_invalid_failed_count_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueueSchedulerProbe::backlog([], -1);
    }
}
