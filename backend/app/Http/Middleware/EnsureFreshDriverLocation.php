<?php

namespace App\Http\Middleware;

use App\Models\DriverAssignment;
use App\Models\DriverCurrentLocation;
use App\Services\DriverLocationEnforcementPolicy;
use App\Services\DriverRuntimeContextResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFreshDriverLocation
{
    public function __construct(
        private readonly DriverLocationEnforcementPolicy $policy,
        private readonly DriverRuntimeContextResolver $driverContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->policy->enabled()) {
            return $next($request);
        }

        [$driver, $channel, $storeId] = $this->driverContext->resolve($request);
        $freshnessSeconds = $this->policy->freshnessSeconds();

        if ($this->allowsFailureRecovery($request, (int) $driver->getKey(), $channel, $storeId)) {
            return $next($request);
        }

        $location = DriverCurrentLocation::query()
            ->where('driver_id', $driver->getKey())
            ->first();

        if (! $location instanceof DriverCurrentLocation) {
            return $this->blocked('missing', $freshnessSeconds);
        }

        if (
            (int) $location->driver_id !== (int) $driver->getKey()
            || (int) $location->store_id !== $storeId
            || strtolower((string) $location->channel) !== $channel
        ) {
            return $this->blocked('scope_mismatch', $freshnessSeconds);
        }

        $receivedAt = trim((string) $location->received_at);
        if (
            $receivedAt === ''
            || CarbonImmutable::parse($receivedAt)->lt(now()->subSeconds($freshnessSeconds))
        ) {
            return $this->blocked('stale', $freshnessSeconds);
        }

        return $next($request);
    }

    private function allowsFailureRecovery(
        Request $request,
        int $driverId,
        string $channel,
        int $storeId,
    ): bool {
        if (
            ! $request->isMethod('post')
            || strtolower(trim((string) $request->input('status'))) !== 'failed'
            || trim((string) $request->input('failure_reason')) === ''
        ) {
            return false;
        }

        $assignmentId = (int) $request->route('assignment');
        if ($assignmentId <= 0) {
            return false;
        }

        return DriverAssignment::query()
            ->whereKey($assignmentId)
            ->where('driver_id', $driverId)
            ->where('assignment_type', $channel)
            ->where('store_id', $storeId)
            ->whereIn('status', ['accepted', 'picked_up', 'out_for_delivery'])
            ->whereNull('completed_at')
            ->exists();
    }

    private function blocked(string $reason, int $freshnessSeconds): JsonResponse
    {
        return response()->json([
            'message' => 'Fresh driver location heartbeat is required.',
            'code' => 'DRIVER_LOCATION_HEARTBEAT_REQUIRED',
            'reason' => $reason,
            'freshness_seconds' => $freshnessSeconds,
        ], 428);
    }
}
