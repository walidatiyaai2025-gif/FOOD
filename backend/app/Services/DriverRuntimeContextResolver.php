<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\Request;

final class DriverRuntimeContextResolver
{
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

        $storeId = (int) ($driver->store_id ?? 0);
        if ($storeId < 1 && $channel === 'b2b') {
            $storeId = app(WholesalePrincipal::class)->storeId();
            $driver->forceFill(['store_id' => $storeId])->save();
        }

        abort_unless($storeId > 0, 409, 'Driver store context is required before location tracking.');

        return [$driver->fresh(), $channel, $storeId];
    }
}
