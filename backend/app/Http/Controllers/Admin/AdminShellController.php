<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\B2cWorkspaceLandingResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class AdminShellController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2cWorkspaceLandingResolver $workspaceLanding,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->hasRole('SUPER_ADMIN')) {
            $target = $this->workspaceLanding->urlFor($user);
            if ($target !== null) {
                return redirect()->to($target);
            }
        }

        return $this->render($request);
    }

    public function b2b(Request $request): View
    {
        return $this->render($request, 'b2b');
    }

    public function b2c(Request $request): View
    {
        return $this->render($request, 'b2c');
    }

    private function render(Request $request, ?string $activeChannel = null): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $locale = in_array($user->locale, ['ar', 'en'], true)
            ? $user->locale
            : (string) config('app.locale', 'ar');

        App::setLocale($locale);

        $navigation = $this->navigation->for($user);

        // SUPER_ADMIN remains on the platform control plane by default.
        // Retail support access is entered only through an explicit store context.
        if ($user->hasRole('SUPER_ADMIN')) {
            unset($navigation['b2c']);
        }

        if ($activeChannel !== null) {
            abort_unless(array_key_exists($activeChannel, $navigation), 403);
        }

        $globalRoles = $user->roles()
            ->orderBy('roles.code')
            ->pluck('roles.code')
            ->all();

        $storeRoles = $user->storeRoleAssignments()
            ->with('role:id,code')
            ->get()
            ->pluck('role.code')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return view('admin.shell', [
            'user' => $user,
            'navigation' => $navigation,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => $activeChannel === null ? 'overview' : $activeChannel.'_dashboard',
            'activeChannel' => $activeChannel,
            'roleCodes' => array_values(array_unique([...$globalRoles, ...$storeRoles])),
        ]);
    }
}
