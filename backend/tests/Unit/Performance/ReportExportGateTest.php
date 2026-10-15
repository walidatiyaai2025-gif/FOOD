<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\ReportExportGate;

final class ReportExportGateTest extends TestCase
{
    public function test_bounded_synchronous_export_at_threshold_passes(): void
    {
        $result = ReportExportGate::evaluate(
            rowCount: 5000,
            executionMode: 'synchronous',
            synchronousRequest: true,
            chunkSize: null,
            resumable: false,
        );

        $this->assertSame('PASS', $result['status']);
        $this->assertFalse($result['large_export']);
    }

    public function test_large_synchronous_export_fails(): void
    {
        $result = ReportExportGate::evaluate(
            rowCount: 5001,
            executionMode: 'synchronous',
            synchronousRequest: true,
            chunkSize: null,
            resumable: false,
        );

        $this->assertSame('FAIL', $result['status']);
        $this->assertContains(
            'exports_over_5000_rows_must_not_run_synchronously_in_request',
            $result['reasons'],
        );
        $this->assertContains(
            'large_export_requires_chunked_or_queued_execution',
            $result['reasons'],
        );
    }

    public function test_large_queued_chunked_resumable_export_passes(): void
    {
        $result = ReportExportGate::evaluate(
            rowCount: 25000,
            executionMode: 'queued_chunked',
            synchronousRequest: false,
            chunkSize: 500,
            resumable: true,
        );

        $this->assertSame('PASS', $result['status']);
        $this->assertTrue($result['large_export']);
        $this->assertSame(500, $result['chunk_size']);
    }

    public function test_large_export_without_resume_contract_fails(): void
    {
        $result = ReportExportGate::evaluate(
            rowCount: 12000,
            executionMode: 'chunk_streamed',
            synchronousRequest: false,
            chunkSize: 1000,
            resumable: false,
        );

        $this->assertSame('FAIL', $result['status']);
        $this->assertContains('large_export_must_be_resumable', $result['reasons']);
    }

    public function test_invalid_mode_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportExportGate::evaluate(
            rowCount: 1,
            executionMode: 'background-magic',
            synchronousRequest: false,
            chunkSize: null,
            resumable: false,
        );
    }
}
