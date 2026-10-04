<?php

return [
    'active_user_window_minutes' => (int) env('FOODEX_ACTIVE_USER_WINDOW_MINUTES', 15),
    'low_stock_threshold' => (float) env('FOODEX_LOW_STOCK_THRESHOLD', 12),

    'dashboard_roles' => [
        'SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN', 'OPERATIONS', 'INVENTORY', 'FINANCE', 'CUSTOMER_SUPPORT',
    ],

    'dashboard_permissions' => [
        'security.view', 'users.view', 'roles.manage', 'users.roles.manage', 'users.status.manage', 'stores.view', 'b2b.accounts.view', 'b2b.pricing.view',
        'catalog.view', 'inventory.view', 'orders.view', 'customers.view', 'promotions.view', 'finance.view',
        'reports.view', 'support.view', 'notifications.view', 'settings.view', 'settings.manage',
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
            // Retail is always store-scoped. Global operational roles belong to wholesale only.
            'global_roles' => ['SUPER_ADMIN'],
            'store_roles' => [
                'B2C_STORE_ADMIN',
                'RETAIL_OPERATIONS',
                'RETAIL_INVENTORY',
                'RETAIL_FINANCE',
                'RETAIL_CUSTOMER_SUPPORT',
            ],
        ],
    ],
];
