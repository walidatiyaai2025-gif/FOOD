<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DriverTenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDriverRuntimeAccess
{
    public function __construct(private readonly DriverTenantScope $driverTenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->tokenCan('app:driver'), 403, 'This token is not authorized for the Driver App.');

        $driver = $this->driverTenants->eligibleRetailDriver($user);

        abort_unless(
            $driver !== null
                && $user->hasRole('B2C_DRIVER')
                && $user->hasPermission('deliveries.b2c.execute'),
            403,
            'Driver App execution is available only for authorized Retail (B2C) drivers.',
        );

        $request->attributes->set('driver_runtime_context', [
            'driver_id' => (int) $driver->getKey(),
            'channel' => strtolower((string) $driver->driver_type),
            'store_id' => $driver->store_id === null ? null : (int) $driver->store_id,
        ]);

        return $next($request);
    }
}
