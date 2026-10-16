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
        'inventory_wms' => [
            'version' => 1,
            'category' => 'operations',
            'default_state' => 'DISABLED',
            'required_core' => [
                'identity',
                'tenant_context',
                'rbac',
                'audit',
                'module_registry',
                'catalog',
            ],
            'hard_dependencies' => [],
            'optional_integrations' => [
                'procurement',
                'accounting',
                'reporting_bi',
            ],
            'capabilities' => [
                'wms.read',
                'wms.manage',
            ],
            'permissions' => [
                'inventory.view',
                'inventory.manage',
            ],
            'health_checks' => [],
            'navigation' => [],
        ],
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
    ],
];
