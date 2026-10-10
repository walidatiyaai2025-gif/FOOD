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

    public function route(Order $order, ?User $actor = null, Carbon|string|null $at = null): OrderDispatchState
    {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));
        $order->refresh();
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::VAN);

        $existing = OrderDispatchState::query()->where('order_id', $order->id)->first();
        if ($existing !== null && in_array($existing->routing_source, ['manual_customer_service', 'reassignment_override'], true)) {
            return $existing;
        }

        if ($existing !== null && $this->hasPhysicalCustody($order)) {
            return $existing;
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
            );
        }

        /** @var RoutingPolicy|null $policy */
        $policy = $policyResolution['policy'];

        if ($policy === null) {
            return $this->routeWithoutPolicy($order, $territory, $resolution, $actor, $moment);
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
        );
    }

    /** @param array<string, mixed> $context */
    public function awaitingDispatch(
        Order $order,
        ?User $actor,
        string $reason,
        array $context = [],
        Carbon|string|null $at = null,
    ): OrderDispatchState {
        $moment = $at instanceof Carbon ? $at : ($at === null ? now() : Carbon::parse($at));
        $order->refresh();
        $this->actors->assertOrderActor($order, FulfillmentActorPolicy::VAN);

        return $this->persistPending(
            $order,
            null,
            null,
            null,
            'order_created_hook',
            $reason,
            $context,
            $actor,
            $moment,
        );
    }

    private function routeWithoutPolicy(
        Order $order,
        ?ServiceTerritory $territory,
        array $resolution,
        ?User $actor,
        Carbon $moment,
    ): OrderDispatchState {
        if ($order->delivery_latitude === null || $order->delivery_longitude === null) {
            return $this->persistPending($order, null, null, null, 'auto_territory_fallback', 'missing_delivery_coordinates', $resolution, $actor, $moment);
        }

        if ($territory === null) {
            return $this->persistPending($order, null, null, null, 'auto_territory_fallback', 'unmapped_delivery_address', $resolution, $actor, $moment);
        }

        $eligible = $this->eligiblePrimaryAssignments($territory, $moment);

        if ($eligible->count() === 0) {
            return $this->persistPending($order, $territory, null, null, 'auto_territory_fallback', 'no_eligible_primary_van', $resolution, $actor, $moment);
        }

        if ($eligible->count() > 1) {
            return $this->persistPending($order, $territory, null, null, 'auto_territory_fallback', 'ambiguous_primary_vans', $resolution, $actor, $moment);
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

    private function hasPhysicalCustody(Order $order): bool
    {
        if ((string) $order->status === 'out_for_delivery') {
            return true;
        }

        return OrderVanExecutionState::query()
            ->where('order_id', $order->id)
            ->whereIn('status', ['picked_up', 'out_for_delivery'])
            ->whereHas('assignment', fn ($query) => $query->where('status', 'active'))
            ->exists();
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

        return DB::transaction(function () use ($order, $territory, $policy, $trace, $source, $reason, $resolution, $actor, $moment, $decisionKey): OrderDispatchState {
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
                'context' => ['territory_resolution' => $resolution],
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
    ): OrderDispatchState {
        $policyId = $policy instanceof RoutingPolicy ? (int) $policy->getKey() : null;
        $decisionKey = hash('sha256', implode('|', [
            $order->id,
            $territory->id,
            $eligible->id,
            $policyId ?? 'none',
            $source,
        ]));

        return DB::transaction(function () use ($order, $territory, $eligible, $policy, $trace, $source, $reason, $resolution, $actor, $moment, $decisionKey): OrderDispatchState {
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
