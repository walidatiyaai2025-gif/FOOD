<?php

return [
    'name' => 'Page name',
    'code' => 'Page code',
    'permissions' => 'Registry permission codes',
    'permissions_unspecified' => 'No permission code declared; route authorization still applies',
    'scope' => 'Data scope',
    'channel' => 'Business channel',
    'module' => 'Module association',
    'module_unspecified' => 'No module key declared',
    'module_unknown' => 'Unrecognized module key',
    'scopes' => [
        'global' => 'Platform / global',
        'store' => 'Store-scoped',
        'both' => 'Platform or store, subject to authorization',
        'unspecified' => 'Not declared in registry',
    ],
    'channels' => [
        'b2b' => 'Wholesale (B2B)',
        'b2c' => 'Retail (B2C)',
        'unspecified' => 'No channel filter declared in registry',
    ],
    'modules' => [
        'procurement' => 'Procurement',
        'inventory_wms' => 'Warehouse and inventory',
        'finance' => 'Finance',
        'treasury' => 'Treasury',
        'enterprise_bi' => 'Enterprise analytics',
    ],
];
