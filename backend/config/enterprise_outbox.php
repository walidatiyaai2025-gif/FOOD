<?php

return [
    'max_attempts' => 5,

    /*
    |--------------------------------------------------------------------------
    | Versioned event consumers
    |--------------------------------------------------------------------------
    |
    | Consumer classes are resolved only by the bounded dispatcher after a row
    | has been claimed. No consumer/provider is resolved in the producer DB
    | transaction or during application boot.
    |
    */
    'consumers' => [
        'ProcurementPurchaseInvoiceHandedOff' => [
            \App\Enterprise\Finance\FinanceSourcePostingConsumer::class,
        ],
        'TreasuryReceiptPosted' => [
            \App\Enterprise\Finance\FinanceSourcePostingConsumer::class,
        ],
        'TreasuryPaymentPosted' => [
            \App\Enterprise\Finance\FinanceSourcePostingConsumer::class,
        ],
        'TreasuryReconciliationClosed' => [
            \App\Enterprise\Finance\FinanceSourcePostingConsumer::class,
        ],
        'TreasuryCashClosingClosed' => [
            \App\Enterprise\Finance\FinanceSourcePostingConsumer::class,
        ],
    ],
];
