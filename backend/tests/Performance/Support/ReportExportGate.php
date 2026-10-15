<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;

final class ReportExportGate
{
    public const LARGE_EXPORT_ROW_THRESHOLD = 5000;

    /**
     * @return array{
     *     row_count:int,
     *     execution_mode:string,
     *     synchronous_request:bool,
     *     chunk_size:int|null,
     *     resumable:bool,
     *     large_export:bool,
     *     status:string,
     *     reasons:array<int, string>
     * }
     */
    public static function evaluate(
        int $rowCount,
        string $executionMode,
        bool $synchronousRequest,
        ?int $chunkSize,
        bool $resumable,
    ): array {
        if ($rowCount < 0) {
            throw new InvalidArgumentException('Export row count must be non-negative.');
        }

        if (! in_array($executionMode, ['synchronous', 'queued_chunked', 'chunk_streamed'], true)) {
            throw new InvalidArgumentException('Unsupported export execution mode: '.$executionMode);
        }

        if ($chunkSize !== null && $chunkSize <= 0) {
            throw new InvalidArgumentException('Export chunk size must be positive when supplied.');
        }

        $largeExport = $rowCount > self::LARGE_EXPORT_ROW_THRESHOLD;
        $reasons = [];

        if ($largeExport && $synchronousRequest) {
            $reasons[] = 'exports_over_5000_rows_must_not_run_synchronously_in_request';
        }

        if ($largeExport && ! in_array($executionMode, ['queued_chunked', 'chunk_streamed'], true)) {
            $reasons[] = 'large_export_requires_chunked_or_queued_execution';
        }

        if ($largeExport && $chunkSize === null) {
            $reasons[] = 'large_export_requires_explicit_chunk_size';
        }

        if ($largeExport && ! $resumable) {
            $reasons[] = 'large_export_must_be_resumable';
        }

        return [
            'row_count' => $rowCount,
            'execution_mode' => $executionMode,
            'synchronous_request' => $synchronousRequest,
            'chunk_size' => $chunkSize,
            'resumable' => $resumable,
            'large_export' => $largeExport,
            'status' => $reasons === [] ? 'PASS' : 'FAIL',
            'reasons' => $reasons,
        ];
    }
}
