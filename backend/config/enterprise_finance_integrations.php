<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Finance source-module posting integration
    |--------------------------------------------------------------------------
    |
    | Only source event contracts that already exist are wired here. Sales and
    | Inventory remain supported domains, but no contract name is fabricated
    | before those source modules publish a versioned outbox contract.
    |
    | Posting is fail-closed: an enabled Finance module requires an explicit
    | currency, distinct authorized maker/poster users, and posting account
    | codes. Missing or invalid configuration is left to the bounded outbox
    | retry/dead-letter path; source transactions are never rolled back.
    |
    */
    'supported_domains' => [
        'sales',
        'procurement',
        'inventory',
        'treasury',
    ],

    'currency_code' => env('FOODEX_FINANCE_INTEGRATION_CURRENCY'),
    'maker_user_id' => env('FOODEX_FINANCE_INTEGRATION_MAKER_USER_ID'),
    'poster_user_id' => env('FOODEX_FINANCE_INTEGRATION_POSTER_USER_ID'),

    'events' => [
        'ProcurementPurchaseInvoiceHandedOff' => [
            'domain' => 'procurement',
            'version' => 1,
            'mode' => 'post',
            'source_type' => 'purchase_invoice',
            'source_id_path' => 'purchase_invoice_id',
            'source_reference_path' => 'invoice_number',
            'amount_path' => 'total_amount',
            'currency_path' => 'currency_code',
            'entry_date_path' => 'invoice_date',
            'debit_account_code' => env('FOODEX_FINANCE_PROCUREMENT_INVOICE_DEBIT_ACCOUNT'),
            'credit_account_code' => env('FOODEX_FINANCE_PROCUREMENT_INVOICE_CREDIT_ACCOUNT'),
        ],
        'TreasuryReceiptPosted' => [
            'domain' => 'treasury',
            'version' => 1,
            'mode' => 'post',
            'source_type' => 'receipt',
            'source_id_path' => 'data.treasury_receipt_id',
            'source_reference_path' => 'data.receipt_number',
            'amount_path' => 'data.amount',
            'currency_path' => 'data.currency_code',
            'entry_date_path' => 'data.value_date',
            'debit_account_code' => env('FOODEX_FINANCE_TREASURY_RECEIPT_DEBIT_ACCOUNT'),
            'credit_account_code' => env('FOODEX_FINANCE_TREASURY_RECEIPT_CREDIT_ACCOUNT'),
        ],
        'TreasuryPaymentPosted' => [
            'domain' => 'treasury',
            'version' => 1,
            'mode' => 'post',
            'source_type' => 'payment',
            'source_id_path' => 'data.treasury_payment_id',
            'source_reference_path' => 'data.payment_number',
            'amount_path' => 'data.amount',
            'currency_path' => 'data.currency_code',
            'entry_date_path' => 'data.value_date',
            'debit_account_code' => env('FOODEX_FINANCE_TREASURY_PAYMENT_DEBIT_ACCOUNT'),
            'credit_account_code' => env('FOODEX_FINANCE_TREASURY_PAYMENT_CREDIT_ACCOUNT'),
        ],
        'TreasuryReconciliationClosed' => [
            'domain' => 'treasury',
            'version' => 1,
            'mode' => 'ignore',
        ],
        'TreasuryCashClosingClosed' => [
            'domain' => 'treasury',
            'version' => 1,
            'mode' => 'ignore',
        ],
    ],
];
