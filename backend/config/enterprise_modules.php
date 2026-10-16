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
        'finance' => [
            'version' => 1,
            'category' => 'finance',
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
                'sales',
                'procurement',
                'inventory',
                'treasury',
            ],
            'capabilities' => [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.gl.manage',
                'finance.gl.post',
            ],
            'permissions' => [
                'finance.view',
                'finance.manage',
                'finance.post',
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
