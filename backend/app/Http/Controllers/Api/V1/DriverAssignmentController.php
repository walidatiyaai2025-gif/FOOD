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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'active' => $query->whereNotIn('status', ['delivered', 'failed']),
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

        abort_if(
            DriverAssignment::query()
                ->where('order_id', $order->getKey())
                ->whereNotIn('status', ['delivered', 'failed'])
                ->exists(),
            409,
            'Order already has an active driver assignment.',
        );

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
        $dashboardNotifier->deliveryChanged($order, 'assigned');

        return response()->json(['data' => $assignment], 201);
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
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
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

        if ($driver->store_id === null) {
            abort_unless(
                ! DriverAssignment::query()->where('driver_id', $driver->getKey())->exists(),
                403,
                'Driver store ownership must be reconciled before delivery execution.',
            );
        }

        return [$driver, $channel];
    }
}
