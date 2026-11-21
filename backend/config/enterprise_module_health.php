<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise module health probes
    |--------------------------------------------------------------------------
    |
    | Values are class-string lists resolved lazily only after the Module Registry
    | reports the module ENABLED. Keep this file data-only/config-cache safe.
    |
    */
    'probes' => [
        // Required by procurement v2 manifest health_checks=['database'].
        // A missing probe is NOT equivalent to a healthy certified module.
        'procurement' => [\App\Enterprise\Health\DatabaseHealthProbe::class],
        'inventory_wms' => [],
        'sample_optional' => [],
    ],
];
