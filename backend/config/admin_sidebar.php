<?php

$customerServiceRoles = [
    'CUSTOMER_SERVICE_MANAGER',
    'CUSTOMER_SERVICE_SUPERVISOR',
    'CUSTOMER_SERVICE_AGENT',
];

return [
    'protected_items' => ['sidebar_role_access', 'sidebar_layout'],
    'protected_groups' => ['administration'],

    'groups' => [
        'overview' => ['label' => 'admin.nav_groups.overview', 'icon' => '⌂', 'order' => 10],
        'erp' => ['label' => 'admin.nav_groups.erp', 'icon' => '▤', 'order' => 20],
        'stores' => ['label' => 'admin.nav_groups.stores', 'icon' => '⌂', 'order' => 30],
        'catalog' => ['label' => 'admin.nav_groups.catalog', 'icon' => '▦', 'order' => 40],
        'accounts' => ['label' => 'admin.nav_groups.accounts', 'icon' => '◎', 'order' => 50],
        'operations' => ['label' => 'admin.nav_groups.operations', 'icon' => '↻', 'order' => 60],
        'field_operations' => ['label' => 'admin.nav_groups.field_operations', 'icon' => '⌖', 'order' => 70],
        'marketing' => ['label' => 'admin.nav_groups.marketing', 'icon' => '✦', 'order' => 80],
        'advertising' => ['label' => 'admin.nav_groups.advertising', 'icon' => '◉', 'order' => 90],
        'finance' => ['label' => 'admin.nav_groups.finance', 'icon' => '¤', 'order' => 100],
        'analytics' => ['label' => 'admin.nav_groups.analytics', 'icon' => '▥', 'order' => 110],
        'applications' => ['label' => 'admin.nav_groups.applications', 'icon' => '▣', 'order' => 120],
        'administration' => ['label' => 'admin.nav_groups.administration', 'icon' => '⚙', 'order' => 130],
    ],

    'items' => [
        'enterprise_action_center' => ['group' => 'overview', 'label' => 'enterprise_action_center.nav_title', 'order' => 10, 'scope' => 'global', 'permissions' => [], 'exclude_roles' => $customerServiceRoles],
        'enterprise_workspaces' => ['group' => 'overview', 'label' => 'enterprise_workspaces.nav_title', 'order' => 20, 'scope' => 'global', 'permissions' => [], 'exclude_roles' => $customerServiceRoles],
        'b2c_dashboard' => ['group' => 'overview', 'label' => 'admin.premium_nav.dashboard', 'order' => 30, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => []],
        'b2b_dashboard' => ['group' => 'overview', 'label' => 'admin.channels.b2b', 'order' => 40, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => []],
        'erp_customer_service' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.customer_service.title', 'order' => 50, 'scope' => 'both', 'permissions' => ['customers.view', 'b2b.accounts.view'], 'mode' => 'any'],

        'erp_center' => ['group' => 'erp', 'label' => 'admin.erp_center.nav_title', 'order' => 10, 'scope' => 'global', 'permissions' => [], 'exclude_roles' => $customerServiceRoles],
        'erp_procurement' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.procurement.title', 'order' => 20, 'scope' => 'global', 'permissions' => ['procurement.suppliers.view']],
        'erp_wms' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.wms.title', 'order' => 30, 'scope' => 'global', 'permissions' => ['inventory.view']],
        'erp_finance' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.finance.title', 'order' => 40, 'scope' => 'global', 'permissions' => ['finance.view']],
        'erp_treasury' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.treasury.title', 'order' => 50, 'scope' => 'global', 'permissions' => ['finance.view']],
        'erp_bi' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.bi.title', 'order' => 70, 'scope' => 'global', 'permissions' => ['reports.view']],
        'erp_my_work' => ['group' => 'erp', 'label' => 'admin.erp_center.modules.my_work.title', 'order' => 80, 'scope' => 'global', 'permissions' => [], 'exclude_roles' => $customerServiceRoles],

        'retail_store_provisioning' => ['group' => 'stores', 'label' => 'admin.retail_store_provisioning', 'order' => 10, 'scope' => 'global', 'permissions' => ['platform.manage']],
        'smart_ordering' => ['group' => 'stores', 'label' => 'smart_ordering.nav_title', 'order' => 20, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['smart_ordering.view', 'orders.view', 'smart_ordering.qr.view', 'smart_ordering.analytics.view'], 'mode' => 'any'],
        'b2c_storefront' => ['group' => 'stores', 'label' => 'admin.b2c_workspace.modules.storefront', 'order' => 30, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['stores.view']],
        'b2b_storefront' => ['group' => 'stores', 'label' => 'admin.b2b_workspace.modules.storefront', 'order' => 40, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['settings.view']],

        'catalog_management' => ['group' => 'catalog', 'label' => 'admin.catalog_management', 'order' => 10, 'scope' => 'global', 'permissions' => ['catalog.view']],
        'commercial_sales_control' => ['group' => 'catalog', 'label' => 'admin.commercial_sales_control', 'order' => 20, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['catalog.view']],
        'lookup_management' => ['group' => 'catalog', 'label' => 'admin.lookup_management', 'order' => 30, 'scope' => 'both', 'permissions' => ['lookups.view']],
        'b2c_products' => ['group' => 'catalog', 'label' => 'admin.b2c_workspace.modules.products', 'order' => 40, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['catalog.view']],
        'b2c_inventory' => ['group' => 'catalog', 'label' => 'admin.b2c_workspace.modules.inventory', 'order' => 50, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['inventory.view']],
        'b2b_products' => ['group' => 'catalog', 'label' => 'admin.b2b_sidebar.product_management', 'order' => 60, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['catalog.view']],
        'b2b_inventory' => ['group' => 'catalog', 'label' => 'admin.b2b_sidebar.inventory_management', 'order' => 70, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['inventory.view']],

        'customer_360' => ['group' => 'accounts', 'label' => 'admin.customer_360', 'order' => 10, 'scope' => 'both', 'permissions' => ['customers.view', 'b2b.accounts.view'], 'mode' => 'any'],
        'b2c_customers' => ['group' => 'accounts', 'label' => 'admin.b2c_workspace.modules.customers', 'order' => 20, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['customers.view']],

        'whatsapp_inbox' => ['group' => 'operations', 'label' => 'whatsapp.inbox_title', 'order' => 10, 'scope' => 'both', 'permissions' => ['whatsapp.inbox.view']],
        'order_operations' => ['group' => 'operations', 'label' => 'admin.order_management', 'order' => 20, 'scope' => 'both', 'permissions' => ['orders.view']],
        'driver_live_tracking' => ['group' => 'operations', 'label' => 'admin.driver_live_tracking.title', 'order' => 30, 'scope' => 'both', 'permissions' => ['drivers.tracking.view'], 'exclude_roles' => $customerServiceRoles],
        'b2c_incoming_orders' => ['group' => 'operations', 'label' => 'admin.b2c_workspace.modules.incoming_orders', 'order' => 40, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['orders.view']],
        'b2c_drivers' => ['group' => 'operations', 'label' => 'admin.b2c_workspace.modules.drivers', 'order' => 50, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['drivers.b2c.view']],
        'b2b_drivers' => ['group' => 'operations', 'label' => 'admin.b2b_workspace.modules.drivers', 'order' => 60, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['drivers.b2b.view']],

        'field_ops_overview' => ['group' => 'field_operations', 'label' => 'admin.field_operations.overview', 'order' => 10, 'scope' => 'global', 'permissions' => ['field_ops.manage', 'drivers.b2b.view', 'drivers.tracking.view', 'customers.view', 'territories.manage', 'finance.view'], 'mode' => 'any', 'exclude_roles' => $customerServiceRoles],
        'field_ops_fleet' => ['group' => 'field_operations', 'label' => 'admin.field_operations.fleet', 'order' => 20, 'scope' => 'global', 'permissions' => ['drivers.tracking.view'], 'exclude_roles' => $customerServiceRoles],
        'field_ops_vans' => ['group' => 'field_operations', 'label' => 'admin.field_operations.vans', 'order' => 30, 'scope' => 'global', 'permissions' => ['drivers.b2b.view'], 'exclude_roles' => $customerServiceRoles],
        'field_ops_assignments' => ['group' => 'field_operations', 'label' => 'admin.field_operations.assignments', 'order' => 40, 'scope' => 'global', 'permissions' => ['drivers.b2b.view', 'field_ops.manage'], 'mode' => 'any', 'exclude_roles' => $customerServiceRoles],
        'field_ops_customers' => ['group' => 'field_operations', 'label' => 'admin.field_operations.customers', 'order' => 50, 'scope' => 'global', 'permissions' => ['customers.view', 'drivers.b2b.view'], 'mode' => 'all', 'exclude_roles' => $customerServiceRoles],
        'field_ops_visits' => ['group' => 'field_operations', 'label' => 'admin.field_operations.visits', 'order' => 60, 'scope' => 'global', 'permissions' => ['drivers.b2b.view', 'customers.view'], 'mode' => 'all', 'exclude_roles' => $customerServiceRoles],
        'field_ops_territories' => ['group' => 'field_operations', 'label' => 'admin.field_operations.territories', 'order' => 70, 'scope' => 'global', 'permissions' => ['territories.manage', 'field_ops.manage'], 'mode' => 'any', 'exclude_roles' => $customerServiceRoles],
        'field_ops_routing' => ['group' => 'field_operations', 'label' => 'admin.field_operations.routing', 'order' => 80, 'scope' => 'global', 'permissions' => ['territories.manage', 'field_ops.manage'], 'mode' => 'any', 'exclude_roles' => $customerServiceRoles],
        'field_ops_finance' => ['group' => 'field_operations', 'label' => 'admin.field_operations.finance', 'order' => 90, 'scope' => 'global', 'permissions' => ['finance.view'], 'exclude_roles' => $customerServiceRoles],

        'whatsapp_campaigns' => ['group' => 'marketing', 'label' => 'whatsapp.campaigns.nav_title', 'order' => 10, 'scope' => 'global', 'permissions' => ['whatsapp.campaigns.view']],
        'whatsapp_chatbot' => ['group' => 'marketing', 'label' => 'chatbot.nav_title', 'order' => 20, 'scope' => 'global', 'permissions' => ['chatbot.view'], 'exclude_roles' => $customerServiceRoles],
        'commercial_flash_offers' => ['group' => 'marketing', 'label' => 'admin.flash_offers', 'order' => 30, 'scope' => 'global', 'permissions' => ['promotions.view']],
        'b2c_promotions' => ['group' => 'marketing', 'label' => 'admin.b2c_workspace.modules.promotions', 'order' => 40, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['promotions.view']],
        'b2c_content' => ['group' => 'marketing', 'label' => 'admin.b2c_workspace.modules.content', 'order' => 50, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['promotions.view']],

        'notification_campaigns' => ['group' => 'advertising', 'label' => 'notifications.sidebar_campaigns', 'order' => 10, 'scope' => 'both', 'permissions' => ['notifications.view']],
        'live_ads' => ['group' => 'advertising', 'label' => 'live_ads.title', 'order' => 20, 'scope' => 'both', 'permissions' => ['live_ads.view']],
        'coupons' => ['group' => 'advertising', 'label' => 'coupons.title', 'order' => 30, 'scope' => 'both', 'permissions' => ['coupons.view']],

        'treasury_master' => ['group' => 'finance', 'label' => 'treasury.nav_title', 'order' => 10, 'scope' => 'global', 'permissions' => ['finance.view']],

        'whatsapp_supervisor' => ['group' => 'analytics', 'label' => 'whatsapp_supervisor.nav_title', 'order' => 10, 'scope' => 'global', 'permissions' => ['whatsapp.customer_service.supervise']],
        'reports_center' => ['group' => 'analytics', 'label' => 'reports.title', 'order' => 20, 'scope' => 'global', 'permissions' => ['reports.view']],
        'b2c_reports' => ['group' => 'analytics', 'label' => 'admin.b2c_workspace.modules.reports', 'order' => 30, 'scope' => 'store', 'channel' => 'b2c', 'permissions' => ['reports.view']],
        'b2b_finance' => ['group' => 'analytics', 'label' => 'admin.b2b_workspace.modules.finance', 'order' => 40, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['finance.view']],
        'b2b_reports' => ['group' => 'analytics', 'label' => 'admin.b2b_workspace.modules.reports', 'order' => 50, 'scope' => 'global', 'channel' => 'b2b', 'permissions' => ['reports.view']],

        'app_preview' => ['group' => 'applications', 'label' => 'admin.app_preview', 'order' => 10, 'scope' => 'both', 'permissions' => ['app_preview.view']],
        'mobile_customer_download' => ['group' => 'applications', 'label' => 'admin.mobile_apps.customer_download', 'order' => 20, 'scope' => 'global', 'permissions' => ['platform.manage']],
        'mobile_driver_download' => ['group' => 'applications', 'label' => 'admin.mobile_apps.driver_download', 'order' => 30, 'scope' => 'global', 'permissions' => ['platform.manage']],
        'mobile_van_download' => ['group' => 'applications', 'label' => 'admin.mobile_apps.van_download', 'order' => 40, 'scope' => 'global', 'permissions' => ['platform.manage']],

        'administration_hub' => ['group' => 'administration', 'label' => 'admin.administration_hub.title', 'order' => 10, 'scope' => 'global', 'permissions' => [], 'exclude_roles' => $customerServiceRoles],
        'sidebar_role_access' => ['group' => 'administration', 'label' => 'sidebar_admin.role_access_nav', 'order' => 20, 'scope' => 'global', 'permissions' => ['roles.manage']],
        'sidebar_layout' => ['group' => 'administration', 'label' => 'sidebar_admin.layout_nav', 'order' => 30, 'scope' => 'global', 'permissions' => ['roles.manage']],
    ],
];
