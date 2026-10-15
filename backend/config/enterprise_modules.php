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
        'smart_ordering' => [
            'version' => 1,
            'category' => 'restaurant',
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
            'capabilities' => [
                'public_menu',
                'ordering',
                'dine_in',
                'pickup',
                'delivery',
                'whatsapp',
                'chatbot',
                'offers',
                'campaign_qr',
                'analytics',
                'loyalty',
                'reviews',
                'advanced_upsell',
                'multi_branch',
                'custom_domain',
                'foodex_supply_intelligence',
            ],
            'permissions' => [],
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
