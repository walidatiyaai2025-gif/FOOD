<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise reporting registry
    |--------------------------------------------------------------------------
    |
    | This foundation is intentionally data-only. Individual BI/report lanes add
    | definitions here only after their canonical read model exists. Reading the
    | registry must never execute operational queries or instantiate providers.
    |
    */
    'reports' => [],
];
