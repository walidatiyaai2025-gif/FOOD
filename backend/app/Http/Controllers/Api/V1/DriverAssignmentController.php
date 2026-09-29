<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardOperationalNotifier;
use App\Services\DriverOrderService;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverAssignmentController extends Controller
{
    public function index(Request $request, DriverOrderService $driverOrders): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);

        $scope = strtolower($request->string('scope', 'all')->toString());
        abort_unless(in_array($scope, ['all', 'active', 'completed', 'failed'], true), 422);

        $query = DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->when(
                $driver->store_id !== null,
                fn ($builder) => $builder->where('store_id', (int) $driver->store_id),
            );

        match ($scope) {
            'active' => $query->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned']),
            'completed' => $query->where('status', 'delivered'),
            'failed' => $query->where('status', 'failed'),
            default => null,
        };

        $rows = $query
            ->latest('id')
            ->get()
            ->map(fn (DriverAssignment $assignment): array => $driverOrders->payload($assignment))
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'scope' => $scope,
                'total' => $rows->count(),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $assignment,
        DriverOrderService $driverOrders,
    ): JsonResponse {
        [$driver, $channel] = $this->driverContext($request);

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->when(
                $driver->store_id !== null,
                fn ($query) => $query->where('store_id', (int) $driver->store_id),
            )
            ->firstOrFail();

        return response()->json(['data' => $driverOrders->payload($model)]);
    }

    public function assign(
        Request $request,
        AuditLogger $auditLogger,
        DashboardOperationalNotifier $dashboardNotifier,
    ): JsonResponse {
        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);
        $driver = Driver::query()->findOrFail($data['driver_id']);
        $order = Order::query()->findOrFail($data['order_id']);
        $channel = strtolower((string) $order->channel);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 409, 'Unsupported order channel.');
        abort_unless(
            strtolower((string) $driver->driver_type) === $channel,
            409,
            'Driver and order channels must match.',
        );
        abort_if(
            in_array((string) $order->status, ['delivered', 'cancelled'], true),
            409,
            'Completed or cancelled orders cannot be assigned.',
        );

        $ability = "drivers.{$channel}.manage";
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OperationalTenantScope::class)->assertStore(
            $user,
            (int) $order->store_id,
            $ability,
            $channel,
        );

        if ($driver->store_id === null) {
            $historicalStores = DriverAssignment::query()
                ->where('driver_id', $driver->getKey())
                ->whereNotNull('store_id')
                ->distinct()
                ->pluck('store_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            abort_unless(
                $historicalStores === [] || $historicalStores === [(int) $order->store_id],
                409,
                'Driver has ambiguous historical store ownership and must be reconciled before assignment.',
            );
            $driver->update(['store_id' => (int) $order->store_id]);
        } else {
            abort_unless(
                (int) $driver->store_id === (int) $order->store_id,
                409,
                'Driver and order must belong to the same store.',
            );
        }

        $activeAssignment = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned'])
            ->latest('id')
            ->first();

        $previousDriverId = null;
        if ($activeAssignment !== null) {
            abort_if(
                ! $request->boolean('replace_existing'),
                409,
                'Order already has an active driver assignment.',
            );
            abort_if(
                (int) $activeAssignment->driver_id === (int) $driver->getKey(),
                409,
                'Order is already assigned to this driver.',
            );

            $previousDriverId = (int) $activeAssignment->driver_id;
            $before = $activeAssignment->toArray();
            $activeAssignment->forceFill([
                'status' => 'unassigned',
                'completed_at' => now(),
            ])->save();
            $auditLogger->record(
                'delivery.assignment.unassigned',
                $user,
                $activeAssignment,
                $before,
                [
                    ...$activeAssignment->fresh()->toArray(),
                    'reason' => 'reassigned',
                    'replacement_driver_id' => (int) $driver->getKey(),
                ],
                $request,
            );
        }

        DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'failed')
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'updated_at' => now()]);

        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->getKey(),
            'order_id' => $order->getKey(),
            'store_id' => (int) $order->store_id,
            'assignment_type' => $channel,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        $auditLogger->record(
            'delivery.assignment.created',
            $user,
            $assignment,
            null,
            $assignment->toArray(),
            $request,
        );
        $dashboardNotifier->driverAssigned($order, $assignment, $previousDriverId);

        return response()->json(['data' => $assignment], 201);
    }

    public function unassign(
        Request $request,
        int $order,
        AuditLogger $auditLogger,
        DashboardOperationalNotifier $dashboardNotifier,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $orderModel = Order::query()->findOrFail($order);
        $channel = strtolower((string) $orderModel->channel);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 409, 'Unsupported order channel.');
        app(OperationalTenantScope::class)->assertStore(
            $user,
            (int) $orderModel->store_id,
            "drivers.{$channel}.manage",
            $channel,
        );

        $assignment = DriverAssignment::query()
            ->where('order_id', $orderModel->getKey())
            ->where('assignment_type', $channel)
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned'])
            ->latest('id')
            ->first();

        abort_unless($assignment instanceof DriverAssignment, 409, 'Order has no active driver assignment.');

        $before = $assignment->toArray();
        $assignment->forceFill([
            'status' => 'unassigned',
            'completed_at' => now(),
        ])->save();

        $auditLogger->record(
            'delivery.assignment.unassigned',
            $user,
            $assignment,
            $before,
            [
                ...$assignment->fresh()->toArray(),
                'reason' => trim((string) $request->input('reason', 'manual_unassign')),
            ],
            $request,
        );
        $dashboardNotifier->deliveryChanged(
            $orderModel,
            'unassigned',
            $assignment->fresh(),
            trim((string) $request->input('reason', 'manual_unassign')),
            (string) ($before['status'] ?? 'assigned'),
        );

        return response()->json(['data' => $assignment->fresh()]);
    }

    public function transition(
        Request $request,
        int $assignment,
        DriverOrderService $driverOrders,
    ): JsonResponse {
        [$driver, $channel] = $this->driverContext($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed']),
            ],
            'failure_reason' => [
                Rule::requiredIf(fn (): bool => $request->string('status')->toString() === 'failed'),
                'nullable',
                Rule::in([
                    'customer_no_answer',
                    'wrong_address',
                    'customer_refused',
                    'customer_absent',
                    'payment_issue',
                    'order_issue',
                    'other',
                ]),
            ],
            'note' => [
                Rule::requiredIf(fn (): bool => $request->string('status')->toString() === 'failed'
                    && $request->string('failure_reason')->toString() === 'other'),
                'nullable',
                'string',
                'max:1000',
            ],
            'proof_image' => ['nullable', 'image', 'max:5120'],
        ]);

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->whereNotIn('status', ['cancelled', 'unassigned'])
            ->when(
                $driver->store_id !== null,
                fn ($query) => $query->where('store_id', (int) $driver->store_id),
            )
            ->firstOrFail();

        $fresh = $driverOrders->transition(
            $model,
            $driver,
            $user,
            (string) $data['status'],
            $data['note'] ?? null,
            $request,
            $request->file('proof_image'),
            $data['failure_reason'] ?? null,
        );

        return response()->json(['data' => $driverOrders->payload($fresh)]);
    }

    /** @return array{0: Driver, 1: string} */
    private function driverContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $driver = Driver::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
        abort_unless($driver instanceof Driver, 403, 'Active driver profile is required.');

        $channel = strtolower((string) $driver->driver_type);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403);
        abort_unless($user->hasPermission("deliveries.{$channel}.execute"), 403);

        $driver = $this->reconcileDriverOwnership($driver, $channel);

        return [$driver, $channel];
    }

    private function reconcileDriverOwnership(Driver $driver, string $channel): Driver
    {
        $assignmentStoreIds = DB::table('driver_assignments')
            ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
            ->where('driver_assignments.driver_id', $driver->getKey())
            ->where('driver_assignments.assignment_type', $channel)
            ->where('orders.channel', $channel)
            ->distinct()
            ->pluck('orders.store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        if ($assignmentStoreIds->count() === 1) {
            $authoritativeStoreId = (int) $assignmentStoreIds->first();
            if ((int) ($driver->store_id ?? 0) !== $authoritativeStoreId) {
                $driver->forceFill(['store_id' => $authoritativeStoreId])->save();
            }
        } elseif ($assignmentStoreIds->isEmpty() && $channel === 'b2b') {
            $principalStoreId = app(WholesalePrincipal::class)->storeId();
            if ((int) ($driver->store_id ?? 0) !== $principalStoreId) {
                $driver->forceFill(['store_id' => $principalStoreId])->save();
            }
        } elseif ($assignmentStoreIds->isEmpty()) {
            return $driver;
        } else {
            abort(403, 'Driver assignment history belongs to multiple stores and must be reconciled.');
        }

        $storeId = (int) $driver->store_id;

        DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->get(['id', 'order_id', 'store_id'])
            ->each(function (DriverAssignment $assignment) use ($storeId, $channel): void {
                $matches = Order::query()
                    ->whereKey($assignment->order_id)
                    ->where('store_id', $storeId)
                    ->where('channel', $channel)
                    ->exists();

                if ($matches && (int) ($assignment->store_id ?? 0) !== $storeId) {
                    $assignment->forceFill(['store_id' => $storeId])->save();
                }
            });

        return $driver->fresh();
    }
}
