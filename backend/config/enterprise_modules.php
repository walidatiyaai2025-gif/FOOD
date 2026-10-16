<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise module manifests
    |--------------------------------------------------------------------------
    |
    | Definitions are intentionally data-only. Merely reading this file must not
    | instantiate optional module classes, query the database, or require provider
    | credentials. Business modules add their manifest here as they are certified.
    | Registry configuration must remain independent of optional module services.
    |
    */
    'modules' => [
        'sample_optional' => [
            'version' => 1,
            'category' => 'platform',
            'default_state' => 'DISABLED',
            'required_core' => [
                'identity',
                'tenant_context',
                'rbac',
                'audit',
                'module_registry',
            ],
            'hard_dependencies' => [],
            'optional_integrations' => [],
            'capabilities' => [],
            'permissions' => [],
            'health_checks' => [],
            'navigation' => [],
        ],
        'procurement' => [
            'version' => 1,
            'category' => 'operations',
            'default_state' => 'DISABLED',
            'required_core' => [
                'identity',
                'tenant_context',
                'rbac',
                'audit',
                'module_registry',
            ],
            'hard_dependencies' => [],
            'optional_integrations' => [
                'inventory',
                'accounting',
                'treasury',
            ],
            'capabilities' => [
                'procurement.suppliers',
                'procurement.purchase_requests',
                'procurement.purchase_orders',
                'procurement.goods_receipts',
            ],
            'permissions' => [
                'procurement.suppliers.view',
                'procurement.suppliers.manage',
                'procurement.purchase_requests.view',
                'procurement.purchase_requests.manage',
                'procurement.purchase_requests.approve',
                'procurement.purchase_orders.view',
                'procurement.purchase_orders.manage',
                'procurement.purchase_orders.issue',
                'procurement.goods_receipts.view',
                'procurement.goods_receipts.manage',
                'procurement.goods_receipts.post',
            ],
            'health_checks' => [
                'database',
            ],
            'navigation' => [],
        ],
    ],
];
