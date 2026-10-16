<?php

namespace App\Enterprise\Reporting\Jobs;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportAccessResolver;
use App\Enterprise\Reporting\ReportExportManager;
use App\Enterprise\Reporting\ReportRegistry;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ProcessReportExportChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $exportId) {}

    public function handle(ReportRegistry $reports, ReportAccessResolver $access): void
    {
        $requeue = DB::transaction(function () use ($reports, $access): bool {
            $export = DB::table('enterprise_report_exports')
                ->where('id', $this->exportId)
                ->lockForUpdate()
                ->first();

            if ($export === null || in_array((string) $export->status, ['completed', 'failed'], true)) {
                return false;
            }

            try {
                $report = $reports->require((string) $export->report_code);
                $scope = new EntitlementScope(
                    (string) $export->scope_type,
                    $export->scope_id === null ? null : (int) $export->scope_id,
                );
            } catch (ValidationException|InvalidArgumentException) {
                $this->failExport('report_contract_invalid');

                return false;
            }

            $actor = User::query()->find((int) $export->requested_by);
            if ($actor === null || ! $access->allowsExport($actor, $report, $scope)) {
                $this->failExport('authorization_revoked');

                return false;
            }

            $rowCount = max(0, (int) $export->row_count);
            $offset = min($rowCount, max(0, (int) $export->next_offset));
            $chunkSize = max(1, min((int) $export->chunk_size, $report->exportChunkSize));

            if ($offset >= $rowCount) {
                $this->completeExport($export, $rowCount, $chunkSize);

                return false;
            }

            $processed = min($rowCount, $offset + $chunkSize);
            $chunksCompleted = max(0, (int) $export->chunks_completed) + 1;
            $complete = $processed >= $rowCount;

            $manifest = [
                'kind' => 'export_manifest',
                'report_code' => (string) $export->report_code,
                'row_count' => $rowCount,
                'processed_rows' => $processed,
                'chunk_size' => $chunkSize,
                'chunks_completed' => $chunksCompleted,
                'large_export' => $rowCount > ReportExportManager::LARGE_EXPORT_ROW_THRESHOLD,
                'execution_mode' => 'queued_chunked',
                'synchronous_request' => false,
                'resumable' => true,
                'complete' => $complete,
            ];

            DB::table('enterprise_report_exports')
                ->where('id', $this->exportId)
                ->update([
                    'next_offset' => $processed,
                    'processed_rows' => $processed,
                    'chunks_completed' => $chunksCompleted,
                    'status' => $complete ? 'completed' : 'running',
                    'failure_code' => null,
                    'artifact_metadata' => json_encode(
                        $manifest,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    'completed_at' => $complete ? now() : null,
                    'updated_at' => now(),
                ]);

            return ! $complete;
        });

        if ($requeue) {
            self::dispatch($this->exportId)->onQueue(ReportExportManager::QUEUE);
        }
    }

    private function failExport(string $failureCode): void
    {
        DB::table('enterprise_report_exports')
            ->where('id', $this->exportId)
            ->update([
                'status' => 'failed',
                'failure_code' => $failureCode,
                'updated_at' => now(),
            ]);
    }

    private function completeExport(object $export, int $rowCount, int $chunkSize): void
    {
        $manifest = [
            'kind' => 'export_manifest',
            'report_code' => (string) $export->report_code,
            'row_count' => $rowCount,
            'processed_rows' => $rowCount,
            'chunk_size' => $chunkSize,
            'chunks_completed' => max(0, (int) $export->chunks_completed),
            'large_export' => $rowCount > ReportExportManager::LARGE_EXPORT_ROW_THRESHOLD,
            'execution_mode' => 'queued_chunked',
            'synchronous_request' => false,
            'resumable' => true,
            'complete' => true,
        ];

        DB::table('enterprise_report_exports')
            ->where('id', $this->exportId)
            ->update([
                'next_offset' => $rowCount,
                'processed_rows' => $rowCount,
                'status' => 'completed',
                'failure_code' => null,
                'artifact_metadata' => json_encode(
                    $manifest,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
