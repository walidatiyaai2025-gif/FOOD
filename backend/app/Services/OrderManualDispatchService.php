<?php

namespace App\Services;

use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderManualDispatchService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FulfillmentActorPolicy $actors,
        private readonly VanExecutionStateService $executionStates,
    ) {}

    public function assignDriver(
        Order $order,
        User $actor,
        DriverAssignment $driverAssignment,
        string $reason,
    ): OrderDispatchState {
        $reason = $this->reason($reason);
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::DRIVER);

        if (
            (int) $driverAssignment->order_id !== (int) $order->id
            || in_array((string) $driverAssignment->status, ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'], true)
        ) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Driver assignment must be active and belong to this order.'],
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $driverAssignment, $reason): OrderDispatchState {
            $otherActiveDriver = DriverAssignment::query()
                ->where('order_id', $order->id)
                ->where('id', '!=', $driverAssignment->id)
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->lockForUpdate()
                ->exists();

            if ($otherActiveDriver) {
                throw ValidationException::withMessages([
                    'assignee_id' => ['Order cannot have more than one active Driver executor.'],
                ]);
            }
            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);
            $source = $this->manualSource($state, 'driver', (int) $driverAssignment->driver_id);

            OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->each(fn (OrderVanAssignment $assignment) => $assignment->forceFill([
                    'status' => 'reassigned',
                    'ended_at' => now(),
                ])->save());

            $before = $state->exists ? $state->toArray() : null;
            $state->fill([
                'status' => 'assigned',
                'routing_source' => $source,
                'routing_reason' => 'customer_service_driver_assignment',
                'current_assignee_type' => 'driver',
                'current_assignee_id' => (int) $driverAssignment->driver_id,
                'decision_key' => hash('sha256', implode('|', [
                    'manual-driver',
                    $order->id,
                    $driverAssignment->id,
                ])),
                'context' => $this->context($state, [
                    'reason' => $reason,
                    'driver_assignment_id' => (int) $driverAssignment->id,
                ]),
                'decided_at' => now(),
            ])->save();

            $this->audit->record(
                'order.dispatch.manual_assigned',
                $actor,
                $order,
                $before,
                $state->fresh()->toArray(),
            );

            return $state->fresh();
        });
    }

    public function assignVan(
        Order $order,
        User $actor,
        int $vanId,
        string $reason,
        Carbon|string|null $at = null,
    ): OrderDispatchState {
        $reason = $this->reason($reason);
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::VAN);
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));

        $van = Van::query()
            ->whereKey($vanId)
            ->where('status', 'active')
            ->first();

        if (! $van instanceof Van) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Selected Van must be active.'],
            ]);
        }

        $effectiveAssignment = VanAssignment::query()
            ->where('van_id', $van->id)
            ->where('status', 'active')
            ->where('effective_from', '<=', $moment)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->orderByRaw("CASE WHEN assignment_type = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();

        if (! $effectiveAssignment instanceof VanAssignment) {
            throw ValidationException::withMessages([
                'assignee_id' => ['Selected Van has no effective active assignment.'],
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $van, $effectiveAssignment, $reason, $moment): OrderDispatchState {
            $activeDriver = DriverAssignment::query()
                ->where('order_id', $order->id)
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->lockForUpdate()
                ->exists();

            if ($activeDriver) {
                throw ValidationException::withMessages([
                    'assignee_id' => ['Active Driver assignment must be ended before assigning a Van.'],
                ]);
            }

            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);
            $source = $this->manualSource($state, 'van', (int) $van->id);

            $active = OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $same = $active->first(fn (OrderVanAssignment $assignment): bool => (int) $assignment->van_id === (int) $van->id);
            if (! $same instanceof OrderVanAssignment) {
                foreach ($active as $assignment) {
                    $assignment->forceFill([
                        'status' => 'reassigned',
                        'ended_at' => $moment,
                    ])->save();
                }

                $decisionKey = hash('sha256', implode('|', [
                    'manual-van',
                    $order->id,
                    $van->id,
                    $effectiveAssignment->id,
                    $moment->format('Y-m-d H:i:s.u'),
                    Str::uuid()->toString(),
                ]));

                $same = OrderVanAssignment::query()->create([
                    'order_id' => $order->id,
                    'van_id' => $van->id,
                    'van_assignment_id' => $effectiveAssignment->id,
                    'service_territory_id' => $state->service_territory_id,
                    'status' => 'active',
                    'source' => $source,
                    'reason' => 'customer_service_van_assignment',
                    'decision_key' => $decisionKey,
                    'assigned_by' => $actor->id,
                    'assigned_at' => $moment,
                    'routing_context' => [
                        'manual_reason' => $reason,
                        'van_assignment_id' => (int) $effectiveAssignment->id,
                    ],
                ]);
            }

            $this->executionStates->initialize($same, $moment);

            $before = $state->exists ? $state->toArray() : null;
            $state->fill([
                'status' => 'assigned',
                'routing_source' => $source,
                'routing_reason' => 'customer_service_van_assignment',
                'current_assignee_type' => 'van',
                'current_assignee_id' => (int) $van->id,
                'decision_key' => (string) $same->decision_key,
                'context' => $this->context($state, [
                    'reason' => $reason,
                    'van_assignment_id' => (int) $effectiveAssignment->id,
                    'order_van_assignment_id' => (int) $same->id,
                ]),
                'decided_at' => $moment,
            ])->save();

            $this->audit->record(
                'order.dispatch.manual_assigned',
                $actor,
                $order,
                $before,
                $state->fresh()->toArray(),
            );

            return $state->fresh();
        });
    }

    public function clear(Order $order, User $actor, string $reason): OrderDispatchState
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($order, $actor, $reason): OrderDispatchState {
            $activeDriver = DriverAssignment::query()
                ->where('order_id', $order->id)
                ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
                ->lockForUpdate()
                ->exists();

            if ($activeDriver) {
                throw ValidationException::withMessages([
                    'reason' => ['Active Driver assignment must be ended before clearing dispatch.'],
                ]);
            }

            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);

            OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->each(fn (OrderVanAssignment $assignment) => $assignment->forceFill([
                    'status' => 'ended',
                    'ended_at' => now(),
                ])->save());

            $before = $state->exists ? $state->toArray() : null;
            $state->fill([
                'status' => 'awaiting_dispatch',
                'routing_source' => 'manual_customer_service',
                'routing_reason' => 'customer_service_unassigned',
                'current_assignee_type' => null,
                'current_assignee_id' => null,
                'decision_key' => hash('sha256', implode('|', [
                    'manual-clear',
                    $order->id,
                    now()->format('Y-m-d H:i:s.u'),
                    Str::uuid()->toString(),
                ])),
                'context' => $this->context($state, ['reason' => $reason]),
                'decided_at' => now(),
            ])->save();

            $this->audit->record(
                'order.dispatch.manual_cleared',
                $actor,
                $order,
                $before,
                $state->fresh()->toArray(),
            );

            return $state->fresh();
        });
    }

    private function manualSource(OrderDispatchState $state, string $assigneeType, int $assigneeId): string
    {
        if (
            $state->exists
            && (string) $state->status === 'assigned'
            && (string) $state->current_assignee_type === $assigneeType
            && (int) $state->current_assignee_id === $assigneeId
            && in_array((string) $state->routing_source, ['manual_customer_service', 'reassignment_override'], true)
        ) {
            return (string) $state->routing_source;
        }

        return $state->exists
            && ($state->status === 'assigned' || $state->current_assignee_id !== null)
                ? 'reassignment_override'
                : 'manual_customer_service';
    }

    /** @param array<string,mixed> $manual */
    private function context(OrderDispatchState $state, array $manual): array
    {
        /** @var array<string,mixed> $context */
        $context = $state->getAttribute('context') ?? [];
        $context['manual_dispatch'] = $manual;

        return $context;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['A Customer Service dispatch reason is required.'],
            ]);
        }

        return $reason;
    }
}
