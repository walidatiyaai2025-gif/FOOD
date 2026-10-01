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
            'evidenceUrlTemplate' => url('/admin/driver-live-tracking/assignments/__ASSIGNMENT__/evidence'),
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

    public function evidence(Request $request, int $assignment): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $model = DriverAssignment::query()->findOrFail($assignment);
        $channel = strtolower((string) $model->assignment_type);

        $this->scope->assertStore(
            $user,
            (int) $model->store_id,
            'drivers.tracking.view',
            $channel,
        );

        $order = DB::table('orders')
            ->where('id', $model->order_id)
            ->where('store_id', $model->store_id)
            ->where('channel', $channel)
            ->first(['id', 'order_number', 'status']);
        abort_unless($order !== null, 404);

        $events = DB::table('delivery_proofs')
            ->leftJoin('users', 'users.id', '=', 'delivery_proofs.user_id')
            ->where('delivery_proofs.driver_assignment_id', $model->getKey())
            ->where(function ($query) use ($model): void {
                $query
                    ->whereNull('delivery_proofs.order_id')
                    ->orWhere('delivery_proofs.order_id', $model->order_id);
            })
            ->orderBy('delivery_proofs.id')
            ->get([
                'delivery_proofs.id',
                'delivery_proofs.proof_type',
                'delivery_proofs.from_status',
                'delivery_proofs.to_status',
                'delivery_proofs.reason_code',
                'delivery_proofs.note',
                'delivery_proofs.file_path',
                'delivery_proofs.captured_at',
                'users.name as actor_name',
            ])
            ->map(static fn (object $event): array => [
                'id' => (int) $event->id,
                'proof_type' => (string) $event->proof_type,
                'from_status' => $event->from_status === null ? null : (string) $event->from_status,
                'to_status' => $event->to_status === null ? null : (string) $event->to_status,
                'reason_code' => $event->reason_code === null ? null : (string) $event->reason_code,
                'note' => $event->note === null ? null : (string) $event->note,
                'actor_name' => $event->actor_name === null ? null : (string) $event->actor_name,
                'captured_at' => $event->captured_at === null ? null : (string) $event->captured_at,
                'has_image' => is_string($event->file_path) && trim($event->file_path) !== '',
                'proof_url' => is_string($event->file_path) && trim($event->file_path) !== ''
                    ? route('admin.driver-live-tracking.proofs.show', ['proof' => (int) $event->id])
                    : null,
            ])
            ->values();

        return response()->json([
            'data' => [
                'assignment' => [
                    'id' => (int) $model->getKey(),
                    'driver_id' => (int) $model->driver_id,
                    'order_id' => (int) $model->order_id,
                    'store_id' => (int) $model->store_id,
                    'channel' => $channel,
                    'status' => (string) $model->status,
                    'assigned_at' => $model->assigned_at,
                    'completed_at' => $model->completed_at,
                ],
                'order' => [
                    'id' => (int) $order->id,
                    'number' => (string) $order->order_number,
                    'status' => (string) $order->status,
                ],
                'timeline' => $events,
            ],
        ]);
    }

    public function proof(Request $request, int $proof): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $evidence = DeliveryProof::query()->findOrFail($proof);
        $assignment = DriverAssignment::query()->findOrFail($evidence->driver_assignment_id);
        $channel = strtolower((string) $assignment->assignment_type);

        $this->scope->assertStore(
            $user,
            (int) $assignment->store_id,
            'drivers.tracking.view',
            $channel,
        );

        if ($evidence->order_id !== null) {
            abort_unless((int) $evidence->order_id === (int) $assignment->order_id, 404);
        }

        $path = trim((string) $evidence->file_path);
        abort_if($path === '', 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            basename($path),
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
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
