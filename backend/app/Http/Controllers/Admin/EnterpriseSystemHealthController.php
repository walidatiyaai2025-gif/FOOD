<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class EnterpriseSystemHealthController extends Controller
{
    public function __construct(
        private readonly ModuleManifestCatalog $catalog,
        private readonly ModuleRegistry $registry,
        private readonly ModuleHealthService $health,
        private readonly AdminNavigation $navigation,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        $reports = $this->health->all();
        $modules = $this->catalog->all()->map(function ($manifest) use ($reports): array {
            $report = $reports[$manifest->code];
            $state = $this->safeState($manifest->code);

            $dependencies = collect($manifest->hardDependencies)->map(function (string $dependency): array {
                return [
                    'name' => Str::headline($dependency),
                    'state' => $this->safeState($dependency)->value,
                ];
            })->values()->all();

            return [
                'name' => Str::headline($manifest->code),
                'category' => Str::headline($manifest->category),
                'lifecycle' => $state->value,
                'health' => $report->status->value,
                'checked_at' => $report->checkedAt->toIso8601String(),
                'check_count' => count($report->checks),
                'stale_count' => collect($report->checks)->filter(
                    static fn ($check): bool => $check->isStale(),
                )->count(),
                'dependencies' => $dependencies,
            ];
        })->values();

        return view('admin.enterprise-system-health', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'modules' => $modules,
            'outboxCounts' => $this->statusCounts(EnterpriseOutboxMessage::query(), 'status'),
            'workflowCounts' => $this->statusCounts(EnterpriseWorkflowRequest::query(), 'status'),
            'queueDriver' => (string) config('queue.default', ''),
        ]);
    }

    private function safeState(string $module): ModuleState
    {
        try {
            return $this->registry->state($module);
        } catch (Throwable) {
            return ModuleState::ERROR;
        }
    }

    private function statusCounts($query, string $column): array
    {
        return $query
            ->selectRaw($column.', COUNT(*) AS aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }
}
