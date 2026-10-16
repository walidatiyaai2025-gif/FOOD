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
        'treasury' => [
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
            'optional_integrations' => ['finance'],
            'capabilities' => [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
            ],
            'permissions' => [
                'finance.view',
                'finance.manage',
            ],
            'health_checks' => [],
            'navigation' => [],
        ],
    ],
];
