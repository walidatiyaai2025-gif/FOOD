<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enterprise reporting source contracts
    |--------------------------------------------------------------------------
    |
    | These names point only at canonical source-module authorities. They are
    | configurable so BI tests can exercise the read models without copying
    | Finance/WMS migrations into the BI integration branch.
    |
    */
    'sources' => [
        'ar_documents' => 'enterprise_ar_documents',
        'ar_allocations' => 'enterprise_ar_allocations',
        'ap_documents' => 'enterprise_ap_documents',
        'ap_allocations' => 'enterprise_ap_allocations',
        'inventories' => 'inventories',
        'warehouses' => 'warehouses',
        'valuation_policies' => 'enterprise_inventory_valuation_policies',
        'stock_movements' => 'stock_movements',
        'commercial_outbox' => 'enterprise_outbox_messages',
        'orders' => 'orders',
        'profitability_facts' => 'enterprise_bi_profitability_facts',
    ],

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
        'commercial.profitability' => [
            'module' => 'enterprise_bi',
            'view_permission' => 'reports.view',
            'export_permission' => 'reports.export',
            'read_capability' => 'reports.read',
            'export_capability' => 'reports.export',
            'dimensions' => ['date', 'product_id', 'customer_id', 'store_id', 'channel', 'currency', 'status'],
            'measures' => ['orders', 'gross_revenue', 'discount_total', 'net_revenue', 'cost', 'margin', 'unvalued_lines'],
            'allowed_filters' => ['product_id', 'customer_id', 'channel', 'currency', 'status'],
            'read_model' => 'commercial_profitability',
            'drill_down_route' => null,
            'max_range_days' => 31,
            'max_page_size' => 100,
            'export_chunk_size' => 500,
        ],
        'finance.ar_exposure' => [
            'module' => 'enterprise_bi',
            'view_permission' => 'reports.view',
            'export_permission' => 'reports.export',
            'read_capability' => 'reports.read',
            'export_capability' => 'reports.export',
            'dimensions' => ['aging_bucket', 'customer_id', 'store_id', 'currency'],
            'measures' => ['documents', 'outstanding_amount'],
            'allowed_filters' => ['customer_id', 'store_id', 'currency'],
            'read_model' => 'finance_ar_exposure',
            'drill_down_route' => null,
            'max_range_days' => 1,
            'max_page_size' => 100,
            'export_chunk_size' => 500,
        ],
        'finance.ap_exposure' => [
            'module' => 'enterprise_bi',
            'view_permission' => 'reports.view',
            'export_permission' => 'reports.export',
            'read_capability' => 'reports.read',
            'export_capability' => 'reports.export',
            'dimensions' => ['aging_bucket', 'supplier_reference', 'store_id', 'currency'],
            'measures' => ['documents', 'outstanding_amount'],
            'allowed_filters' => ['supplier_reference', 'store_id', 'currency'],
            'read_model' => 'finance_ap_exposure',
            'drill_down_route' => null,
            'max_range_days' => 1,
            'max_page_size' => 100,
            'export_chunk_size' => 500,
        ],
        'wms.stock_exposure' => [
            'module' => 'enterprise_bi',
            'view_permission' => 'reports.view',
            'export_permission' => 'reports.export',
            'read_capability' => 'reports.read',
            'export_capability' => 'reports.export',
            'dimensions' => ['warehouse_id', 'product_id', 'aging_bucket', 'stock_status'],
            'measures' => ['inventory_rows', 'on_hand', 'reserved', 'available', 'stock_value', 'unvalued_items'],
            'allowed_filters' => ['warehouse_id', 'product_id', 'stock_status'],
            'read_model' => 'wms_stock_exposure',
            'drill_down_route' => null,
            'max_range_days' => 1,
            'max_page_size' => 100,
            'export_chunk_size' => 500,
        ],
    ],
];
