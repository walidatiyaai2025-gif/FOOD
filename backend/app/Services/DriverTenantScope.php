<?php

namespace App\Services;

use App\Models\Driver;

final class DriverTenantScope
{
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
