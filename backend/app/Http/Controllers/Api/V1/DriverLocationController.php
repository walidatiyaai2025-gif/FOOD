<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriverAssignment;
use App\Models\DriverCurrentLocation;
use App\Services\DriverRuntimeContextResolver;
use App\Services\FleetLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverLocationController extends Controller
{
    public function heartbeat(Request $request, DriverRuntimeContextResolver $driverContext, FleetLocationService $fleet): JsonResponse
    {
        [$driver, $channel, $storeId] = $driverContext->resolve($request);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'captured_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'app_version' => ['nullable', 'string', 'max:64'],
            'is_mocked' => ['nullable', 'boolean'],
        ]);

        $activeAssignment = DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->where('store_id', $storeId)
            ->whereIn('status', ['accepted', 'picked_up', 'out_for_delivery'])
            ->whereNull('completed_at')
            ->latest('id')
            ->first(['id', 'status']);
        $activeAssignmentId = $activeAssignment?->getKey();
        $activeAssignmentStatus = $activeAssignment?->status;

        $incomingCapturedAt = CarbonImmutable::parse((string) $data['captured_at']);

        $location = DB::transaction(function () use (
            $driver,
            $channel,
            $storeId,
            $data,
            $activeAssignmentId,
            $incomingCapturedAt,
        ): DriverCurrentLocation {
            $location = DriverCurrentLocation::query()
                ->where('driver_id', $driver->getKey())
                ->lockForUpdate()
                ->first();

            if (
                $location instanceof DriverCurrentLocation
                && CarbonImmutable::parse((string) $location->captured_at)->greaterThanOrEqualTo($incomingCapturedAt)
            ) {
                $normalizedActiveAssignmentId = $activeAssignmentId === null
                    ? null
                    : (int) $activeAssignmentId;

                if ($location->active_assignment_id !== $normalizedActiveAssignmentId) {
                    $location->forceFill([
                        'active_assignment_id' => $normalizedActiveAssignmentId,
                    ])->save();
                }

                return $location->fresh();
            }

            $values = [
                'store_id' => $storeId,
                'channel' => $channel,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'speed' => $data['speed'] ?? null,
                'heading' => $data['heading'] ?? null,
                'captured_at' => $incomingCapturedAt,
                'received_at' => now(),
                'app_version' => $data['app_version'] ?? null,
                'is_mocked' => $data['is_mocked'] ?? null,
                'active_assignment_id' => $activeAssignmentId === null ? null : (int) $activeAssignmentId,
            ];

            if ($location instanceof DriverCurrentLocation) {
                $location->forceFill($values)->save();

                return $location->fresh();
            }

            return DriverCurrentLocation::query()->create([
                'driver_id' => $driver->getKey(),
                ...$values,
            ]);
        }, 3);

        $fleet->heartbeat('driver', (int) $driver->getKey(), [
            'assignment_id' => $activeAssignmentId === null ? null : (int) $activeAssignmentId,
            'store_id' => (int) $storeId,
            'channel' => (string) $channel,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy' => $data['accuracy'] ?? null,
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'captured_at' => $data['captured_at'],
            'source_app' => 'driver',
            'app_version' => $data['app_version'] ?? null,
            'is_mocked' => $data['is_mocked'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'driver_id' => (int) $location->driver_id,
                'store_id' => (int) $location->store_id,
                'channel' => (string) $location->channel,
                'captured_at' => CarbonImmutable::parse((string) $location->captured_at)->toISOString(),
                'received_at' => CarbonImmutable::parse((string) $location->received_at)->toISOString(),
                'active_assignment_id' => $activeAssignmentId === null
                    ? null
                    : (int) $activeAssignmentId,
                'active_assignment_status' => $activeAssignmentStatus === null
                    ? null
                    : (string) $activeAssignmentStatus,
                'tracking_required' => $activeAssignmentId !== null,
            ],
        ]);
    }
}
