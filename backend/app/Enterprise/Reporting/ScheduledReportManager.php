<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduledReportManager
{
    public const MAX_INTERVAL_MINUTES = 525600;

    public function __construct(private readonly ReportAccessResolver $access) {}

    public function schedule(
        User $actor,
        ReportQuery $query,
        EntitlementScope $scope,
        int $rowCountEstimate,
        DateTimeInterface $nextRunAt,
        int $intervalMinutes,
        string $scheduleKey,
    ): int {
        if ($rowCountEstimate < 0) {
            throw ValidationException::withMessages([
                'row_count_estimate' => ['Scheduled report row estimate must be non-negative.'],
            ]);
        }

        if ($intervalMinutes < 1 || $intervalMinutes > self::MAX_INTERVAL_MINUTES) {
            throw ValidationException::withMessages([
                'interval_minutes' => ['Scheduled report interval is outside the supported bound.'],
            ]);
        }

        $scheduleKey = trim($scheduleKey);
        if ($scheduleKey === '') {
            throw ValidationException::withMessages([
                'schedule_key' => ['Scheduled report key must not be empty.'],
            ]);
        }

        if (! $this->access->allowsExport($actor, $query->report, $scope)) {
            throw new AuthorizationException('Scheduled report is not authorized for the requested scope.');
        }

        $key = hash('sha256', $scheduleKey);
        $payload = json_encode(
            $query->readModelHandoff(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        DB::table('enterprise_scheduled_reports')->insertOrIgnore([
            'report_code' => $query->report->code,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id,
            'requested_by' => $actor->id,
            'query_payload' => $payload,
            'row_count_estimate' => $rowCountEstimate,
            'interval_minutes' => $intervalMinutes,
            'next_run_at' => $nextRunAt->format('Y-m-d H:i:s'),
            'last_run_at' => null,
            'enabled' => true,
            'last_error' => null,
            'schedule_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (int) DB::table('enterprise_scheduled_reports')
            ->where('schedule_key', $key)
            ->value('id');

        if ($id < 1) {
            throw new \RuntimeException('Scheduled report could not be persisted.');
        }

        return $id;
    }
}
