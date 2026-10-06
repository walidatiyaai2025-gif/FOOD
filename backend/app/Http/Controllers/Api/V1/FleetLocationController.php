<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FleetCurrentLocation;
use App\Models\User;
use App\Services\FleetLocationService;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FleetLocationController extends Controller
{
    public function vanHeartbeat(Request $request, FleetLocationService $service): JsonResponse
    {
        Gate::authorize('drivers.b2b.manage');

        $data = $request->validate([
            'van_id' => ['required', 'integer', 'min:1'],
            'assignment_id' => ['nullable', 'integer', 'min:1'],
            'route_key' => ['nullable', 'string', 'max:128'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'captured_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'app_version' => ['nullable', 'string', 'max:64'],
            'is_mocked' => ['nullable', 'boolean'],
        ]);

        $location = $service->heartbeat('van', (int) $data['van_id'], [
            'vehicle_id' => (int) $data['van_id'],
            'assignment_id' => $data['assignment_id'] ?? null,
            'route_key' => $data['route_key'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'channel' => $data['channel'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy' => $data['accuracy'] ?? null,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'captured_at' => $data['captured_at'],
            'source_app' => 'van',
            'app_version' => $data['app_version'] ?? null,
            'is_mocked' => $data['is_mocked'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($location, $service)]);
    }

    public function feed(Request $request, FleetLocationService $service, OperationalTenantScope $tenantScope): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'actor_type' => ['nullable', Rule::in(['driver', 'van'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', Rule::in(['b2b', 'b2c'])],
            'status' => ['nullable', Rule::in(['online', 'stale', 'offline'])],
            'north' => ['nullable', 'numeric', 'between:-90,90'],
            'south' => ['nullable', 'numeric', 'between:-90,90'],
            'east' => ['nullable', 'numeric', 'between:-180,180'],
            'west' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $channels = isset($data['channel']) ? [(string) $data['channel']] : ['b2b', 'b2c'];
        $allowedStoresByChannel = [];

        foreach ($channels as $channel) {
            $storeIds = $tenantScope->allowedStoreIds($user, 'drivers.tracking.view', $channel);
            if ($storeIds !== []) {
                $allowedStoresByChannel[$channel] = $storeIds;
            }
        }

        abort_if($allowedStoresByChannel === [], 403);

        if (isset($data['store_id'])) {
            $requestedStoreId = (int) $data['store_id'];
            foreach ($allowedStoresByChannel as $channel => $storeIds) {
                if (in_array($requestedStoreId, $storeIds, true)) {
                    $allowedStoresByChannel[$channel] = [$requestedStoreId];
                } else {
                    unset($allowedStoresByChannel[$channel]);
                }
            }
            abort_if($allowedStoresByChannel === [], 404);
        }

        $rows = FleetCurrentLocation::query()
            ->where(function ($query) use ($allowedStoresByChannel): void {
                foreach ($allowedStoresByChannel as $channel => $storeIds) {
                    $query->orWhere(function ($scope) use ($channel, $storeIds): void {
                        $scope->where('channel', $channel)->whereIn('store_id', $storeIds);
                    });
                }
            })
            ->when(isset($data['actor_type']), fn ($query) => $query->where('actor_type', $data['actor_type']))
            ->when(isset($data['north']), fn ($query) => $query->where('latitude', '<=', (float) $data['north']))
            ->when(isset($data['south']), fn ($query) => $query->where('latitude', '>=', (float) $data['south']))
            ->when(isset($data['east']), fn ($query) => $query->where('longitude', '<=', (float) $data['east']))
            ->when(isset($data['west']), fn ($query) => $query->where('longitude', '>=', (float) $data['west']))
            ->orderByDesc('received_at')
            ->limit(500)
            ->get()
            ->map(fn (FleetCurrentLocation $location): array => $this->serialize($location, $service))
            ->when(
                isset($data['status']),
                fn ($rows) => $rows->where('status', $data['status'])->values(),
            );

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $rows->count(),
                'generated_at' => CarbonImmutable::now()->toISOString(),
                'freshness' => ['online_seconds' => 45, 'stale_seconds' => 180],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function serialize(FleetCurrentLocation $location, FleetLocationService $service): array
    {
        return [
            'actor_type' => (string) $location->actor_type,
            'actor_id' => (int) $location->actor_id,
            'vehicle_id' => $location->vehicle_id === null ? null : (int) $location->vehicle_id,
            'assignment_id' => $location->assignment_id === null ? null : (int) $location->assignment_id,
            'route_key' => $location->route_key,
            'store_id' => $location->store_id === null ? null : (int) $location->store_id,
            'channel' => $location->channel,
            'status' => $service->status($location),
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy' => $location->accuracy === null ? null : (float) $location->accuracy,
            'speed' => $location->speed === null ? null : (float) $location->speed,
            'heading' => $location->heading === null ? null : (float) $location->heading,
            'captured_at' => CarbonImmutable::parse((string) $location->captured_at)->toISOString(),
            'received_at' => CarbonImmutable::parse((string) $location->received_at)->toISOString(),
            'source_app' => $location->source_app,
            'app_version' => $location->app_version,
        ];
    }
}
