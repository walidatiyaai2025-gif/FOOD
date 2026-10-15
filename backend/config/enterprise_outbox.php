<?php

return [
    'max_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Versioned event consumers
    |--------------------------------------------------------------------------
    |
    | Consumer classes are resolved only by the bounded dispatcher after a row
    | has been claimed. No consumer/provider is resolved in the producer DB
    | transaction or during application boot.
    |
    */
    'consumers' => [],
];
