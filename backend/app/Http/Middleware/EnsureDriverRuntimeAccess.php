<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDriverRuntimeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->tokenCan('app:driver'), 403, 'This token is not authorized for the Driver App.');

        $driver = DB::table('drivers')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first(['id', 'driver_type', 'store_id']);

        abort_unless($driver !== null, 403, 'No active Driver identity is available for this account.');

        $roleAllowed = match (strtolower((string) $driver->driver_type)) {
            'b2b' => $user->hasRole('B2B_DRIVER'),
            'b2c' => $user->hasRole('B2C_DRIVER'),
            default => false,
        };
        abort_unless($roleAllowed, 403, 'This account is not authorized for the Driver App.');

        $request->attributes->set('driver_runtime_context', [
            'driver_id' => (int) $driver->id,
            'channel' => strtolower((string) $driver->driver_type),
            'store_id' => $driver->store_id === null ? null : (int) $driver->store_id,
        ]);

        return $next($request);
    }
}
