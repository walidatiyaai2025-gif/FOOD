<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise reporting registry
    |--------------------------------------------------------------------------
    |
    | Registry entries are metadata only. Concrete read models must remain
    | bounded and must never execute per-row operational queries.
    |
    */
    'reports' => [
        'orders.summary' => [
            'module' => 'enterprise_bi',
            'view_permission' => 'reports.view',
            'export_permission' => 'reports.export',
            'read_capability' => 'reports.read',
            'export_capability' => 'reports.export',
            'dimensions' => ['date', 'status', 'channel', 'currency'],
            'measures' => ['orders', 'gross_total', 'average_order_value'],
            'allowed_filters' => ['status', 'channel', 'currency'],
            'read_model' => 'orders_summary_daily',
            'drill_down_route' => 'admin.operations.orders.index',
            'max_range_days' => 31,
            'max_page_size' => 100,
            'export_chunk_size' => 500,
        ],
    ],
];
