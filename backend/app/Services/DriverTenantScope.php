<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;

final class DriverTenantScope
{
    /**
     * Return the single effective Retail Driver profile only while its
     * authoritative store is active and classified as Retail (B2C).
     *
     * A B2C role/driver_type alone never grants a session for a disabled
     * store or a historical driver linked to a Wholesale store.
     */
    public function eligibleRetailDriver(User $user): ?Driver
    {
        return Driver::query()
            ->join('stores', 'stores.id', '=', 'drivers.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('drivers.user_id', $user->getKey())
            ->where('drivers.driver_type', 'b2c')
            ->where('drivers.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->select('drivers.*')
            ->first();
    }

    /** @return array{0:string,1:int} */
    public function resolve(Driver $driver): array
    {
        $channel = strtolower(trim((string) $driver->driver_type));
        abort_unless(
            in_array($channel, ['b2c', 'b2b'], true),
            409,
            'Driver channel scope is invalid.',
        );

        $storeId = (int) ($driver->store_id ?? 0);
        abort_unless($storeId > 0, 409, 'Driver store scope must be assigned explicitly.');

        $storeChannel = app(OperationalTenantScope::class)->storeChannel($storeId);
        abort_unless(
            $storeChannel === $channel,
            409,
            'Driver store channel does not match the driver channel.',
        );

        if ($channel === 'b2b') {
            abort_unless(
                $storeId === app(WholesalePrincipal::class)->storeId(),
                409,
                'Wholesale drivers must belong to the principal Wholesale store.',
            );
        }

        return [$channel, $storeId];
    }
}
