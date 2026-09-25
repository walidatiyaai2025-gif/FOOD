<?php

return [
    'dashboard_roles' => [
        'SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN', 'OPERATIONS', 'INVENTORY', 'FINANCE', 'CUSTOMER_SUPPORT',
    ],

    'dashboard_permissions' => [
        'security.view', 'users.view', 'roles.manage', 'stores.view', 'b2b.accounts.view', 'b2b.pricing.view',
        'catalog.view', 'inventory.view', 'orders.view', 'customers.view', 'promotions.view', 'finance.view',
        'reports.view', 'support.view', 'notifications.view', 'settings.view',
    ],

    'channels' => [
        'b2b' => [
            'route' => 'admin.b2b.dashboard',
            'label' => 'admin.channels.b2b',
            'description' => 'admin.channels.b2b_description',
            'global_roles' => ['SUPER_ADMIN', 'B2B_ADMIN', 'OPERATIONS', 'INVENTORY', 'FINANCE', 'CUSTOMER_SUPPORT'],
            'store_roles' => [],
        ],
        'b2c' => [
            'route' => 'admin.b2c.dashboard',
            'label' => 'admin.channels.b2c',
            'description' => 'admin.channels.b2c_description',
            'global_roles' => ['SUPER_ADMIN', 'OPERATIONS', 'INVENTORY', 'FINANCE', 'CUSTOMER_SUPPORT'],
            'store_roles' => ['B2C_STORE_ADMIN'],
        ],
    ],
];
