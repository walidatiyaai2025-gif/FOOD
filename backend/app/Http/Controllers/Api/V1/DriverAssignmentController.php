<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardOperationalNotifier;
use App\Services\OperationalTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);
        $rows = DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->when($driver->store_id !== null, fn ($query) => $query->where('store_id', (int) $driver->store_id))
            ->latest('id')
            ->get();
        $allowed = $this->allowedTransitions();
        $payload = $rows->map(fn (DriverAssignment $assignment): array => $this->payload($assignment, $allowed));

        return response()->json(['data' => $payload]);
    }

    public function assign(Request $request, AuditLogger $auditLogger, DashboardOperationalNotifier $dashboardNotifier): JsonResponse
    {
        $data = $request->validate(['driver_id' => ['required', 'integer', 'exists:drivers,id'], 'order_id' => ['required', 'integer', 'exists:orders,id']]);
        $driver = Driver::query()->findOrFail($data['driver_id']);
        $order = Order::query()->findOrFail($data['order_id']);
        $channel = strtolower((string) $order->channel);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 409, 'Unsupported order channel.');
        abort_unless(strtolower((string) $driver->driver_type) === $channel, 409, 'Driver and order channels must match.');
        $ability = "drivers.{$channel}.manage";
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OperationalTenantScope::class)->assertStore($user, (int) $order->store_id, $ability, $channel);

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
            abort_unless((int) $driver->store_id === (int) $order->store_id, 409, 'Driver and order must belong to the same store.');
        }

        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->getKey(),
            'order_id' => $order->getKey(),
            'store_id' => (int) $order->store_id,
            'assignment_type' => $channel,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);
        $auditLogger->record('delivery.assignment.created', $user, $assignment, null, $assignment->toArray(), $request);
        $dashboardNotifier->deliveryChanged($order, 'assigned');

        return response()->json(['data' => $assignment], 201);
    }

    public function transition(Request $request, int $assignment, AuditLogger $auditLogger, DashboardOperationalNotifier $dashboardNotifier): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->when($driver->store_id !== null, fn ($query) => $query->where('store_id', (int) $driver->store_id))
            ->firstOrFail();
        $allowed = $this->allowedTransitions();
        abort_unless(in_array($data['status'], $allowed[$model->status] ?? [], true), 409, 'Invalid delivery transition.');
        $before = ['status' => $model->status];
        DB::transaction(function () use ($model, $data): void {
            $model->status = $data['status'];
            if (array_key_exists('note', $data)) {
                $model->notes = $data['note'];
            }
            if ($data['status'] === 'delivered') {
                $model->completed_at = now();
            }
            $model->save();

            $order = Order::query()
                ->whereKey($model->order_id)
                ->where('store_id', (int) $model->store_id)
                ->where('channel', (string) $model->assignment_type)
                ->lockForUpdate()
                ->firstOrFail();

            $targetOrderStatus = match ($data['status']) {
                'picked_up', 'out_for_delivery' => 'out_for_delivery',
                'delivered' => 'delivered',
                'failed' => 'failed',
                default => null,
            };

            if ($targetOrderStatus !== null && (string) $order->status !== $targetOrderStatus) {
                app(OrderController::class)->transition(
                    request()->duplicate(request: [
                        'status' => $targetOrderStatus,
                        'note' => $data['note'] ?? 'driver_delivery_update',
                    ]),
                    (int) $order->getKey(),
                    app(AuditLogger::class),
                    app(DashboardOperationalNotifier::class),
                );
            }
        });
        $auditLogger->record('delivery.assignment.status_changed', $request->user(), $model, $before, ['status' => $model->status], $request);

        $fresh = $model->fresh();
        $order = Order::query()->find($fresh->order_id);
        if ($order instanceof Order && (int) $order->store_id === (int) $fresh->store_id) {
            $dashboardNotifier->deliveryChanged($order, (string) $fresh->status);
        }

        return response()->json(['data' => $this->payload($fresh, $allowed)]);
    }

    /** @param array<string, list<string>> $allowed */
    private function payload(DriverAssignment $assignment, array $allowed): array
    {
        $order = Order::query()
            ->whereKey($assignment->order_id)
            ->where('store_id', (int) $assignment->store_id)
            ->where('channel', (string) $assignment->assignment_type)
            ->firstOrFail();

        $customerTable = $assignment->assignment_type === 'b2b' ? 'b2b_customers' : 'b2c_customers';
        $customerColumn = $assignment->assignment_type === 'b2b' ? 'b2b_customer_id' : 'b2c_customer_id';
        $customerId = $order->{$customerColumn};
        $customer = $customerId === null ? null : DB::table($customerTable)->where('id', $customerId)->first(['name', 'phone', 'email']);

        return [
            'id' => (int) $assignment->getKey(),
            'order_id' => (int) $order->getKey(),
            'assignment_type' => (string) $assignment->assignment_type,
            'status' => (string) $assignment->status,
            'available_statuses' => $allowed[$assignment->status] ?? [],
            'assigned_at' => $assignment->assigned_at,
            'completed_at' => $assignment->completed_at,
            'notes' => $assignment->notes,
            'order' => [
                'number' => (string) $order->order_number,
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'grand_total' => (float) $order->grand_total,
                'payment_method' => (string) $order->payment_method,
                'customer_note' => $order->customer_note,
                'address_id' => $order->address_id === null ? null : (int) $order->address_id,
                'customer' => $customer === null ? null : [
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                ],
                'items' => DB::table('order_items')
                    ->where('order_id', $order->getKey())
                    ->orderBy('id')
                    ->get(['sku_snapshot', 'name_snapshot', 'quantity', 'unit_price', 'line_total'])
                    ->map(fn ($item): array => [
                        'sku' => $item->sku_snapshot,
                        'name' => $item->name_snapshot,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                    ])->all(),
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function allowedTransitions(): array
    {
        return [
            'assigned' => ['accepted'],
            'accepted' => ['picked_up'],
            'picked_up' => ['out_for_delivery'],
            'out_for_delivery' => ['delivered', 'failed'],
            'failed' => ['out_for_delivery'],
        ];
    }

    private function driverContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $driver = Driver::query()->where('user_id', $user->getKey())->where('is_active', true)->first();
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
