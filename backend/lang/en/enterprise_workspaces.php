<?php

return [
    'nav_title' => 'Enterprise Workspaces',
    'eyebrow' => 'Role-aware operations',
    'title' => 'Enterprise Workspaces',
    'description' => 'Focused entry points assembled from your permissions and module entitlements. Every card opens the canonical FOODEX source of truth.',
    'open' => 'Open workspace',
    'empty' => 'No authorized actions are available in this workspace.',
    'workspaces' => [
        'executive' => 'Executive',
        'finance' => 'Finance',
        'sales' => 'Sales',
        'warehouse' => 'Warehouse',
    ],
    'cards' => [
        'reports' => [
            'title' => 'Business reports',
            'description' => 'Open the bounded reporting center for authorized business analysis and drill-down.',
        ],
        'action_center' => [
            'title' => 'Action Center',
            'description' => 'Review approvals and actionable exceptions without duplicating their source records.',
        ],
        'system_health' => [
            'title' => 'System health',
            'description' => 'Inspect enterprise module lifecycle, health and platform execution signals.',
        ],
        'finance_reports' => [
            'title' => 'Finance reports',
            'description' => 'Open authorized finance reporting and drill down to canonical records.',
        ],
        'orders' => [
            'title' => 'Order operations',
            'description' => 'Open the canonical order workspace for authorized sales and fulfillment work.',
        ],
        'sales_reports' => [
            'title' => 'Sales reports',
            'description' => 'Review bounded sales reporting without creating a parallel analytics surface.',
        ],
        'catalog' => [
            'title' => 'Catalog and inventory',
            'description' => 'Open the canonical catalog workspace used by warehouse and inventory operations.',
        ],
        'warehouse_reports' => [
            'title' => 'Inventory reports',
            'description' => 'Review authorized inventory reporting and operational drill-down.',
        ],
    ],
];
