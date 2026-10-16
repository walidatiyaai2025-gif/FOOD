<?php

return [
    'title' => 'Customer Service Workspace',
    'subtitle' => 'Open one customer context and move through orders, invoices and payments, delivery, conversations and activity using the canonical FOODEX records.',
    'search_title' => 'Start with the customer',
    'search_help' => 'Search by business-readable identity. Customer 360 remains the authoritative customer, invoice, payment-reference and activity record.',
    'search_label' => 'Customer search',
    'search_placeholder' => 'Name, email or phone',
    'open_customer' => 'Open Customer 360',
    'journey_label' => 'Customer service journey',
    'no_access' => 'No customer-service capability is available for your current permissions.',
    'truth_title' => 'Canonical records only',
    'truth_help' => 'This workspace composes existing FOODEX authorities. It does not create a second customer, order, payment, delivery or conversation ledger.',
    'cards' => [
        'customer' => [
            'title' => 'Customer 360',
            'description' => 'Profile, addresses, account context, balances and the customer-owned operational timeline.',
            'action' => 'Open customers',
        ],
        'orders' => [
            'title' => 'Orders & fulfillment',
            'description' => 'Open the canonical order operations surface for exact order state, fulfillment and authorized actions.',
            'action' => 'Open orders',
        ],
        'finance' => [
            'title' => 'Invoices & payments',
            'description' => 'Use Customer 360 to inspect invoices, balances, payment references and finance actions allowed by your role.',
            'action' => 'Open customer finance',
        ],
        'delivery' => [
            'title' => 'Delivery & field operations',
            'description' => 'Review authorized driver, van, assignment, visit and proof context without duplicating delivery state.',
            'action' => 'Open field operations',
        ],
        'conversations' => [
            'title' => 'Service conversations',
            'description' => 'Continue authorized WhatsApp service conversations in the shared inbox and keep communication tied to canonical customer context.',
            'action' => 'Open inbox',
        ],
        'activity' => [
            'title' => 'Activity & audit',
            'description' => 'Return to Customer 360 for the authoritative customer timeline and source-record drill-down.',
            'action' => 'Open activity',
        ],
    ],
];
