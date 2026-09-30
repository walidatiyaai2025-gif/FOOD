<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class DriverLiveTrackingDashboardController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenantResolver,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->canView($user), 403);

        return view('admin.driver-live-tracking', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'driver_live_tracking',
            'feedUrl' => url('/api/v1/admin/driver-live-tracking/feed'),
        ]);
    }

    private function canView(User $user): bool
    {
        if ($user->hasPermission('drivers.tracking.view')) {
            return true;
        }

        $retailStoreIds = $this->tenantResolver->retailStoreIds($user);
        if ($retailStoreIds === []) {
            return false;
        }

        return $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('store', fn ($query) => $query->where('stores.is_active', true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', 'drivers.tracking.view')))
            ->exists();
    }
}
