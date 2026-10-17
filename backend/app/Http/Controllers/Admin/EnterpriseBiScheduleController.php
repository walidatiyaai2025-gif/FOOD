<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Reporting\ReportAccessResolver;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Enterprise\Reporting\ScheduledReportManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EnterpriseBiScheduleController extends Controller
{
    public function store(
        Request $request,
        ReportRegistry $reports,
        ReportAccessResolver $access,
        ScheduledReportManager $schedules,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'report' => ['required', 'string', 'max:120'],
            'scope_type' => ['required', Rule::in([EntitlementScope::STORE, EntitlementScope::LEGAL_ENTITY])],
            'scope_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dimensions' => ['nullable', 'array', 'max:12'],
            'dimensions.*' => ['string', 'max:80'],
            'measures' => ['required', 'array', 'min:1', 'max:12'],
            'measures.*' => ['string', 'max:80'],
            'filters' => ['nullable', 'array', 'max:10'],
            'filters.*' => ['nullable', 'string', 'max:120'],
            'sort_field' => ['nullable', 'string', 'max:80'],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'interval_minutes' => ['required', 'integer', 'min:15', 'max:525600'],
            'next_run_at' => ['required', 'date'],
        ]);

        $report = $reports->require((string) $validated['report']);
        $scope = new EntitlementScope((string) $validated['scope_type'], (int) $validated['scope_id']);
        abort_unless($access->allowsExport($actor, $report, $scope), 403);

        $query = ReportQuery::fromArray($report, [
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'dimensions' => array_values((array) ($validated['dimensions'] ?? [])),
            'measures' => array_values((array) $validated['measures']),
            'group_by' => array_values((array) ($validated['dimensions'] ?? [])),
            'filters' => array_filter((array) ($validated['filters'] ?? []), static fn (mixed $value): bool => $value !== null && $value !== ''),
            'sort' => isset($validated['sort_field']) && trim((string) $validated['sort_field']) !== ''
                ? [[
                    'field' => (string) $validated['sort_field'],
                    'direction' => (string) ($validated['sort_direction'] ?? 'asc'),
                ]]
                : [],
            'per_page' => min(25, $report->maxPageSize),
        ]);

        $nextRun = CarbonImmutable::parse((string) $validated['next_run_at']);
        if ($nextRun->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'next_run_at' => [__('enterprise_bi_ui.schedule_future_required')],
            ]);
        }

        $scheduleKey = implode('|', [
            'bi-ui',
            (string) $actor->id,
            $report->code,
            $scope->key(),
            hash('sha256', json_encode($query->executionPlan(), JSON_THROW_ON_ERROR)),
            (string) $validated['interval_minutes'],
            $nextRun->toIso8601String(),
        ]);

        $schedules->schedule(
            $actor,
            $query,
            $scope,
            0,
            $nextRun,
            (int) $validated['interval_minutes'],
            $scheduleKey,
        );

        return back()->with('status', __('enterprise_bi_ui.schedule_saved'));
    }

    public function update(
        Request $request,
        int $schedule,
        ReportRegistry $reports,
        ReportAccessResolver $access,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'interval_minutes' => ['required', 'integer', 'min:15', 'max:525600'],
            'next_run_at' => ['required', 'date'],
        ]);

        $row = DB::table('enterprise_scheduled_reports')->where('id', $schedule)->first();
        abort_if($row === null, 404);
        abort_unless($actor->hasRole('SUPER_ADMIN') || (int) $row->requested_by === (int) $actor->id, 403);

        $report = $reports->require((string) $row->report_code);
        $scope = new EntitlementScope((string) $row->scope_type, $row->scope_id === null ? null : (int) $row->scope_id);
        abort_unless($access->allowsExport($actor, $report, $scope), 403);

        $nextRun = CarbonImmutable::parse((string) $validated['next_run_at']);
        if ($nextRun->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'next_run_at' => [__('enterprise_bi_ui.schedule_future_required')],
            ]);
        }

        DB::table('enterprise_scheduled_reports')
            ->where('id', $schedule)
            ->update([
                'enabled' => (bool) $validated['enabled'],
                'interval_minutes' => (int) $validated['interval_minutes'],
                'next_run_at' => $nextRun,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return back()->with('status', __('enterprise_bi_ui.schedule_saved'));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('reports.export'), 403);

        return $actor;
    }
}
