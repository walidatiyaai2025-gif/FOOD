<?php

return [
    'language' => 'Language',
    'english' => 'English',
    'arabic' => 'العربية',
    'public_context' => 'FOODEX public information for Customer and Driver/Van store review.',
    'navigation' => [
        'support' => 'Support',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        'account_deletion' => 'Account deletion',
    ],
    'pages' => [
        'privacy' => [
            'title' => 'FOODEX Privacy Policy',
            'paragraphs' => [
                'FOODEX processes account, order, delivery, device and support data only as needed to operate the service, secure accounts, fulfill transactions and meet legal or accounting obligations.',
                'Store submission declarations must match the actual production build and configured integrations. Contact Support for privacy questions or deletion status.',
            ],
        ],
        'terms' => [
            'title' => 'FOODEX Terms of Service',
            'paragraphs' => [
                'Use of FOODEX is subject to the commercial, payment, delivery and account rules presented in the service. Operational Driver/Van accounts may be managed by the associated organization and are not treated as ordinary consumer accounts.',
            ],
        ],
        'support' => [
            'title' => 'FOODEX Support',
            'paragraphs' => [
                'For account, order, delivery, privacy or store-review support, use the public support action below. This page is intentionally public so external store reviewers can reach the support surface.',
            ],
        ],
        'account_deletion' => [
            'title' => 'FOODEX Account Deletion',
            'paragraphs' => [
                'Customer accounts can request deletion from the authenticated Customer App Account screen using Delete account. Identity verification is required. Active orders or outstanding financial obligations may delay anonymization. Required order, invoice, payment, tax and audit records are retained where legally or operationally required.',
                'Driver/Van operational accounts follow managed deactivation and retention rules and are not automatically destroyed as consumer accounts. Driver/Van users should contact the organization administrator responsible for their operational account.',
            ],
        ],
    ],
    'support' => [
        'contact_heading' => 'Public support contact',
        'whatsapp_label' => 'WhatsApp support: :number',
        'whatsapp_action' => 'Message FOODEX support on WhatsApp',
        'fallback_heading' => 'Support fallback',
        'fallback_label' => 'No active public WhatsApp support contact is configured.',
        'fallback_body' => 'Customer users can open Customer App → Diagnostics to export support-safe information, and use Customer App → Account for account or deletion actions. Driver/Van users should contact the organization administrator managing their operational account.',
        'reviewer_note' => 'This public page remains available without signing in for external store review.',
    ],
    'account_path' => 'Customer App → Account → Delete account',
];

