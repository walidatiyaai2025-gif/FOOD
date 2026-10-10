<?php

namespace App\Services;

use App\Models\B2BVanCutoverRun;
use App\Models\B2BVanCutoverSnapshot;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use App\Models\User;
use App\Models\Van;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class B2BVanFulfillmentCutoverService
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

    public function __construct(
        private readonly B2BVanFulfillmentMigrationAudit $inventory,
        private readonly B2BOrderRoutingCoordinator $routing,
        private readonly VanExecutionStateService $executionStates,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string,mixed> */
    public function apply(?User $actor = null, Carbon|string|null $at = null): array
    {
        $moment = $this->moment($at);
        $preflight = $this->inventory->report();

        $run = B2BVanCutoverRun::query()->create([
            'public_id' => (string) Str::uuid(),
            'status' => 'running',
            'started_at' => $moment,
            'summary' => [
                'preflight' => $preflight['counts'],
                'policy' => $preflight['policy'],
            ],
        ]);

        $changedOrders = 0;

        try {
            Order::query()
                ->whereIn('channel', ['b2b', 'b2c'])
                ->whereNotIn('status', self::TERMINAL_ORDER_STATUSES)
                ->orderBy('id')
                ->chunkById(100, function ($orders) use ($run, $actor, $moment, &$changedOrders): void {
                    foreach ($orders as $candidate) {
                        $changed = DB::transaction(function () use ($run, $candidate, $actor, $moment): bool {
                            $order = Order::query()
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->firstOrFail();

                            $before = $this->snapshot($order);

                            if (strtolower((string) $order->channel) === 'b2b') {
                                $this->reconcileB2B($run, $order, $actor, $moment);
                            } else {
                                $this->reconcileB2C($run, $order, $actor, $moment);
                            }

                            $order->refresh();
                            $after = $this->snapshot($order);

                            if ($this->statesEquivalent($before, $after)) {
                                return false;
                            }

                            B2BVanCutoverSnapshot::query()->create([
                                'b2b_van_cutover_run_id' => $run->getKey(),
                                'order_id' => $order->getKey(),
                                'channel' => strtolower((string) $order->channel),
                                'before_state' => $before,
                                'after_state' => $after,
                            ]);

                            $this->audit->record(
                                'fulfillment.cutover.order_reconciled',
                                $actor,
                                $order,
                                $before,
                                $after,
                            );

                            return true;
                        });

                        if ($changed) {
                            $changedOrders++;
                        }
                    }
                });

            $postflight = $this->inventory->report();
            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'summary' => [
                    'preflight' => $preflight['counts'],
                    'postflight' => $postflight['counts'],
                    'policy' => $preflight['policy'],
                    'changed_orders' => $changedOrders,
                ],
            ])->save();

            return $this->runPayload($run->fresh());
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'summary' => [
                    'preflight' => $preflight['counts'],
                    'policy' => $preflight['policy'],
                    'changed_orders' => $changedOrders,
                    'failure' => [
                        'class' => $exception::class,
                        'message' => $exception->getMessage(),
                    ],
                ],
            ])->save();

            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function rollback(string $publicId, ?User $actor = null, Carbon|string|null $at = null): array
    {
        $moment = $this->moment($at);
        $run = B2BVanCutoverRun::query()
            ->where('public_id', trim($publicId))
            ->firstOrFail();

        if ($run->status === 'rolled_back') {
            return $this->runPayload($run);
        }

        $restored = 0;
        $conflicts = 0;

        $snapshots = B2BVanCutoverSnapshot::query()
            ->where('b2b_van_cutover_run_id', $run->getKey())
            ->whereNull('rolled_back_at')
            ->orderByDesc('id')
            ->get();

        foreach ($snapshots as $snapshot) {
            $result = DB::transaction(function () use ($snapshot, $run, $actor, $moment): string {
                $order = Order::query()
                    ->whereKey($snapshot->order_id)
                    ->lockForUpdate()
                    ->first();

                if (! $order instanceof Order) {
                    $snapshot->forceFill([
                        'rollback_state' => [
                            'status' => 'conflict',
                            'reason' => 'order_missing',
                        ],
                    ])->save();

                    return 'conflict';
                }

                $current = $this->snapshot($order);
                if (! $this->statesEquivalent($current, $snapshot->after_state)) {
                    $snapshot->forceFill([
                        'rollback_state' => [
                            'status' => 'conflict',
                            'reason' => 'state_changed_after_cutover',
                            'current_state' => $current,
                        ],
                    ])->save();

                    return 'conflict';
                }

                $this->restoreDriverAssignments($order, $snapshot->before_state);
                $this->restoreVanAssignments($order, $snapshot->before_state, $run, $moment);
                $this->restoreDispatchState($order, $snapshot->before_state, $run, $moment);

                $restoredState = $this->snapshot($order->fresh());
                $snapshot->forceFill([
                    'rollback_state' => [
                        'status' => 'restored',
                        'restored_state' => $restoredState,
                    ],
                    'rolled_back_at' => $moment,
                ])->save();

                $this->audit->record(
                    'fulfillment.cutover.order_rolled_back',
                    $actor,
                    $order,
                    $current,
                    $restoredState,
                );

                return 'restored';
            });

            if ($result === 'restored') {
                $restored++;
            } else {
                $conflicts++;
            }
        }

        $summary = (array) $run->summary;
        $summary['rollback'] = [
            'restored_orders' => $restored,
            'conflicts' => $conflicts,
        ];

        $run->forceFill([
            'status' => $conflicts === 0 ? 'rolled_back' : 'partial_rollback',
            'rolled_back_at' => $conflicts === 0 ? $moment : null,
            'summary' => $summary,
        ])->save();

        return $this->runPayload($run->fresh());
    }

    private function reconcileB2B(B2BVanCutoverRun $run, Order $order, ?User $actor, Carbon $moment): void
    {
        $activeDrivers = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', self::TERMINAL_DRIVER_STATUSES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($activeDrivers as $assignment) {
            $before = $assignment->toArray();
            $assignment->forceFill([
                'status' => 'reassigned',
                'completed_at' => $assignment->completed_at ?? $moment,
            ])->save();

            $this->audit->record(
                'fulfillment.cutover.driver_ended',
                $actor,
                $assignment,
                $before,
                $assignment->fresh()->toArray(),
            );
        }

        $dispatch = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if ($dispatch instanceof OrderDispatchState && $dispatch->current_assignee_type === FulfillmentActorPolicy::DRIVER) {
            $before = $dispatch->toArray();
            $dispatch->forceFill([
                'status' => 'unrouted',
                'routing_policy_id' => null,
                'routing_decision_trace_id' => null,
                'routing_mode' => null,
                'routing_source' => 'b2b_van_cutover',
                'routing_reason' => 'legacy_driver_actor_removed',
                'current_assignee_type' => null,
                'current_assignee_id' => null,
                'decision_key' => hash('sha256', 'b2b-van-cutover|'.$run->public_id.'|'.$order->getKey()),
                'context' => array_merge((array) $dispatch->context, [
                    'cutover_run' => $run->public_id,
                    'legacy_actor' => 'driver',
                ]),
                'decided_at' => $moment,
            ])->save();

            $this->audit->record(
                'fulfillment.cutover.dispatch_released',
                $actor,
                $order,
                $before,
                $dispatch->fresh()->toArray(),
            );
            $dispatch = $dispatch->fresh();
        }

        if (
            $dispatch instanceof OrderDispatchState
            && $dispatch->status === 'awaiting_dispatch'
            && $dispatch->current_assignee_type === null
            && $dispatch->current_assignee_id === null
        ) {
            return;
        }

        if ($this->preserveAssignedVanState($run, $order, $dispatch, $actor, $moment)) {
            return;
        }

        $this->routing->routeCreatedOrder($order, $actor, 'migration_cutover');
    }

    private function preserveAssignedVanState(
        B2BVanCutoverRun $run,
        Order $order,
        ?OrderDispatchState $dispatch,
        ?User $actor,
        Carbon $moment,
    ): bool {
        if (
            ! $dispatch instanceof OrderDispatchState
            || $dispatch->status !== 'assigned'
            || $dispatch->current_assignee_type !== FulfillmentActorPolicy::VAN
            || $dispatch->current_assignee_id === null
        ) {
            return false;
        }

        $vanId = (int) $dispatch->current_assignee_id;
        if (! Van::query()->whereKey($vanId)->exists()) {
            $before = $dispatch->toArray();
            $dispatch->forceFill([
                'status' => 'unrouted',
                'routing_source' => 'b2b_van_cutover',
                'routing_reason' => 'stale_van_actor_removed',
                'current_assignee_type' => null,
                'current_assignee_id' => null,
                'decision_key' => hash('sha256', 'b2b-stale-van|'.$run->public_id.'|'.$order->getKey()),
                'context' => array_merge((array) $dispatch->context, [
                    'cutover_run' => $run->public_id,
                    'stale_van_id' => $vanId,
                ]),
                'decided_at' => $moment,
            ])->save();

            $this->audit->record(
                'fulfillment.cutover.dispatch_released',
                $actor,
                $order,
                $before,
                $dispatch->fresh()->toArray(),
            );

            return false;
        }

        $active = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $matching = $active->first(fn (OrderVanAssignment $assignment): bool => (int) $assignment->van_id === $vanId);

        foreach ($active as $assignment) {
            if ((int) $assignment->van_id === $vanId && $assignment->is($matching)) {
                continue;
            }

            $before = $assignment->toArray();
            $assignment->forceFill([
                'status' => 'reassigned',
                'ended_at' => $moment,
            ])->save();

            $this->audit->record(
                'fulfillment.cutover.van_contradiction_ended',
                $actor,
                $order,
                ['order_van_assignment' => $before],
                ['order_van_assignment' => $assignment->fresh()->toArray()],
            );
        }

        if (! $matching instanceof OrderVanAssignment) {
            $matching = OrderVanAssignment::query()->create([
                'order_id' => $order->getKey(),
                'van_id' => $vanId,
                'service_territory_id' => $dispatch->service_territory_id,
                'status' => 'active',
                'source' => 'b2b_van_cutover_preserve',
                'reason' => 'dispatch_van_assignment_repaired',
                'decision_key' => hash('sha256', 'b2b-preserved-van|'.$order->getKey().'|'.$vanId),
                'assigned_by' => $actor?->getKey(),
                'assigned_at' => $moment,
                'routing_context' => [
                    'cutover_run' => $run->public_id,
                    'preserved_dispatch_state_id' => $dispatch->getKey(),
                ],
            ]);
        }

        $this->executionStates->initialize($matching, $moment);

        return true;
    }

    private function reconcileB2C(B2BVanCutoverRun $run, Order $order, ?User $actor, Carbon $moment): void
    {
        $activeVans = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($activeVans as $assignment) {
            $before = $assignment->toArray();
            $assignment->forceFill([
                'status' => 'ended',
                'ended_at' => $moment,
            ])->save();

            $this->audit->record(
                'fulfillment.cutover.b2c_van_ended',
                $actor,
                $order,
                ['order_van_assignment' => $before],
                ['order_van_assignment' => $assignment->fresh()->toArray()],
            );
        }

        $dispatch = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! $dispatch instanceof OrderDispatchState || $dispatch->current_assignee_type !== FulfillmentActorPolicy::VAN) {
            return;
        }

        $activeDriver = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->whereNotIn('status', self::TERMINAL_DRIVER_STATUSES)
            ->latest('id')
            ->first();

        $before = $dispatch->toArray();
        $dispatch->forceFill([
            'status' => $activeDriver instanceof DriverAssignment ? 'assigned' : 'unrouted',
            'routing_policy_id' => null,
            'routing_decision_trace_id' => null,
            'routing_mode' => null,
            'routing_source' => 'b2c_driver_cutover',
            'routing_reason' => $activeDriver instanceof DriverAssignment
                ? 'restored_active_driver'
                : 'van_actor_removed_no_active_driver',
            'current_assignee_type' => $activeDriver instanceof DriverAssignment
                ? FulfillmentActorPolicy::DRIVER
                : null,
            'current_assignee_id' => $activeDriver instanceof DriverAssignment
                ? (int) $activeDriver->driver_id
                : null,
            'decision_key' => hash('sha256', 'b2c-driver-cutover|'.$run->public_id.'|'.$order->getKey()),
            'context' => array_merge((array) $dispatch->context, [
                'cutover_run' => $run->public_id,
                'legacy_actor' => 'van',
            ]),
            'decided_at' => $moment,
        ])->save();

        $this->audit->record(
            'fulfillment.cutover.b2c_dispatch_repaired',
            $actor,
            $order,
            $before,
            $dispatch->fresh()->toArray(),
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(Order $order): array
    {
        $driverAssignments = DriverAssignment::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (DriverAssignment $assignment): array => [
                'id' => (int) $assignment->getKey(),
                'driver_id' => (int) $assignment->driver_id,
                'assignment_type' => (string) $assignment->assignment_type,
                'status' => (string) $assignment->status,
                'assigned_at' => $this->rawDate($assignment, 'assigned_at'),
                'completed_at' => $this->rawDate($assignment, 'completed_at'),
            ])
            ->values()
            ->all();

        $vanAssignments = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (OrderVanAssignment $assignment): array => [
                'id' => (int) $assignment->getKey(),
                'van_id' => (int) $assignment->van_id,
                'van_assignment_id' => $assignment->van_assignment_id === null ? null : (int) $assignment->van_assignment_id,
                'service_territory_id' => $assignment->service_territory_id === null ? null : (int) $assignment->service_territory_id,
                'status' => (string) $assignment->status,
                'source' => (string) $assignment->source,
                'reason' => $assignment->reason,
                'decision_key' => (string) $assignment->decision_key,
                'assigned_by' => $assignment->assigned_by === null ? null : (int) $assignment->assigned_by,
                'assigned_at' => $this->rawDate($assignment, 'assigned_at'),
                'ended_at' => $this->rawDate($assignment, 'ended_at'),
                'routing_context' => $assignment->routing_context,
            ])
            ->values()
            ->all();

        $dispatch = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->first();

        $executionStates = OrderVanExecutionState::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (OrderVanExecutionState $state): array => [
                'id' => (int) $state->getKey(),
                'order_van_assignment_id' => (int) $state->order_van_assignment_id,
                'van_id' => (int) $state->van_id,
                'status' => (string) $state->status,
                'failure_reason_code' => $state->failure_reason_code,
                'failure_note' => $state->failure_note,
                'version' => (int) $state->version,
                'context' => $state->context,
                'last_transition_at' => $this->rawDate($state, 'last_transition_at'),
            ])
            ->values()
            ->all();

        return [
            'order' => [
                'id' => (int) $order->getKey(),
                'channel' => strtolower((string) $order->channel),
                'status' => (string) $order->status,
            ],
            'driver_assignments' => $driverAssignments,
            'van_assignments' => $vanAssignments,
            'dispatch' => $dispatch instanceof OrderDispatchState
                ? $this->dispatchSnapshot($dispatch)
                : null,
            'van_execution_states' => $executionStates,
        ];
    }

    /** @return array<string,mixed> */
    private function dispatchSnapshot(OrderDispatchState $state): array
    {
        return [
            'id' => (int) $state->getKey(),
            'status' => (string) $state->status,
            'service_territory_id' => $state->service_territory_id === null ? null : (int) $state->service_territory_id,
            'routing_policy_id' => $state->routing_policy_id === null ? null : (int) $state->routing_policy_id,
            'routing_decision_trace_id' => $state->routing_decision_trace_id === null ? null : (int) $state->routing_decision_trace_id,
            'routing_mode' => $state->routing_mode,
            'routing_source' => $state->routing_source,
            'routing_reason' => $state->routing_reason,
            'current_assignee_type' => $state->current_assignee_type,
            'current_assignee_id' => $state->current_assignee_id === null ? null : (int) $state->current_assignee_id,
            'decision_key' => $state->decision_key,
            'context' => $state->context,
            'decided_at' => $this->rawDate($state, 'decided_at'),
        ];
    }

    /** @param array<string,mixed> $before */
    private function restoreDriverAssignments(Order $order, array $before): void
    {
        foreach ((array) ($before['driver_assignments'] ?? []) as $row) {
            $assignment = DriverAssignment::query()
                ->whereKey((int) $row['id'])
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof DriverAssignment) {
                continue;
            }

            $assignment->forceFill([
                'status' => $row['status'],
                'completed_at' => $row['completed_at'],
            ])->save();
        }
    }

    /** @param array<string,mixed> $before */
    private function restoreVanAssignments(Order $order, array $before, B2BVanCutoverRun $run, Carbon $moment): void
    {
        $beforeRows = collect((array) ($before['van_assignments'] ?? []))->keyBy('id');
        $current = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($current as $assignment) {
            $row = $beforeRows->get((int) $assignment->getKey());

            if (! is_array($row)) {
                $assignment->forceFill([
                    'status' => 'ended',
                    'ended_at' => $moment,
                    'reason' => 'cutover_rollback',
                    'routing_context' => array_merge((array) $assignment->routing_context, [
                        'rollback_run' => $run->public_id,
                    ]),
                ])->save();

                continue;
            }

            $assignment->forceFill([
                'van_id' => $row['van_id'],
                'van_assignment_id' => $row['van_assignment_id'],
                'service_territory_id' => $row['service_territory_id'],
                'status' => $row['status'],
                'source' => $row['source'],
                'reason' => $row['reason'],
                'decision_key' => $row['decision_key'],
                'assigned_by' => $row['assigned_by'],
                'assigned_at' => $row['assigned_at'],
                'ended_at' => $row['ended_at'],
                'routing_context' => $row['routing_context'],
            ])->save();
        }
    }

    /** @param array<string,mixed> $before */
    private function restoreDispatchState(Order $order, array $before, B2BVanCutoverRun $run, Carbon $moment): void
    {
        $row = $before['dispatch'] ?? null;
        $state = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! is_array($row)) {
            if ($state instanceof OrderDispatchState) {
                $state->forceFill([
                    'status' => 'unrouted',
                    'service_territory_id' => null,
                    'routing_policy_id' => null,
                    'routing_decision_trace_id' => null,
                    'routing_mode' => null,
                    'routing_source' => 'cutover_rollback',
                    'routing_reason' => 'restored_absent_pre_cutover',
                    'current_assignee_type' => null,
                    'current_assignee_id' => null,
                    'decision_key' => hash('sha256', 'cutover-rollback|'.$run->public_id.'|'.$order->getKey()),
                    'context' => ['rollback_run' => $run->public_id],
                    'decided_at' => $moment,
                ])->save();
            }

            return;
        }

        $state ??= new OrderDispatchState(['order_id' => $order->getKey()]);
        $state->forceFill([
            'order_id' => $order->getKey(),
            'status' => $row['status'],
            'service_territory_id' => $row['service_territory_id'],
            'routing_policy_id' => $row['routing_policy_id'],
            'routing_decision_trace_id' => $row['routing_decision_trace_id'],
            'routing_mode' => $row['routing_mode'],
            'routing_source' => $row['routing_source'],
            'routing_reason' => $row['routing_reason'],
            'current_assignee_type' => $row['current_assignee_type'],
            'current_assignee_id' => $row['current_assignee_id'],
            'decision_key' => $row['decision_key'],
            'context' => $row['context'],
            'decided_at' => $row['decided_at'],
        ])->save();
    }

    /** @param array<string,mixed> $first @param array<string,mixed> $second */
    private function statesEquivalent(array $first, array $second): bool
    {
        if (isset($first['dispatch']) && is_array($first['dispatch'])) {
            unset($first['dispatch']['decided_at']);
        }
        if (isset($second['dispatch']) && is_array($second['dispatch'])) {
            unset($second['dispatch']['decided_at']);
        }

        return $first === $second;
    }

    private function rawDate(Model $model, string $attribute): ?string
    {
        $value = $model->getRawOriginal($attribute);

        return $value === null ? null : (string) $value;
    }

    private function moment(Carbon|string|null $at): Carbon
    {
        return $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));
    }

    /** @return array<string,mixed> */
    private function runPayload(B2BVanCutoverRun $run): array
    {
        return [
            'run_id' => (string) $run->public_id,
            'status' => (string) $run->status,
            'started_at' => $run->started_at->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'rolled_back_at' => $run->rolled_back_at?->toISOString(),
            'snapshot_count' => B2BVanCutoverSnapshot::query()
                ->where('b2b_van_cutover_run_id', $run->getKey())
                ->count(),
            'summary' => (array) $run->summary,
        ];
    }
}
