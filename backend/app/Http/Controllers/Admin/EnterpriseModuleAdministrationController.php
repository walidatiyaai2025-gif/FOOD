<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Services\EnterpriseSavedViewService;
use App\Support\AdminNavigation;
use App\Support\EnterpriseGridState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

final class EnterpriseModuleAdministrationController extends Controller
{
    private const SURFACE_KEY = 'enterprise.modules';

    private const GRID_FILTERS = ['state'];

    private const GRID_SORTS = ['name', 'state'];

    private const GRID_COLUMNS = [
        'category',
        'state',
        'dependencies',
        'entitlements',
        'capabilities',
    ];

    public function __construct(
        private readonly EnterpriseModuleAdministration $modules,
        private readonly EnterpriseSavedViewService $savedViews,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->platformAdmin($request);

        return $this->page($request, $actor, null);
    }

    public function show(Request $request, string $module): View
    {
        $actor = $this->platformAdmin($request);

        try {
            $selected = $this->modules->module($module);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return $this->page($request, $actor, $selected);
    }

    public function lifecycle(Request $request, string $module): RedirectResponse
    {
        $actor = $this->platformAdmin($request);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'suspend', 'maintenance'])],
        ]);

        try {
            $this->modules->perform($module, (string) $validated['action'], $actor);
        } catch (InvalidArgumentException) {
            abort(404);
        } catch (LogicException) {
            return back()->withErrors([
                'module' => __('enterprise_modules.transition_failed'),
            ]);
        }

        return redirect()
            ->route('admin.enterprise-modules.show', ['module' => $module])
            ->with('status', __('enterprise_modules.transition_saved'));
    }

    /**
     * @param  array<string, mixed>|null  $selected
     */
    private function page(Request $request, User $actor, ?array $selected): View
    {
        $grid = EnterpriseGridState::fromRequest(
            $request,
            self::GRID_FILTERS,
            self::GRID_SORTS,
            self::GRID_COLUMNS,
            self::GRID_COLUMNS,
        );

        $modules = $this->modules->modules();

        if ($grid->search !== null) {
            $needle = mb_strtolower($grid->search);
            $modules = $modules->filter(static function (array $module) use ($needle): bool {
                $haystack = mb_strtolower((string) $module['name'].' '.(string) $module['category']);

                return str_contains($haystack, $needle);
            });
        }

        if (isset($grid->filters['state'])) {
            $state = strtoupper($grid->filters['state']);
            $allowedStates = array_map(
                static fn (ModuleState $item): string => $item->value,
                ModuleState::cases(),
            );

            if (in_array($state, $allowedStates, true)) {
                $modules = $modules->filter(
                    static fn (array $module): bool => (string) $module['state'] === $state,
                );
            }
        }

        if ($grid->sortField !== null) {
            $descending = $grid->sortDirection === 'desc';
            $sortField = $grid->sortField;
            $modules = $modules->sortBy(
                static fn (array $module): string => mb_strtolower((string) $module[$sortField]),
                SORT_NATURAL | SORT_FLAG_CASE,
                $descending,
            );
        }

        $modules = $modules->values();
        $page = max(1, $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $modules->forPage($page, $grid->perPage)->values(),
            $modules->count(),
            $grid->perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'page'],
        );
        $paginator->appends($request->except('page'));

        $savedViews = $this->savedViews
            ->visible($actor, self::SURFACE_KEY, 'platform', null)
            ->map(fn ($view): array => [
                'id' => (int) $view->getKey(),
                'name' => (string) $view->name,
                'visibility' => (string) $view->visibility,
                'owned' => (int) $view->user_id === (int) $actor->getKey(),
                'query' => $this->savedViews->queryFor($view),
            ]);

        return view('admin.enterprise-modules', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'modules' => $paginator,
            'selected' => $selected,
            'gridState' => $grid,
            'visibleColumns' => $grid->columns,
            'moduleStates' => array_map(
                static fn (ModuleState $state): string => $state->value,
                ModuleState::cases(),
            ),
            'savedViews' => $savedViews,
            'savedViewStateToken' => $this->savedViews->stateToken(
                self::SURFACE_KEY,
                'platform',
                null,
                $grid,
            ),
        ]);
    }

    private function platformAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        return $actor;
    }
}
