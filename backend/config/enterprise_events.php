<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Versioned cross-module contracts
    |--------------------------------------------------------------------------
    |
    | A producer/consumer must explicitly opt into a version here. Unknown versions
    | fail closed. Contract definitions are data-only and never resolve optional
    | module services or external providers during application boot.
    |
    */
    'contracts' => [
        'EnterpriseModuleStateChanged' => [1],
    ],
];
