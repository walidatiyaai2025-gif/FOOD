<?php

return [
    'workspaces' => [
        'executive' => [
            'access_any' => ['reports.view', 'platform.manage'],
            'cards' => [
                [
                    'key' => 'reports',
                    'label' => 'enterprise_workspaces.cards.reports.title',
                    'description' => 'enterprise_workspaces.cards.reports.description',
                    'route' => 'admin.reports.index',
                    'permission' => 'reports.view',
                ],
                [
                    'key' => 'action_center',
                    'label' => 'enterprise_workspaces.cards.action_center.title',
                    'description' => 'enterprise_workspaces.cards.action_center.description',
                    'route' => 'admin.action-center.index',
                    'permissions_any' => ['orders.approve', 'finance.manage', 'inventory.adjust', 'platform.manage'],
                ],
                [
                    'key' => 'system_health',
                    'label' => 'enterprise_workspaces.cards.system_health.title',
                    'description' => 'enterprise_workspaces.cards.system_health.description',
                    'route' => 'admin.enterprise-system-health.index',
                    'permission' => 'platform.manage',
                ],
            ],
        ],
        'finance' => [
            'access_any' => ['finance.view', 'reports.view'],
            'cards' => [
                [
                    'key' => 'finance_reports',
                    'label' => 'enterprise_workspaces.cards.finance_reports.title',
                    'description' => 'enterprise_workspaces.cards.finance_reports.description',
                    'route' => 'admin.reports.index',
                    'params' => ['report' => 'finance'],
                    'permission' => 'reports.view',
                ],
                [
                    'key' => 'finance_actions',
                    'label' => 'enterprise_workspaces.cards.action_center.title',
                    'description' => 'enterprise_workspaces.cards.action_center.description',
                    'route' => 'admin.action-center.index',
                    'permission' => 'finance.manage',
                ],
            ],
        ],
        'sales' => [
            'access_any' => ['orders.view', 'customers.view'],
            'cards' => [
                [
                    'key' => 'orders',
                    'label' => 'enterprise_workspaces.cards.orders.title',
                    'description' => 'enterprise_workspaces.cards.orders.description',
                    'route' => 'admin.operations.orders.index',
                    'permission' => 'orders.view',
                ],
                [
                    'key' => 'sales_reports',
                    'label' => 'enterprise_workspaces.cards.sales_reports.title',
                    'description' => 'enterprise_workspaces.cards.sales_reports.description',
                    'route' => 'admin.reports.index',
                    'params' => ['report' => 'orders'],
                    'permission' => 'reports.view',
                ],
            ],
        ],
        'warehouse' => [
            'access_any' => ['inventory.view', 'catalog.view'],
            'cards' => [
                [
                    'key' => 'catalog',
                    'label' => 'enterprise_workspaces.cards.catalog.title',
                    'description' => 'enterprise_workspaces.cards.catalog.description',
                    'route' => 'admin.catalog.index',
                    'permission' => 'catalog.view',
                ],
                [
                    'key' => 'warehouse_reports',
                    'label' => 'enterprise_workspaces.cards.warehouse_reports.title',
                    'description' => 'enterprise_workspaces.cards.warehouse_reports.description',
                    'route' => 'admin.reports.index',
                    'params' => ['report' => 'inventory'],
                    'permission' => 'reports.view',
                ],
                [
                    'key' => 'warehouse_actions',
                    'label' => 'enterprise_workspaces.cards.action_center.title',
                    'description' => 'enterprise_workspaces.cards.action_center.description',
                    'route' => 'admin.action-center.index',
                    'permission' => 'inventory.adjust',
                ],
            ],
        ],
    ],
];
