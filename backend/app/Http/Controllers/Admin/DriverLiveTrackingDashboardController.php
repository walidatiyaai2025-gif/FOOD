<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DriverDeliveryEvidenceService;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DriverLiveTrackingDashboardController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenantResolver,
        private readonly DriverDeliveryEvidenceService $evidence,
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
            'feedUrl' => route('admin.driver-live-tracking.feed'),
            'vanFeedUrl' => route('admin.field-operations.fleet.feed'),
            'trackingI18n' => [
                'noDrivers' => __('admin.driver_live_tracking.no_drivers'),
                'loading' => __('admin.driver_live_tracking.loading'),
                'failed' => __('admin.driver_live_tracking.load_failed'),
                'ready' => __('admin.driver_live_tracking.ready'),
                'store' => __('admin.driver_live_tracking.store'),
                'status' => __('admin.driver_live_tracking.status'),
                'statuses' => [
                    'online' => __('admin.driver_live_tracking.online'),
                    'stale' => __('admin.driver_live_tracking.stale'),
                    'offline' => __('admin.driver_live_tracking.offline'),
                ],
                'order' => __('admin.driver_live_tracking.order'),
                'accuracy' => __('admin.driver_live_tracking.accuracy'),
                'speed' => __('admin.driver_live_tracking.speed'),
                'lastSeen' => __('admin.driver_live_tracking.last_seen'),
                'driver' => __('admin.driver_live_tracking.driver'),
                'van' => __('admin.driver_live_tracking.van'),
                'entities' => __('admin.driver_live_tracking.entities'),
                'entityType' => __('admin.driver_live_tracking.entity_type'),
                'allEntities' => __('admin.driver_live_tracking.all_entities'),
                'route' => __('admin.driver_live_tracking.route'),
                'assignment' => __('admin.driver_live_tracking.assignment'),
            ],
        ]);
    }

    public function evidence(Request $request, int $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'data' => $this->evidence->assignment($user, $assignment),
        ]);
    }

    public function proof(Request $request, int $assignment, int $proof): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $record = $this->evidence->proof($user, $assignment, $proof);
        $path = trim((string) $record->file_path);
        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = 'delivery-proof-'.$record->getKey().($extension === '' ? '' : '.'.$extension);

        return Storage::disk('public')->response($path, $filename, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
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
