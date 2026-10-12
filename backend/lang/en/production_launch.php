<?php

return [
    'title' => 'Production Launch Gate',
    'description' => 'Verify production evidence from one fail-closed surface before the owner approves launch.',
    'back' => 'Settings Center',
    'preflight' => 'Production readiness checks',
    'preflight_description' => 'Every mandatory check must pass from authoritative evidence. Missing or stale evidence blocks launch.',
    'ready' => 'All mandatory production checks passed. Owner confirmation is now available.',
    'blocked' => 'Launch remains blocked. Resolve every failed readiness check before approval.',
    'approved' => 'Production launch approval was recorded with the current release lineage.',
    'refresh' => 'Refresh readiness checks',
    'status' => [
        'passed' => 'Passed',
        'blocked' => 'Blocked',
    ],
    'release' => [
        'title' => 'Release lineage',
        'description' => 'The approval audit records this version, source lineage and mobile build identity.',
        'version' => 'FOODEX version',
        'source' => 'Source commit',
        'run' => 'Release run',
        'mobile_builds' => 'Customer / Driver / Van builds',
        'unknown' => 'Not available',
    ],
    'confirm' => [
        'title' => 'Owner-controlled launch approval',
        'description' => 'Approval does not bypass deployment controls. It records that every mandatory gate passed for this release lineage.',
        'label' => 'Type the exact owner confirmation',
        'placeholder' => 'Type the displayed phrase exactly',
        'password' => 'Your current password',
        'password_placeholder' => 'Enter your current password',
        'submit' => 'Approve production launch',
        'submitting' => 'Recording launch approval…',
    ],
    'checks' => [
        'schema_migrations' => [
            'title' => 'Schema and migrations',
            'description' => 'Required production database structures are present.',
        ],
        'environment_configuration' => [
            'title' => 'Production environment',
            'description' => 'Production mode, application key, debug policy and database configuration meet launch safeguards.',
        ],
        'official_seed' => [
            'title' => 'Official FOODEX seed',
            'description' => 'The authoritative launch catalog seeder is installed and available.',
        ],
        'product_image_quality' => [
            'title' => 'Product image quality',
            'description' => 'Required product images passed completeness and quality evidence.',
        ],
        'clean_production_data' => [
            'title' => 'Clean production data',
            'description' => 'Known demo customers, transactions and launch-test markers are absent.',
        ],
        'api_customer_contract' => [
            'title' => 'API and Customer contract',
            'description' => 'Customer-facing API contracts are validated against the release source.',
        ],
        'customer_ar_en_registry' => [
            'title' => 'Arabic and English coverage',
            'description' => 'Customer Screen Registry and AR/EN localization evidence are complete.',
        ],
        'customer_e2e' => [
            'title' => 'Customer end-to-end journey',
            'description' => 'Browse, cart, checkout, order, tracking and account evidence passed.',
        ],
        'provider_readiness' => [
            'title' => 'Production providers',
            'description' => 'Payment, delivery and notification provider readiness is attested without exposing secrets.',
        ],
        'mobile_artifacts' => [
            'title' => 'Mobile release artifacts',
            'description' => 'Customer, Driver and Van artifacts match the intended production release lineage.',
        ],
        'backup_rollback' => [
            'title' => 'Backup and rollback',
            'description' => 'Production backup and rollback evidence is present for this launch.',
        ],
        'exact_head_required_ci' => [
            'title' => 'Exact-head Required CI',
            'description' => 'Required CI passed for the exact promoted source revision.',
        ],
    ],
    'errors' => [
        'blocked' => 'Production launch is blocked until every required preflight check passes.',
        'confirmation' => 'The owner launch confirmation does not match.',
        'password_required' => 'Enter your current password.',
        'password' => 'The current password is incorrect.',
    ],
];
