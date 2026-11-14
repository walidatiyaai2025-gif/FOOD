<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\Request;

final class DriverRuntimeContextResolver
{
    public function __construct(private readonly DriverTenantScope $driverTenants) {}

    /** @return array{0: Driver, 1: string, 2: int} */
    public function resolve(Request $request): array
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

        // Driver routes are already authorized by EnsureDriverRuntimeAccess.
        // Recheck that its trusted snapshot still describes this same profile.
        // A reassignment between the middleware and heartbeat/freshness checks
        // must not silently switch the request into another store's scope.
        $authorized = $request->attributes->get('driver_runtime_context');
        abort_unless(
            is_array($authorized)
                && (int) ($authorized['driver_id'] ?? 0) === (int) $driver->getKey()
                && ($authorized['channel'] ?? null) === $channel,
            409,
            'Driver role channel does not match the authoritative driver scope.',
        );

        [$authoritativeChannel, $storeId] = $this->driverTenants->resolve($driver);
        abort_unless(
            $authoritativeChannel === $channel
                && (int) ($authorized['store_id'] ?? 0) === $storeId,
            409,
            'Driver role channel does not match the authoritative driver scope.',
        );

        return [$driver, $channel, $storeId];
    }
}
