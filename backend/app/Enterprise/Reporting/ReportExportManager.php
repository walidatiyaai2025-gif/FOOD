<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\Jobs\ProcessReportExportChunk;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportExportManager
{
    public const LARGE_EXPORT_ROW_THRESHOLD = 5000;

    public const QUEUE = 'enterprise-report-exports';

    public function __construct(private readonly ReportAccessResolver $access) {}

    public function request(
        User $actor,
        ReportQuery $query,
        EntitlementScope $scope,
        int $rowCount,
        ?string $idempotencyKey = null,
        ?int $scheduledReportId = null,
    ): int {
        if ($rowCount < 0) {
            throw ValidationException::withMessages([
                'row_count' => ['Export row count must be non-negative.'],
            ]);
        }

        if (! $this->access->allowsExport($actor, $query->report, $scope)) {
            throw new AuthorizationException('Report export is not authorized for the requested scope.');
        }

        $handoff = $query->exportHandoff($scope);
        $payload = json_encode($handoff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $key = $this->idempotencyKey(
            $actor,
            $query,
            $scope,
            $rowCount,
            $scheduledReportId,
            $idempotencyKey,
        );

        [$id, $created] = DB::transaction(function () use (
            $actor,
            $query,
            $scope,
            $rowCount,
            $scheduledReportId,
            $payload,
            $key,
        ): array {
            $created = DB::table('enterprise_report_exports')->insertOrIgnore([
                'report_code' => $query->report->code,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'requested_by' => $actor->id,
                'scheduled_report_id' => $scheduledReportId,
                'query_payload' => $payload,
                'row_count' => $rowCount,
                'execution_mode' => 'queued_chunked',
                'chunk_size' => $query->report->exportChunkSize,
                'next_offset' => 0,
                'processed_rows' => 0,
                'chunks_completed' => 0,
                'status' => 'queued',
                'failure_code' => null,
                'artifact_metadata' => null,
                'idempotency_key' => $key,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            $id = (int) DB::table('enterprise_report_exports')
                ->where('idempotency_key', $key)
                ->value('id');

            if ($id < 1) {
                throw new \RuntimeException('Report export request could not be persisted.');
            }

            return [$id, $created];
        });

        if ($created) {
            ProcessReportExportChunk::dispatch($id)->onQueue(self::QUEUE);
        }

        return $id;
    }

    private function idempotencyKey(
        User $actor,
        ReportQuery $query,
        EntitlementScope $scope,
        int $rowCount,
        ?int $scheduledReportId,
        ?string $explicit,
    ): string {
        if ($explicit !== null) {
            $explicit = trim($explicit);
            if ($explicit === '') {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Export idempotency key must not be empty.'],
                ]);
            }

            return hash('sha256', 'explicit:'.$explicit);
        }

        $seed = json_encode([
            'actor_id' => $actor->id,
            'report' => $query->report->code,
            'scope' => $scope->key(),
            'query' => $query->executionPlan(),
            'row_count' => $rowCount,
            'scheduled_report_id' => $scheduledReportId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $seed);
    }
}
