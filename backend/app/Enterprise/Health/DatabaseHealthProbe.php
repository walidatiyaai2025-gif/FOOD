<?php

namespace App\Enterprise\Health;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Required real database liveness probe for signed Procurement readiness.
 * Never report healthy solely because a module's probe configuration is empty.
 */
final class DatabaseHealthProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            // A public health/reporting response must not reveal SQL errors,
            // server addresses, credentials or connection details.
            return ModuleHealthCheck::error('database', 'Database connection check failed.');
        }

        return ModuleHealthCheck::healthy('database');
    }
}
