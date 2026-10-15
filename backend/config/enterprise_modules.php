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
        'enterprise_bi' => [
            'version' => 1,
            'category' => 'analytics',
            'default_state' => 'DISABLED',
            'required_core' => [
                'identity',
                'tenant_context',
                'rbac',
                'audit',
                'module_registry',
                'entitlements',
            ],
            'hard_dependencies' => [],
            'optional_integrations' => [
                'sales',
                'inventory',
                'procurement',
                'finance',
                'treasury',
            ],
            'capabilities' => [
                'reports.read',
                'reports.export',
            ],
            'permissions' => [
                'reports.view',
                'reports.export',
            ],
            'health_checks' => [],
            'navigation' => [],
        ],
    ],
];
