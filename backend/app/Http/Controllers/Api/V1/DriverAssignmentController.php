<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DashboardOperationalNotifier;
use App\Services\DriverOrderService;
use App\Services\DriverTenantScope;
use App\Services\InvoiceService;
use App\Services\OperationalTenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverAssignmentController extends Controller
{
    public function __construct(private readonly DriverTenantScope $driverTenants) {}

    public function index(Request $request, DriverOrderService $driverOrders): JsonResponse
    {
        [$driver, $channel] = $this->driverContext($request);

        $scope = strtolower($request->string('scope', 'all')->toString());
        abort_unless(in_array($scope, ['all', 'active', 'completed', 'failed'], true), 422);

        $query = DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->where('store_id', (int) $driver->store_id);

        match ($scope) {
            'active' => $query->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned']),
            'completed' => $query->where('status', 'delivered'),
            'failed' => $query->where('status', 'failed'),
            default => null,
        };

        $rows = $query
            ->latest('id')
            ->get()
            ->map(function (DriverAssignment $assignment) use ($driverOrders): array {
                if (in_array(
                    (string) $assignment->status,
                    ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'],
                    true,
                )) {
                    return $driverOrders->historyPayload($assignment);
                }

                return $driverOrders->payload($assignment);
            })
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
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
            ->where('store_id', (int) $driver->store_id)
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
        $order = Order::query()->findOrFail($data['order_id']);
        $channel = strtolower((string) $order->channel);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 409, 'Unsupported order channel.');

        $ability = "drivers.{$channel}.manage";
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        app(OperationalTenantScope::class)->assertStore(
            $user,
            (int) $order->store_id,
            $ability,
            $channel,
        );

        $driver = Driver::query()->findOrFail($data['driver_id']);
        abort_unless((bool) $driver->is_active, 409, 'Driver must be active before assignment.');
        [$driverChannel, $driverStoreId] = $this->driverTenants->resolve($driver);
        abort_unless($driverChannel === $channel, 409, 'Driver and order channels must match.');
        abort_unless(
            $driverStoreId === (int) $order->store_id,
            409,
            'Driver and order must belong to the same authoritative store.',
        );
        abort_if(
            $channel === 'b2b' && (string) $order->status === 'pending',
            409,
            'Pending B2B orders require Customer Service approval before driver assignment.',
        );
        abort_if(
            in_array((string) $order->status, ['delivered', 'cancelled'], true),
            409,
            'Completed or cancelled orders cannot be assigned.',
        );

        $activeAssignment = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
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
                'status' => 'reassigned',
                'completed_at' => now(),
            ])->save();
            $auditLogger->record(
                'delivery.assignment.reassigned',
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
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
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

    public function downloadInvoice(
        Request $request,
        int $assignment,
        InvoiceService $invoices,
    ): Response {
        $validated = $request->validate([
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);
        [$driver, $channel] = $this->driverContext($request);

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->where('store_id', (int) $driver->store_id)
            ->whereNotIn('status', ['cancelled', 'unassigned', 'reassigned'])
            ->firstOrFail();

        $order = Order::query()
            ->whereKey($model->order_id)
            ->where('store_id', (int) $driver->store_id)
            ->where('channel', $channel)
            ->firstOrFail();

        $invoice = Invoice::query()
            ->where('order_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->where('channel', $channel)
            ->whereIn('status', ['issued', 'reissued'])
            ->orderByDesc('revision')
            ->orderByDesc('id')
            ->firstOrFail();

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $locale = (string) ($validated['locale'] ?? $user->locale ?? 'en');
        $pdf = $invoices->renderPdf($invoice, $locale);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function transition(
        Request $request,
        int $assignment,
        DriverOrderService $driverOrders,
    ): JsonResponse {
        [$driver, $channel] = $this->driverContext($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if (
            $idempotencyKey !== ''
            && (
                strlen($idempotencyKey) < 8
                || strlen($idempotencyKey) > 128
                || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1
            )
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency-Key must be 8-128 characters using letters, numbers, dot, underscore, colon or dash.',
                ],
            ]);
        }

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in(['accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed']),
            ],
            'failure_reason' => [
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
                'nullable',
                'string',
                'max:1000',
            ],
            'proof_image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->whereNotIn('status', ['cancelled', 'unassigned', 'reassigned'])
            ->where('store_id', (int) $driver->store_id)
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
            $idempotencyKey === '' ? null : $idempotencyKey,
        );

        return response()->json([
            'data' => $driverOrders->payload($fresh),
            'meta' => [
                'idempotency_key' => $idempotencyKey === '' ? null : $idempotencyKey,
            ],
        ]);
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

        $this->driverTenants->resolve($driver);

        return [$driver, $channel];
    }
}
