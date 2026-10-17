<?php

namespace App\Enterprise\Reporting;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

final class ScheduledReportDispatcher
{
    public const MAX_BATCH = 100;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ReportRegistry $reports,
        private readonly ReportExportManager $exports,
    ) {}

    public function dispatchDue(int $limit = 25): int
    {
        $ids = DB::table('enterprise_scheduled_reports')
            ->where('enabled', true)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_BATCH)))
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            if ($this->dispatchOne((int) $id)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    private function dispatchOne(int $id): bool
    {
        $schedule = DB::table('enterprise_scheduled_reports')->where('id', $id)->first();
        if ($schedule === null
            || ! (bool) $schedule->enabled
            || CarbonImmutable::parse((string) $schedule->next_run_at)->isFuture()) {
            return false;
        }

        $actor = User::query()->find((int) $schedule->requested_by);
        if ($actor === null) {
            $this->disable($id, 'actor_unavailable');

            return false;
        }

        try {
            $report = $this->reports->require((string) $schedule->report_code);
            if (! $this->modules->isEnabled($report->moduleCode)) {
                return false;
            }

            $scope = new EntitlementScope(
                (string) $schedule->scope_type,
                $schedule->scope_id === null ? null : (int) $schedule->scope_id,
            );
            $payload = json_decode(
                (string) $schedule->query_payload,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'query_payload' => ['Scheduled report query snapshot must be an object.'],
                ]);
            }

            $query = ReportQuery::fromArray($report, [
                'filters' => $payload['filters'] ?? [],
                'from' => $payload['from'] ?? null,
                'to' => $payload['to'] ?? null,
                'page' => 1,
                'per_page' => min(
                    $report->maxPageSize,
                    max(1, (int) ($payload['per_page'] ?? min(25, $report->maxPageSize))),
                ),
                'dimensions' => $payload['dimensions'] ?? $report->dimensions,
                'measures' => $payload['measures'] ?? $report->measures,
                'group_by' => $payload['group_by'] ?? ($payload['dimensions'] ?? $report->dimensions),
                'sort' => $payload['sort'] ?? [],
            ]);

            $dueToken = (string) $schedule->next_run_at;
            $this->exports->request(
                $actor,
                $query,
                $scope,
                max(0, (int) $schedule->row_count_estimate),
                'scheduled:'.$id.':'.$dueToken,
                $id,
            );
        } catch (AuthorizationException) {
            $this->disable($id, 'authorization_revoked');

            return false;
        } catch (ValidationException|InvalidArgumentException|JsonException) {
            $this->disable($id, 'schedule_contract_invalid');

            return false;
        }

        $now = CarbonImmutable::now();
        $nextRunAt = $now->addMinutes(
            max(1, min((int) $schedule->interval_minutes, ScheduledReportManager::MAX_INTERVAL_MINUTES)),
        );

        $updated = DB::table('enterprise_scheduled_reports')
            ->where('id', $id)
            ->where('enabled', true)
            ->where('next_run_at', $schedule->next_run_at)
            ->update([
                'last_run_at' => $now,
                'next_run_at' => $nextRunAt,
                'last_error' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1;
    }

    private function disable(int $id, string $error): void
    {
        DB::table('enterprise_scheduled_reports')
            ->where('id', $id)
            ->update([
                'enabled' => false,
                'last_error' => $error,
                'updated_at' => now(),
            ]);
    }
}
