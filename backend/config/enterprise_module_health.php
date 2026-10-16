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
        'inventory_wms' => [],
        'sample_optional' => [],
    ],
];
