<?php

namespace App\Enterprise\Commercial;

use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use App\Models\User;
use App\Services\B2BOrderRoutingCoordinator;
use InvalidArgumentException;
use Throwable;

final class CommercialDeliveryRouteBridge
{
    private const INTEGRATION = 'delivery_route';

    public function __construct(
        private readonly CommercialEventFactory $events,
        private readonly CommercialContextGuard $guard,
        private readonly B2BOrderRoutingCoordinator $b2bRouting,
    ) {}

    public function handoff(Order $order, string $source): CommercialEventEnvelope
    {
        $order->refresh();

        $channel = strtolower(trim((string) $order->channel));
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new InvalidArgumentException('Commercial delivery handoff requires a supported order channel.');
        }

        $source = strtolower(trim($source));
        if ($source === '') {
            $source = 'unknown';
        }

        $storeId = (int) $order->store_id;
        $orderId = (int) $order->getKey();

        return $this->events->make(
            fact: 'order',
            channel: $channel,
            storeId: $storeId,
            aggregateType: 'order',
            aggregateId: $orderId,
            payload: [
                'integration' => self::INTEGRATION,
                'handoff_key' => $this->handoffKey($storeId, $channel, $orderId),
                'source' => $source,
                'order_id' => $orderId,
                'store_id' => $storeId,
                'channel' => $channel,
                'order_status' => (string) $order->status,
            ],
        );
    }

    public function apply(
        CommercialEventEnvelope $event,
        ?User $actor = null,
    ): ?OrderDispatchState {
        $order = $this->orderForEvent($event);

        if (strtolower((string) $order->channel) !== 'b2b') {
            return OrderDispatchState::query()
                ->where('order_id', $order->getKey())
                ->first();
        }

        try {
            return $this->b2bRouting->routeCreatedOrder(
                $order,
                $actor,
                (string) ($event->payload['source'] ?? 'commercial_handoff'),
            );
        } catch (Throwable $exception) {
            report($exception);

            return OrderDispatchState::query()
                ->where('order_id', $order->getKey())
                ->first();
        }
    }

    /** @return array<string, mixed> */
    public function reference(User $actor, int $orderId): array
    {
        $order = $this->guard->order($actor, $orderId);
        $dispatch = OrderDispatchState::query()
            ->where('order_id', $order->getKey())
            ->first();

        return [
            'order' => [
                'id' => (int) $order->getKey(),
                'store_id' => (int) $order->store_id,
                'channel' => strtolower((string) $order->channel),
                'status' => (string) $order->status,
            ],
            'dispatch' => $dispatch instanceof OrderDispatchState ? [
                'status' => (string) $dispatch->status,
                'routing_source' => $dispatch->routing_source,
                'routing_reason' => $dispatch->routing_reason,
                'decision_key' => $dispatch->decision_key,
                'assignee_type' => $dispatch->current_assignee_type,
                'assignee_id' => $dispatch->current_assignee_id === null
                    ? null
                    : (int) $dispatch->current_assignee_id,
            ] : null,
            'assignment' => $dispatch instanceof OrderDispatchState
                ? $this->currentAssignmentReference($order, $dispatch)
                : null,
        ];
    }

    private function orderForEvent(CommercialEventEnvelope $event): Order
    {
        $channel = strtolower(trim($event->channel));
        $aggregateType = strtolower(trim($event->aggregateType));
        $integration = (string) ($event->payload['integration'] ?? '');
        $orderId = (int) ($event->payload['order_id'] ?? 0);
        $storeId = (int) ($event->payload['store_id'] ?? 0);
        $payloadChannel = strtolower(trim((string) ($event->payload['channel'] ?? '')));

        if (
            $event->name !== 'foodex.commercial.order.changed'
            || $event->version !== CommercialTruthRegistry::CONTRACT_VERSION
            || $aggregateType !== 'order'
            || $integration !== self::INTEGRATION
            || ! in_array($channel, ['b2b', 'b2c'], true)
            || $event->aggregateId < 1
            || $event->storeId < 1
            || $orderId !== $event->aggregateId
            || $storeId !== $event->storeId
            || $payloadChannel !== $channel
            || (string) ($event->payload['handoff_key'] ?? '') !== $this->handoffKey(
                $event->storeId,
                $channel,
                $event->aggregateId,
            )
        ) {
            throw new InvalidArgumentException('Commercial delivery handoff identity is invalid.');
        }

        return Order::query()
            ->whereKey($event->aggregateId)
            ->where('store_id', $event->storeId)
            ->where('channel', $channel)
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    private function currentAssignmentReference(
        Order $order,
        OrderDispatchState $dispatch,
    ): ?array {
        if ((string) $dispatch->status !== 'assigned') {
            return null;
        }

        $assigneeId = $dispatch->current_assignee_id === null
            ? 0
            : (int) $dispatch->current_assignee_id;

        if ($assigneeId < 1) {
            return null;
        }

        return match ((string) $dispatch->current_assignee_type) {
            'van' => $this->currentVanReference($order, $dispatch, $assigneeId),
            'driver' => $this->currentDriverReference($order, $dispatch, $assigneeId),
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function currentVanReference(
        Order $order,
        OrderDispatchState $dispatch,
        int $vanId,
    ): ?array {
        $decisionKey = trim((string) $dispatch->decision_key);
        if ($decisionKey === '') {
            return null;
        }

        $assignment = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('van_id', $vanId)
            ->where('decision_key', $decisionKey)
            ->where('status', 'active')
            ->first();

        if (! $assignment instanceof OrderVanAssignment) {
            return null;
        }

        $execution = OrderVanExecutionState::query()
            ->where('order_van_assignment_id', $assignment->getKey())
            ->where('order_id', $order->getKey())
            ->where('van_id', $vanId)
            ->first();

        return [
            'record_type' => 'order_van_assignment',
            'id' => (int) $assignment->getKey(),
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'status' => (string) $assignment->status,
            'execution_status' => $execution?->status,
            'decision_key' => (string) $assignment->decision_key,
            'assigned_at' => (string) $assignment->assigned_at,
            'ended_at' => $assignment->ended_at === null
                ? null
                : (string) $assignment->ended_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function currentDriverReference(
        Order $order,
        OrderDispatchState $dispatch,
        int $driverId,
    ): ?array {
        $context = $dispatch->getAttribute('context');
        $manual = is_array($context) ? ($context['manual_dispatch'] ?? null) : null;
        $assignmentId = is_array($manual)
            ? (int) ($manual['driver_assignment_id'] ?? 0)
            : 0;

        if ($assignmentId < 1) {
            return null;
        }

        $assignment = DriverAssignment::query()
            ->whereKey($assignmentId)
            ->where('order_id', $order->getKey())
            ->where('store_id', $order->store_id)
            ->where('driver_id', $driverId)
            ->where('assignment_type', strtolower((string) $order->channel))
            ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])
            ->first();

        if (! $assignment instanceof DriverAssignment) {
            return null;
        }

        return [
            'record_type' => 'driver_assignment',
            'id' => (int) $assignment->getKey(),
            'actor_type' => 'driver',
            'actor_id' => $driverId,
            'status' => (string) $assignment->status,
            'execution_status' => (string) $assignment->status,
            'decision_key' => (string) $dispatch->decision_key,
            'assigned_at' => (string) $assignment->assigned_at,
            'ended_at' => $assignment->completed_at === null
                ? null
                : (string) $assignment->completed_at,
        ];
    }

    private function handoffKey(int $storeId, string $channel, int $orderId): string
    {
        return hash('sha256', implode('|', [
            'foodex-commercial-delivery-route',
            CommercialTruthRegistry::CONTRACT_VERSION,
            $storeId,
            $channel,
            $orderId,
        ]));
    }
}
