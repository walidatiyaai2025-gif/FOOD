<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

final class CustomerOrderTimelineService
{
    private const DRIVER_STATUSES = [
        'accepted',
        'picked_up',
        'out_for_delivery',
        'delivered',
        'failed',
    ];

    /** @return list<array<string, mixed>> */
    public function forOrder(Order $order): array
    {
        $events = [];

        if ($order->created_at !== null) {
            $events[] = [
                'stage' => 'placed',
                'status' => 'placed',
                'source' => 'order',
                'occurred_at' => $order->created_at->toAtomString(),
            ];
        }

        $driverEvents = DB::table('delivery_proofs')
            ->join('driver_assignments', 'driver_assignments.id', '=', 'delivery_proofs.driver_assignment_id')
            ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('driver_assignments.order_id', $order->getKey())
            ->where('driver_assignments.store_id', $order->store_id)
            ->where('driver_assignments.assignment_type', $order->channel)
            ->whereIn('delivery_proofs.to_status', self::DRIVER_STATUSES)
            ->orderBy('delivery_proofs.captured_at')
            ->orderBy('delivery_proofs.id')
            ->get([
                'delivery_proofs.id',
                'delivery_proofs.to_status',
                'delivery_proofs.reason_code',
                'delivery_proofs.captured_at',
                'driver_assignments.id as assignment_id',
                'driver_assignments.driver_id',
                'users.name as driver_name',
            ]);

        $driverStatuses = [];
        foreach ($driverEvents as $event) {
            $status = (string) $event->to_status;
            $driverStatuses[$status] = true;

            $timeline = [
                'stage' => $status,
                'status' => $status,
                'source' => 'driver',
                'occurred_at' => $event->captured_at === null
                    ? null
                    : (string) $event->captured_at,
                'assignment_id' => (int) $event->assignment_id,
                'driver_id' => (int) $event->driver_id,
                'driver_name' => $event->driver_name === null
                    ? null
                    : (string) $event->driver_name,
            ];

            if ($status === 'failed' && $event->reason_code !== null) {
                $timeline['reason_code'] = (string) $event->reason_code;
            }

            $events[] = $timeline;
        }

        OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->where('store_id', $order->store_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'to_status', 'created_at'])
            ->each(function (OrderStatusHistory $history) use (&$events, $driverStatuses): void {
                $status = (string) $history->to_status;

                if ($status === 'pending') {
                    return;
                }

                if (
                    in_array($status, ['out_for_delivery', 'delivered', 'failed'], true)
                    && isset($driverStatuses[$status])
                ) {
                    return;
                }

                $events[] = [
                    'stage' => $status,
                    'status' => $status,
                    'source' => 'order_status',
                    'occurred_at' => $history->created_at?->toAtomString(),
                ];
            });

        DB::table('driver_assignments')
            ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('driver_assignments.order_id', $order->getKey())
            ->where('driver_assignments.store_id', $order->store_id)
            ->where('driver_assignments.assignment_type', $order->channel)
            ->whereNotNull('driver_assignments.assigned_at')
            ->orderBy('driver_assignments.assigned_at')
            ->orderBy('driver_assignments.id')
            ->get([
                'driver_assignments.id',
                'driver_assignments.driver_id',
                'driver_assignments.assigned_at',
                'users.name as driver_name',
            ])
            ->each(function (object $assignment) use (&$events): void {
                $events[] = [
                    'stage' => 'driver_assigned',
                    'status' => 'assigned',
                    'source' => 'driver_assignment',
                    'occurred_at' => (string) $assignment->assigned_at,
                    'assignment_id' => (int) $assignment->id,
                    'driver_id' => (int) $assignment->driver_id,
                    'driver_name' => $assignment->driver_name === null
                        ? null
                        : (string) $assignment->driver_name,
                ];
            });

        usort(
            $events,
            static function (array $left, array $right): int {
                $leftAt = (string) ($left['occurred_at'] ?? '');
                $rightAt = (string) ($right['occurred_at'] ?? '');
                $comparison = strcmp($leftAt, $rightAt);

                if ($comparison !== 0) {
                    return $comparison;
                }

                return strcmp((string) $left['stage'], (string) $right['stage']);
            },
        );

        return array_values($events);
    }
}
