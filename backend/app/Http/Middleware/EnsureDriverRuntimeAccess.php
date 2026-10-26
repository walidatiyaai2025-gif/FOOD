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
            ->whereIn('driver_type', ['b2b', 'b2c'])
            ->where('is_active', true)
            ->first(['id', 'driver_type', 'store_id']);

        $channel = strtolower((string) ($driver->driver_type ?? ''));
        $role = $channel === 'b2b' ? 'B2B_DRIVER' : 'B2C_DRIVER';
        $permission = $channel === 'b2b' ? 'deliveries.b2b.execute' : 'deliveries.b2c.execute';

        abort_unless(
            $driver !== null
                && in_array($channel, ['b2b', 'b2c'], true)
                && $user->hasRole($role)
                && $user->hasPermission($permission),
            403,
            'Driver App execution requires an authorized B2B or B2C driver profile.',
        );

        $request->attributes->set('driver_runtime_context', [
            'driver_id' => (int) $driver->id,
            'channel' => strtolower((string) $driver->driver_type),
            'store_id' => $driver->store_id === null ? null : (int) $driver->store_id,
        ]);

        return $next($request);
    }
}
