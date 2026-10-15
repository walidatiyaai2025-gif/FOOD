<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

final class EnterpriseModuleAdministrationController extends Controller
{
    public function __construct(
        private readonly EnterpriseModuleAdministration $modules,
        private readonly AdminNavigation $navigation,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->platformAdmin($request);

        return view('admin.enterprise-modules', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'modules' => $this->modules->modules(),
            'selected' => null,
        ]);
    }

    public function show(Request $request, string $module): View
    {
        $actor = $this->platformAdmin($request);

        try {
            $selected = $this->modules->module($module);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return view('admin.enterprise-modules', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'modules' => $this->modules->modules(),
            'selected' => $selected,
        ]);
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

    private function platformAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        return $actor;
    }
}
