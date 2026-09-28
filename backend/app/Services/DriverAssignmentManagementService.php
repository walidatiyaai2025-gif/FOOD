<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DriverAssignmentManagementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DashboardOperationalNotifier $notifier,
    ) {}

    public function withdraw(
        DriverAssignment $assignment,
        User $actor,
        Request $request,
        ?string $note = null,
    ): DriverAssignment {
        return $this->closeAndNormalize($assignment, $actor, $request, 'unassigned', $note);
    }

    public function reassign(
        DriverAssignment $assignment,
        Driver $newDriver,
        User $actor,
        Request $request,
        ?string $note = null,
    ): DriverAssignment {
        return DB::transaction(function () use ($assignment, $newDriver, $actor, $request, $note): DriverAssignment {
            $current = DriverAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($current->order_id)->lockForUpdate()->firstOrFail();

            abort_if(in_array((string) $order->status, ['delivered', 'cancelled'], true), 409, 'Completed or cancelled orders cannot be reassigned.');
            abort_unless((int) $newDriver->store_id === (int) $current->store_id, 409, 'Driver and order must belong to the same store.');
            abort_unless(strtolower((string) $newDriver->driver_type) === strtolower((string) $current->assignment_type), 409, 'Driver and order channels must match.');
            abort_unless($newDriver->is_active, 409, 'The selected driver is inactive.');

            if ((int) $current->driver_id === (int) $newDriver->getKey() && ! in_array((string) $current->status, ['unassigned', 'reassigned', 'delivered'], true)) {
                return $current;
            }

            $this->normalizeOrderForReassignment($order, $actor, $request, $note);
            $before = $current->toArray();
            $current->forceFill([
                'status' => 'reassigned',
                'completed_at' => now(),
            ])->save();

            $next = DriverAssignment::query()->create([
                'driver_id' => $newDriver->getKey(),
                'order_id' => $order->getKey(),
                'store_id' => (int) $order->store_id,
                'assignment_type' => strtolower((string) $order->channel),
                'status' => 'assigned',
                'assigned_at' => now(),
                'completed_at' => null,
            ]);

            $this->audit->record('delivery.assignment.reassigned', $actor, $current, $before, [
                'status' => 'reassigned',
                'replacement_assignment_id' => $next->getKey(),
                'replacement_driver_id' => $newDriver->getKey(),
                'note' => $note,
            ], $request);
            $this->notifier->deliveryChanged($order, 'assigned');

            return $next->fresh();
        }, 3);
    }

    private function closeAndNormalize(
        DriverAssignment $assignment,
        User $actor,
        Request $request,
        string $status,
        ?string $note,
    ): DriverAssignment {
        return DB::transaction(function () use ($assignment, $actor, $request, $status, $note): DriverAssignment {
            $current = DriverAssignment::query()->whereKey($assignment->getKey())->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($current->order_id)->lockForUpdate()->firstOrFail();

            abort_if(in_array((string) $current->status, ['delivered', 'unassigned', 'reassigned'], true), 409, 'This driver assignment is already closed.');
            abort_if((string) $order->status === 'delivered', 409, 'Delivered orders cannot be withdrawn from a driver.');

            $this->normalizeOrderForReassignment($order, $actor, $request, $note);
            $before = $current->toArray();
            $current->forceFill([
                'status' => $status,
                'completed_at' => now(),
            ])->save();

            $this->audit->record('delivery.assignment.withdrawn', $actor, $current, $before, [
                'status' => $status,
                'note' => $note,
            ], $request);
            $this->notifier->deliveryChanged($order, $status);

            return $current->fresh();
        }, 3);
    }

    private function normalizeOrderForReassignment(
        Order $order,
        User $actor,
        Request $request,
        ?string $note,
    ): void {
        if (! in_array((string) $order->status, ['out_for_delivery', 'failed'], true)) {
            return;
        }

        $from = (string) $order->status;
        $order->forceFill(['status' => 'ready'])->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'store_id' => (int) $order->store_id,
            'user_id' => $actor->getKey(),
            'from_status' => $from,
            'to_status' => 'ready',
            'note' => $note ?: 'Driver assignment withdrawn/reassigned by administration.',
        ]);

        $this->audit->record('order.status_changed', $actor, $order, ['status' => $from], [
            'status' => 'ready',
            'source' => 'admin_driver_management',
            'note' => $note,
        ], $request);
        $this->notifier->orderStatusChanged($order, $from, 'ready');
    }
}
