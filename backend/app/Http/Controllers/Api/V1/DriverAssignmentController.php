<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);
        $rows = DriverAssignment::query()->where('driver_id', $driver->getKey())->where('assignment_type', $channel)->latest('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function assign(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate(['driver_id' => ['required', 'integer', 'exists:drivers,id'], 'order_id' => ['required', 'integer', 'exists:orders,id']]);
        $driver = Driver::query()->findOrFail($data['driver_id']);
        $order = Order::query()->findOrFail($data['order_id']);
        $channel = strtolower((string) $order->channel);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 409, 'Unsupported order channel.');
        abort_unless(strtolower((string) $driver->driver_type) === $channel, 409, 'Driver and order channels must match.');
        $ability = "drivers.{$channel}.manage";
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission($ability, (int) $order->store_id), 403);

        $assignment = DriverAssignment::query()->create(['driver_id' => $driver->getKey(), 'order_id' => $order->getKey(), 'assignment_type' => $channel, 'status' => 'assigned', 'assigned_at' => now()]);
        $auditLogger->record('delivery.assignment.created', $user, $assignment, null, $assignment->toArray(), $request);

        return response()->json(['data' => $assignment], 201);
    }

    public function transition(Request $request, int $assignment, AuditLogger $auditLogger): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);
        $data = $request->validate(['status' => ['required', Rule::in(['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed'])]]);
        $model = DriverAssignment::query()->whereKey($assignment)->where('driver_id', $driver->getKey())->where('assignment_type', $channel)->firstOrFail();
        $allowed = ['assigned' => ['accepted'], 'accepted' => ['picked_up'], 'picked_up' => ['out_for_delivery'], 'out_for_delivery' => ['delivered', 'failed'], 'failed' => ['out_for_delivery']];
        abort_unless(in_array($data['status'], $allowed[$model->status] ?? [], true), 409, 'Invalid delivery transition.');
        $before = ['status' => $model->status];
        DB::transaction(function () use ($model, $data): void { $model->status = $data['status']; if ($data['status'] === 'delivered') $model->completed_at = now(); $model->save(); });
        $auditLogger->record('delivery.assignment.status_changed', $request->user(), $model, $before, ['status' => $model->status], $request);

        return response()->json(['data' => $model->fresh()]);
    }

    private function driverContext(Request $request): array
    {
        $user = $request->user(); abort_unless($user instanceof User, 401);
        $driver = Driver::query()->where('user_id', $user->getKey())->where('is_active', true)->first(); abort_unless($driver instanceof Driver, 403, 'Active driver profile is required.');
        $channel = strtolower((string) $driver->driver_type); abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403);
        abort_unless($user->hasPermission("deliveries.{$channel}.execute"), 403);

        return [$driver, $channel];
    }
}
