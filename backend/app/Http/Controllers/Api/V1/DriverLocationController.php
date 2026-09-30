<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\DriverCurrentLocation;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriverLocationController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        [$driver, $channel, $storeId] = $this->driverContext($request);

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

        $activeAssignmentId = DriverAssignment::query()
            ->where('driver_id', $driver->getKey())
            ->where('assignment_type', $channel)
            ->where('store_id', $storeId)
            ->whereNotIn('status', ['delivered', 'failed', 'cancelled', 'unassigned', 'reassigned'])
            ->latest('id')
            ->value('id');

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
                return $location;
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

        return response()->json([
            'data' => [
                'driver_id' => (int) $location->driver_id,
                'store_id' => (int) $location->store_id,
                'channel' => (string) $location->channel,
                'captured_at' => CarbonImmutable::parse((string) $location->captured_at)->toISOString(),
                'received_at' => CarbonImmutable::parse((string) $location->received_at)->toISOString(),
                'active_assignment_id' => $location->active_assignment_id,
            ],
        ]);
    }

    /** @return array{0: Driver, 1: string, 2: int} */
    private function driverContext(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $driver = Driver::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
        abort_unless($driver instanceof Driver, 403, 'Active driver profile is required.');

        $channel = strtolower((string) $driver->driver_type);
        abort_unless(in_array($channel, ['b2c', 'b2b'], true), 403);
        abort_unless($user->hasPermission("deliveries.{$channel}.execute"), 403);

        $storeId = (int) ($driver->store_id ?? 0);
        if ($storeId < 1 && $channel === 'b2b') {
            $storeId = app(WholesalePrincipal::class)->storeId();
            $driver->forceFill(['store_id' => $storeId])->save();
        }

        abort_unless($storeId > 0, 409, 'Driver store context is required before location tracking.');

        return [$driver->fresh(), $channel, $storeId];
    }
}
