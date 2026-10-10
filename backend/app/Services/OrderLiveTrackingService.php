<?php

namespace App\Services;

use App\Models\FleetCurrentLocation;
use App\Models\Order;
use App\Models\OrderVanAssignment;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderLiveTrackingService
{
    public function __construct(private readonly FleetLocationService $fleetLocations) {}

    /** @return array<string,mixed>|null */
    public function forOrder(Order $order): ?array
    {
        return match (strtolower((string) $order->channel)) {
            'b2b' => $this->vanTracking($order),
            'b2c' => $this->driverTracking($order),
            default => null,
        };
    }

    /** @return array<string,mixed>|null */
    private function vanTracking(Order $order): ?array
    {
        $dispatch = DB::table('order_dispatch_states')
            ->where('order_id', $order->getKey())
            ->first(['status', 'decided_at', 'updated_at']);

        $assignment = OrderVanAssignment::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'active')
            ->latest('assigned_at')
            ->latest('id')
            ->first();

        if (! $assignment instanceof OrderVanAssignment) {
            if ($dispatch === null) {
                return null;
            }

            return [
                'actor_type' => 'van',
                'assignment_id' => null,
                'van_id' => null,
                'van_code' => null,
                'status' => (string) $dispatch->status,
                'dispatch_status' => (string) $dispatch->status,
                'assigned_at' => null,
                'completed_at' => null,
                'live_status' => 'offline',
                'location' => null,
            ];
        }

        $van = Van::query()->find((int) $assignment->van_id);
        $execution = DB::table('order_van_execution_states')
            ->where('order_van_assignment_id', $assignment->getKey())
            ->first(['status', 'last_transition_at']);

        $location = FleetCurrentLocation::query()
            ->where('actor_type', 'van')
            ->where('actor_id', (int) $assignment->van_id)
            ->first();

        $status = $execution?->status === null
            ? (string) $assignment->status
            : (string) $execution->status;

        return [
            'actor_type' => 'van',
            'assignment_id' => (int) $assignment->getKey(),
            'van_id' => (int) $assignment->van_id,
            'van_code' => $van?->code,
            'status' => $status,
            'dispatch_status' => $dispatch?->status === null ? null : (string) $dispatch->status,
            'assigned_at' => $assignment->assigned_at?->toAtomString(),
            'completed_at' => $status === 'delivered'
                ? ($execution?->last_transition_at === null
                    ? $assignment->ended_at?->toAtomString()
                    : CarbonImmutable::parse((string) $execution->last_transition_at)->toAtomString())
                : null,
            'live_status' => $location instanceof FleetCurrentLocation
                ? $this->fleetLocations->status($location)
                : 'offline',
            'location' => $location instanceof FleetCurrentLocation
                ? $this->fleetLocationPayload($location)
                : null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function driverTracking(Order $order): ?array
    {
        $assignment = DB::table('driver_assignments')
            ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('driver_assignments.order_id', $order->getKey())
            ->where('driver_assignments.store_id', $order->store_id)
            ->where('driver_assignments.assignment_type', 'b2c')
            ->whereNotIn('driver_assignments.status', [
                'cancelled',
                'unassigned',
                'reassigned',
            ])
            ->orderByDesc('driver_assignments.id')
            ->first([
                'driver_assignments.id',
                'driver_assignments.driver_id',
                'driver_assignments.status',
                'driver_assignments.assigned_at',
                'driver_assignments.completed_at',
                'users.name as driver_name',
            ]);

        if ($assignment === null) {
            return null;
        }

        $location = DB::table('driver_current_locations')
            ->where('driver_id', (int) $assignment->driver_id)
            ->where('store_id', $order->store_id)
            ->where('channel', 'b2c')
            ->first([
                'latitude',
                'longitude',
                'accuracy',
                'speed',
                'heading',
                'captured_at',
                'received_at',
            ]);

        return [
            'actor_type' => 'driver',
            'assignment_id' => (int) $assignment->id,
            'driver_id' => (int) $assignment->driver_id,
            'driver_name' => $assignment->driver_name === null ? null : (string) $assignment->driver_name,
            'status' => (string) $assignment->status,
            'assigned_at' => $assignment->assigned_at,
            'completed_at' => $assignment->completed_at,
            'live_status' => $location === null
                ? 'offline'
                : $this->freshness((string) $location->received_at),
            'location' => $location === null ? null : [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy' => $location->accuracy === null ? null : (float) $location->accuracy,
                'speed' => $location->speed === null ? null : (float) $location->speed,
                'heading' => $location->heading === null ? null : (float) $location->heading,
                'captured_at' => CarbonImmutable::parse((string) $location->captured_at)->toISOString(),
                'received_at' => CarbonImmutable::parse((string) $location->received_at)->toISOString(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fleetLocationPayload(FleetCurrentLocation $location): array
    {
        return [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy' => $location->accuracy === null ? null : (float) $location->accuracy,
            'speed' => $location->speed === null ? null : (float) $location->speed,
            'heading' => $location->heading === null ? null : (float) $location->heading,
            'captured_at' => CarbonImmutable::parse((string) $location->captured_at)->toISOString(),
            'received_at' => CarbonImmutable::parse((string) $location->received_at)->toISOString(),
        ];
    }

    private function freshness(string $receivedAt): string
    {
        $received = CarbonImmutable::parse($receivedAt);
        $now = CarbonImmutable::now();

        if ($received->greaterThanOrEqualTo($now->subSeconds(45))) {
            return 'online';
        }

        if ($received->greaterThanOrEqualTo($now->subMinutes(3))) {
            return 'stale';
        }

        return 'offline';
    }
}
