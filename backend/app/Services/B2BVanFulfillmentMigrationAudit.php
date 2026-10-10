<?php

namespace App\Services;

use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;

final class B2BVanFulfillmentMigrationAudit
{
    private const TERMINAL_ORDER_STATUSES = [
        'cancelled',
        'canceled',
        'completed',
        'delivered',
        'refunded',
        'void',
        'voided',
    ];

    private const TERMINAL_DRIVER_STATUSES = [
        'unassigned',
        'reassigned',
        'cancelled',
        'canceled',
        'completed',
        'delivered',
        'failed',
    ];

    public function __construct(private readonly FulfillmentActorPolicy $actors) {}

    /** @return array<string,mixed> */
    public function report(): array
    {
        $rows = [];
        $counts = [
            'open_orders' => 0,
            'b2b_orders' => 0,
            'b2c_orders' => 0,
            'contradictory_orders' => 0,
            'b2b_active_driver' => 0,
            'b2b_dispatch_driver' => 0,
            'b2b_multiple_active_vans' => 0,
            'b2b_requires_van_routing' => 0,
            'b2c_active_van' => 0,
            'b2c_dispatch_van' => 0,
        ];

        Order::query()
            ->whereIn('channel', ['b2b', 'b2c'])
            ->whereNotIn('status', self::TERMINAL_ORDER_STATUSES)
            ->orderBy('id')
            ->chunkById(250, function ($orders) use (&$rows, &$counts): void {
                foreach ($orders as $order) {
                    $counts['open_orders']++;
                    $channel = strtolower((string) $order->channel);
                    $counts[$channel.'_orders']++;
                    $expected = $this->actors->actorForChannel($channel);

                    $activeDriverIds = DriverAssignment::query()
                        ->where('order_id', $order->id)
                        ->whereNotIn('status', self::TERMINAL_DRIVER_STATUSES)
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    $activeVanIds = OrderVanAssignment::query()
                        ->where('order_id', $order->id)
                        ->where('status', 'active')
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    $dispatch = OrderDispatchState::query()
                        ->where('order_id', $order->id)
                        ->first(['status', 'current_assignee_type', 'current_assignee_id', 'routing_reason']);

                    $issues = [];
                    if ($channel === 'b2b') {
                        if ($activeDriverIds !== []) {
                            $issues[] = 'b2b_active_driver';
                        }
                        if (($dispatch?->current_assignee_type ?? null) === FulfillmentActorPolicy::DRIVER) {
                            $issues[] = 'b2b_dispatch_driver';
                        }
                        if (count($activeVanIds) > 1) {
                            $issues[] = 'b2b_multiple_active_vans';
                        }
                        if ($activeVanIds === [] && ($dispatch?->current_assignee_type ?? null) !== FulfillmentActorPolicy::VAN) {
                            $issues[] = 'b2b_requires_van_routing';
                        }
                    } else {
                        if ($activeVanIds !== []) {
                            $issues[] = 'b2c_active_van';
                        }
                        if (($dispatch?->current_assignee_type ?? null) === FulfillmentActorPolicy::VAN) {
                            $issues[] = 'b2c_dispatch_van';
                        }
                    }

                    foreach ($issues as $issue) {
                        $counts[$issue]++;
                    }

                    if ($issues !== []) {
                        $counts['contradictory_orders']++;
                        $rows[] = [
                            'order_id' => (int) $order->id,
                            'order_number' => (string) $order->order_number,
                            'channel' => $channel,
                            'status' => (string) $order->status,
                            'expected_actor' => $expected,
                            'active_driver_assignment_ids' => $activeDriverIds,
                            'active_van_assignment_ids' => $activeVanIds,
                            'dispatch_status' => $dispatch?->status,
                            'dispatch_assignee_type' => $dispatch?->current_assignee_type,
                            'dispatch_assignee_id' => $dispatch?->current_assignee_id,
                            'dispatch_reason' => $dispatch?->routing_reason,
                            'issues' => $issues,
                        ];
                    }
                }
            });

        return [
            'mode' => 'dry-run',
            'destructive_changes' => false,
            'policy' => ['b2b' => 'van', 'b2c' => 'driver'],
            'counts' => $counts,
            'rows' => $rows,
            'rollback_plan' => [
                'No data is changed by this report.',
                'Future cutover writes must preserve historical Driver rows and end active contradictions with audit.',
                'Every rerun must be deterministic from current database state.',
            ],
        ];
    }
}
