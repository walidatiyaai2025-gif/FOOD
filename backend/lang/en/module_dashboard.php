<?php

return [
    'filters' => [
        'scope' => 'Scope',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Apply',
        'reset' => 'Reset',
        'all_authorized' => 'All authorized',
    ],
    'actions' => [
        'export' => 'Export',
        'retry' => 'Retry',
    ],
    'comparison' => [
        'previous_period' => 'Previous period',
    ],
    'updated_at' => 'Updated :time',
    'states' => [
        'loading' => [
            'title' => 'Loading dashboard',
            'message' => 'Authoritative module data is being loaded.',
        ],
        'empty' => [
            'title' => 'No data',
            'message' => 'No authorized records match the current scope and period.',
        ],
        'error' => [
            'title' => 'Dashboard unavailable',
            'message' => 'Authoritative module data could not be loaded.',
        ],
        'stale' => [
            'title' => 'Data may be stale',
            'message' => 'The last confirmed module snapshot is shown while fresh data is unavailable.',
        ],
        'permission' => [
            'title' => 'Not permitted',
            'message' => 'You do not have access to this module data.',
        ],
        'disabled' => [
            'title' => 'Module unavailable',
            'message' => 'This module is disabled or not available in the current scope.',
        ],
        'current' => [
            'title' => 'Current data',
            'message' => 'The dashboard reflects the latest confirmed module data.',
        ],
    ],
];
