<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDispatchState;
use App\Models\OrderVanAssignment;
use App\Models\OrderVanExecutionState;
use App\Models\RoutingDecisionTrace;
use App\Models\RoutingPolicy;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\VanAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OrderTerritoryRoutingService
{
    public function __construct(
        private readonly TerritoryService $territories,
        private readonly RoutingPolicyService $routingPolicies,
        private readonly AuditLogger $audit,
        private readonly FulfillmentActorPolicy $actors,
        private readonly VanExecutionStateService $executionStates,
    ) {}

    public function route(
        Order $order,
        ?User $actor = null,
        Carbon|string|null $at = null,
        ?string $orderSource = null,
    ): OrderDispatchState {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));
        $order->refresh();
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::VAN);

        $existing = OrderDispatchState::query()->where('order_id', $order->id)->first();
        if ($existing !== null && in_array($existing->routing_source, ['manual_customer_service', 'reassignment_override'], true)) {
            return $existing;
        }

        $locked = $this->activeExecutionLock($order);
        if ($locked !== null) {
            return $this->persistRerouteBlocked($order, $locked, $actor, $moment, $orderSource);
        }

        $resolution = $this->territories->resolveAddress(
            'order',
            (string) $order->id,
            $order->delivery_latitude === null ? null : (float) $order->delivery_latitude,
            $order->delivery_longitude === null ? null : (float) $order->delivery_longitude,
            actor: $actor,
            at: $moment,
        );

        $territory = isset($resolution['territory_id'])
            ? ServiceTerritory::query()->find($resolution['territory_id'])
            : null;

        $input = [
            'order_id' => (int) $order->id,
            'store_id' => (int) $order->store_id,
            'channel' => (string) $order->channel,
            'territory_id' => $territory?->id,
            'territory_code' => $territory?->code,
            'latitude' => $order->delivery_latitude,
            'longitude' => $order->delivery_longitude,
            'order_source' => $orderSource,
        ];
        $scope = [
            'store_id' => (int) $order->store_id,
            'channel' => (string) $order->channel,
        ];

        $policyResolution = $this->effectivePolicy($moment);
        if ($policyResolution['ambiguous']) {
            return $this->persistPending(
                $order,
                $territory,
                null,
                null,
                'policy_resolution',
                'multiple_effective_routing_policies',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        /** @var RoutingPolicy|null $policy */
        $policy = $policyResolution['policy'];

        if ($policy === null) {
            return $this->routeWithoutPolicy($order, $territory, $resolution, $actor, $moment, $orderSource);
        }

        $trace = $this->routingPolicies->route(
            $policy->code,
            'order',
            (string) $order->id,
            $input,
            $moment,
            $scope,
        );

        $mode = strtoupper((string) $trace->routing_mode);
        if ($mode === 'MANUAL') {
            return $this->persistPending(
                $order,
                $territory,
                $policy,
                $trace,
                'routing_policy',
                'manual_policy_mode',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        $rawResult = $trace->getAttribute('result');
        $result = is_array($rawResult) ? $rawResult : [];
        if (($result['manual_dispatch_required'] ?? false) === true || strtolower((string) ($result['action'] ?? '')) === 'manual') {
            return $this->persistPending(
                $order,
                $territory,
                $policy,
                $trace,
                'routing_policy',
                'policy_requires_manual_dispatch',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        $vanId = $this->policyVanId($result);
        if ($vanId === null) {
            return $this->persistPending(
                $order,
                $territory,
                $policy,
                $trace,
                'routing_policy',
                'policy_did_not_resolve_van',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        if ($territory === null) {
            return $this->persistPending(
                $order,
                null,
                $policy,
                $trace,
                'routing_policy',
                'policy_van_without_resolved_territory',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        $eligible = $this->eligiblePrimaryAssignments($territory, $moment)
            ->firstWhere('van_id', $vanId);

        if (($eligible instanceof VanAssignment) === false) {
            return $this->persistPending(
                $order,
                $territory,
                $policy,
                $trace,
                'routing_policy',
                'policy_selected_ineligible_van',
                $resolution,
                $actor,
                $moment,
                $orderSource,
            );
        }

        return $this->persistAssignment(
            $order,
            $territory,
            $eligible,
            $policy,
            $trace,
            'routing_policy',
            'policy_selected_eligible_van',
            $resolution,
            $actor,
            $moment,
            $orderSource,
        );
    }

    /**
     * Persist a safe post-create exception state without invalidating the commercial order.
     *
     * @param  array<string,mixed>  $context
     */
    public function markPostCreateFailure(
        Order $order,
        ?User $actor,
        string $orderSource,
        string $reason,
        array $context = [],
        Carbon|string|null $at = null,
    ): OrderDispatchState {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));
        $order->refresh();
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::VAN);

        $existing = OrderDispatchState::query()->where('order_id', $order->id)->first();
        if ($existing instanceof OrderDispatchState && (in_array((string) $existing->routing_source, ['manual_customer_service', 'reassignment_override'], true) || ((string) $existing->status === 'assigned' && (string) $existing->current_assignee_type === 'van' && $existing->current_assignee_id !== null))) {
            return $existing;
        }

        return $this->persistPending(
            $order, null, null, null, 'post_create_routing', $reason,
            ['routing_failure' => true, 'post_create_context' => $context],
            $actor, $moment, $orderSource,
        );
    }

    private function routeWithoutPolicy(
        Order $order,
        ?ServiceTerritory $territory,
        array $resolution,
        ?User $actor,
        Carbon $moment,
        ?string $orderSource,
    ): OrderDispatchState {
        if ($order->delivery_latitude === null || $order->delivery_longitude === null) {
            return $this->persistPending($order, null, null, null, 'auto_territory_fallback', 'missing_delivery_coordinates', $resolution, $actor, $moment, $orderSource);
        }

        if ($territory === null) {
            return $this->persistPending($order, null, null, null, 'auto_territory_fallback', 'unmapped_delivery_address', $resolution, $actor, $moment, $orderSource);
        }

        $eligible = $this->eligiblePrimaryAssignments($territory, $moment);

        if ($eligible->count() === 0) {
            return $this->persistPending($order, $territory, null, null, 'auto_territory_fallback', 'no_eligible_primary_van', $resolution, $actor, $moment, $orderSource);
        }

        if ($eligible->count() > 1) {
            return $this->persistPending($order, $territory, null, null, 'auto_territory_fallback', 'ambiguous_primary_vans', $resolution, $actor, $moment, $orderSource);
        }

        /** @var VanAssignment $assignment */
        $assignment = $eligible->first();

        return $this->persistAssignment(
            $order,
            $territory,
            $assignment,
            null,
            null,
            'auto_territory_fallback',
            'unique_eligible_primary_van',
            $resolution,
            $actor,
            $moment,
            $orderSource,
        );
    }

    private function eligiblePrimaryAssignments(ServiceTerritory $territory, Carbon $moment)
    {
        return VanAssignment::query()
            ->with('van')
            ->where('territory_key', $territory->code)
            ->where('assignment_type', 'primary')
            ->where('status', 'active')
            ->where('effective_from', '<=', $moment)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->whereHas('van', fn ($query) => $query->where('status', 'active'))
            ->orderBy('van_id')
            ->get();
    }

    /** @return array{policy:?RoutingPolicy,ambiguous:bool} */
    private function effectivePolicy(Carbon $moment): array
    {
        $policies = RoutingPolicy::query()
            ->where('status', RoutingPolicyService::PUBLISHED)
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $moment))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $moment))
            ->orderByDesc('version')
            ->get();

        if ($policies->isEmpty()) {
            return ['policy' => null, 'ambiguous' => false];
        }

        foreach (['order_dispatch', 'default'] as $canonicalCode) {
            $canonical = $policies->where('code', $canonicalCode);
            if ($canonical->count() === 1) {
                return ['policy' => $canonical->first(), 'ambiguous' => false];
            }
        }

        if ($policies->count() === 1) {
            return ['policy' => $policies->first(), 'ambiguous' => false];
        }

        return ['policy' => null, 'ambiguous' => true];
    }

    /** @param array<string,mixed> $result */
    private function policyVanId(array $result): ?int
    {
        if (isset($result['van_id']) && is_numeric($result['van_id'])) {
            return (int) $result['van_id'];
        }

        if (($result['assignee_type'] ?? null) === 'van' && isset($result['assignee_id']) && is_numeric($result['assignee_id'])) {
            return (int) $result['assignee_id'];
        }

        return null;
    }

    /** @return array{assignment:OrderVanAssignment,execution_status:string,order_status:string}|null */
    private function activeExecutionLock(Order $order): ?array
    {
        $assignment = OrderVanAssignment::query()->where('order_id', $order->id)->where('status', 'active')->latest('id')->first();
        if (! $assignment instanceof OrderVanAssignment) {
            return null;
        }
        $executionStatus = strtolower((string) OrderVanExecutionState::query()->where('order_van_assignment_id', $assignment->id)->value('status'));
        $orderStatus = strtolower((string) $order->status);
        if (! in_array($executionStatus, ['picked_up', 'out_for_delivery'], true) && $orderStatus !== 'out_for_delivery') {
            return null;
        }

        return ['assignment' => $assignment, 'execution_status' => $executionStatus, 'order_status' => $orderStatus];
    }

    /** @param array{assignment:OrderVanAssignment,execution_status:string,order_status:string} $locked */
    private function persistRerouteBlocked(Order $order, array $locked, ?User $actor, Carbon $moment, ?string $orderSource): OrderDispatchState
    {
        /** @var OrderVanAssignment $assignment */
        $assignment = $locked['assignment'];

        return DB::transaction(function () use ($order, $assignment, $locked, $actor, $moment, $orderSource): OrderDispatchState {
            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);
            $before = $state->exists ? $state->toArray() : null;
            $rawContext = $state->getAttribute('context');
            $context = is_array($rawContext) ? $rawContext : [];
            $context['reroute_guard'] = ['reason' => 'in_progress_execution_locked', 'order_source' => $orderSource, 'order_van_assignment_id' => (int) $assignment->id, 'execution_status' => $locked['execution_status'], 'order_status' => $locked['order_status'], 'blocked_at' => $moment->toAtomString()];
            $state->fill(['status' => 'assigned', 'routing_source' => $state->routing_source ?: $assignment->source, 'routing_reason' => $state->routing_reason ?: $assignment->reason, 'current_assignee_type' => 'van', 'current_assignee_id' => (int) $assignment->van_id, 'decision_key' => $state->decision_key ?: $assignment->decision_key, 'context' => $context, 'decided_at' => $state->decided_at ?: $assignment->assigned_at ?: $moment])->save();
            $this->audit->record('order.dispatch.reroute_blocked', $actor, $order, $before, $state->fresh()->toArray());

            return $state->fresh();
        });
    }

    private function persistPending(
        Order $order,
        ?ServiceTerritory $territory,
        ?RoutingPolicy $policy,
        ?RoutingDecisionTrace $trace,
        string $source,
        string $reason,
        array $resolution,
        ?User $actor,
        Carbon $moment,
        ?string $orderSource,
    ): OrderDispatchState {
        $territoryId = $territory instanceof ServiceTerritory ? (int) $territory->getKey() : null;
        $policyId = $policy instanceof RoutingPolicy ? (int) $policy->getKey() : null;
        $traceId = $trace instanceof RoutingDecisionTrace ? (int) $trace->getKey() : null;
        $decisionKey = hash('sha256', implode('|', [
            $order->id,
            $territoryId ?? 'none',
            $policyId ?? 'none',
            $traceId ?? 'none',
            $source,
            $reason,
        ]));

        return DB::transaction(function () use ($order, $territory, $policy, $trace, $source, $reason, $resolution, $actor, $moment, $decisionKey, $orderSource): OrderDispatchState {
            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);

            if ($state->exists && in_array($state->routing_source, ['manual_customer_service', 'reassignment_override'], true)) {
                return $state;
            }

            OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->update(['status' => 'ended', 'ended_at' => $moment, 'updated_at' => now()]);

            $before = $state->exists ? $state->toArray() : null;
            $state->fill([
                'status' => 'awaiting_dispatch',
                'service_territory_id' => $territory?->id,
                'routing_policy_id' => $policy?->id,
                'routing_decision_trace_id' => $trace?->id,
                'routing_mode' => $trace?->routing_mode,
                'routing_source' => $source,
                'routing_reason' => $reason,
                'current_assignee_type' => null,
                'current_assignee_id' => null,
                'decision_key' => $decisionKey,
                'context' => ['order_source' => $orderSource, 'territory_resolution' => $resolution],
                'decided_at' => $moment,
            ])->save();

            $this->audit->record('order.dispatch.awaiting', $actor, $order, $before, $state->fresh()->toArray());

            return $state->fresh();
        });
    }

    private function persistAssignment(
        Order $order,
        ServiceTerritory $territory,
        VanAssignment $eligible,
        ?RoutingPolicy $policy,
        ?RoutingDecisionTrace $trace,
        string $source,
        string $reason,
        array $resolution,
        ?User $actor,
        Carbon $moment,
        ?string $orderSource,
    ): OrderDispatchState {
        $policyId = $policy instanceof RoutingPolicy ? (int) $policy->getKey() : null;
        $decisionKey = hash('sha256', implode('|', [
            $order->id,
            $territory->id,
            $eligible->id,
            $policyId ?? 'none',
            $source,
        ]));

        return DB::transaction(function () use ($order, $territory, $eligible, $policy, $trace, $source, $reason, $resolution, $actor, $moment, $decisionKey, $orderSource): OrderDispatchState {
            $state = OrderDispatchState::query()->lockForUpdate()->firstOrNew(['order_id' => $order->id]);

            if ($state->exists && in_array($state->routing_source, ['manual_customer_service', 'reassignment_override'], true)) {
                return $state;
            }

            $active = OrderVanAssignment::query()
                ->where('order_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $same = $active->firstWhere('decision_key', $decisionKey);
            if (($same instanceof OrderVanAssignment) === false) {
                foreach ($active as $assignment) {
                    $assignment->forceFill(['status' => 'reassigned', 'ended_at' => $moment])->save();
                }

                $same = OrderVanAssignment::query()->firstOrCreate(
                    ['decision_key' => $decisionKey],
                    [
                        'order_id' => $order->id,
                        'van_id' => $eligible->van_id,
                        'van_assignment_id' => $eligible->id,
                        'service_territory_id' => $territory->id,
                        'status' => 'active',
                        'source' => $source,
                        'reason' => $reason,
                        'assigned_by' => $actor?->id,
                        'assigned_at' => $moment,
                        'routing_context' => [
                            'order_source' => $orderSource,
                            'routing_policy_id' => $policy?->id,
                            'routing_decision_trace_id' => $trace?->id,
                            'territory_resolution' => $resolution,
                        ],
                    ],
                );
            }

            $this->executionStates->initialize($same, $moment);

            $before = $state->exists ? $state->toArray() : null;
            $state->fill([
                'status' => 'assigned',
                'service_territory_id' => $territory->id,
                'routing_policy_id' => $policy?->id,
                'routing_decision_trace_id' => $trace?->id,
                'routing_mode' => $trace instanceof RoutingDecisionTrace
                    ? (string) $trace->getAttribute('routing_mode')
                    : 'AUTOMATIC',
                'routing_source' => $source,
                'routing_reason' => $reason,
                'current_assignee_type' => 'van',
                'current_assignee_id' => $eligible->van_id,
                'decision_key' => $decisionKey,
                'context' => [
                    'order_source' => $orderSource,
                    'van_assignment_id' => $eligible->id,
                    'territory_resolution' => $resolution,
                ],
                'decided_at' => $moment,
            ])->save();

            $this->audit->record('order.dispatch.assigned', $actor, $order, $before, $state->fresh()->toArray());

            return $state->fresh();
        });
    }
}
