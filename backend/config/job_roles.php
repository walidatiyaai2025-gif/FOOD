<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Separation-of-duties advisory pairs
    |--------------------------------------------------------------------------
    |
    | These combinations are intentionally advisory at assignment time. Runtime
    | maker/checker services remain authoritative and must still prevent the same
    | actor from preparing and approving the same protected transaction.
    |
    */
    'risk_pairs' => [
        'procurement_maker_checker' => [
            'roles' => ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Canonical FOODEX built-in business role catalog
    |--------------------------------------------------------------------------
    |
    | Codes are immutable authorization identifiers. Presentation lives in
    | lang/{locale}/job_roles.php. Existing #96 role codes are reused where they
    | already represent the business job to avoid semantic duplicates.
    |
    | "scope" is the assignment scope supported by the current RBAC engine
    | (global/store). "business_scope" is the user-facing governance scope.
    | Required modules must be real enterprise module manifest codes.
    |
    */
    'roles' => [
        'SUPER_ADMIN' => ['domain'=>'administration','scope'=>'global','business_scope'=>'platform','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['*'],'sort'=>10],
        'SYSTEM_ADMINISTRATOR' => ['domain'=>'administration','scope'=>'global','business_scope'=>'platform','level'=>'specialist','required_modules'=>[],'optional_modules'=>[],'permissions'=>['platform.manage','settings.view','settings.manage','stores.view','stores.manage','translations.manage','system.update','mobile_settings.manage','push_settings.manage','sms_settings.manage'],'sort'=>20],
        'SECURITY_ADMINISTRATOR' => ['domain'=>'administration','scope'=>'global','business_scope'=>'platform','level'=>'specialist','required_modules'=>[],'optional_modules'=>[],'permissions'=>['security.view','roles.manage','users.view','users.roles.manage','users.status.manage','security.scopes.manage','security.approval_limits.manage','security.delegations.manage','security.approve'],'sort'=>30],

        'B2B_ADMIN' => ['domain'=>'b2b','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','b2b.accounts.view','b2b.accounts.manage','b2b.pricing.view','b2b.pricing.manage','catalog.view','catalog.create','catalog.edit','catalog.manage','lookups.view','lookups.manage','inventory.view','inventory.adjust','inventory.manage','inventory.replenishment_mapping.manage','orders.view','orders.edit','orders.approve','orders.manage','orders.dispatch','finance.view','finance.manage','reports.view','reports.export','settings.view','settings.manage','assistant.use','drivers.tracking.view','drivers.tracking.history','drivers.tracking.manage','drivers.b2b.view','drivers.b2b.manage','notifications.view','notifications.manage','coupons.view','coupons.manage','live_ads.view','live_ads.manage'],'sort'=>100],
        'B2B_ACCOUNT_MANAGER' => ['domain'=>'b2b','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['b2b.accounts.view','b2b.accounts.manage','b2b.pricing.view','customers.view','customers.edit','orders.view','reports.view'],'sort'=>110],
        'B2B_OPERATIONS_OFFICER' => ['domain'=>'b2b','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','orders.view','orders.edit','orders.manage','orders.dispatch','drivers.tracking.view','drivers.b2b.view','drivers.b2b.manage'],'sort'=>120],

        'SALES_MANAGER' => ['domain'=>'sales','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['b2b.accounts.view','b2b.accounts.manage','b2b.pricing.view','b2b.pricing.manage','orders.view','orders.edit','orders.approve','orders.manage','customers.view','customers.edit','customers.manage','reports.view','reports.export'],'sort'=>200],
        'SALES_OFFICER' => ['domain'=>'sales','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['b2b.accounts.view','b2b.pricing.view','orders.view','orders.edit','customers.view','customers.edit'],'sort'=>210],
        'SALES_ANALYST' => ['domain'=>'sales','scope'=>'global','business_scope'=>'tenant','level'=>'viewer','required_modules'=>[],'optional_modules'=>[],'permissions'=>['b2b.accounts.view','b2b.pricing.view','orders.view','customers.view','reports.view','reports.export'],'sort'=>220],

        'CUSTOMER_SERVICE_MANAGER' => ['domain'=>'customer_service','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','support.view','support.manage','customer_service.team.manage','customers.view','customers.edit','customers.manage','orders.view','orders.create','orders.edit','orders.approve','orders.manage','orders.dispatch','whatsapp.inbox.view','whatsapp.conversations.view','whatsapp.conversations.reply','whatsapp.conversations.assign','whatsapp.conversations.reassign','whatsapp.internal_notes.manage','whatsapp.customer_service.supervise','whatsapp.agent_status.manage','whatsapp.assignment_rules.manage','reports.view'],'sort'=>300],
        'CUSTOMER_SERVICE_SUPERVISOR' => ['domain'=>'customer_service','scope'=>'global','business_scope'=>'tenant','level'=>'supervisor','required_modules'=>[],'optional_modules'=>[],'permissions'=>['whatsapp.inbox.view','whatsapp.conversations.view','whatsapp.conversations.reply','whatsapp.conversations.assign','whatsapp.conversations.reassign','whatsapp.internal_notes.manage','whatsapp.customer_service.supervise','whatsapp.agent_status.manage','whatsapp.assignment_rules.manage','whatsapp.campaigns.view','whatsapp.campaigns.manage','whatsapp.campaigns.send','chatbot.view','chatbot.manage','chatbot.runtime.manage','chatbot.features.view','chatbot.features.manage','chatbot.flows.view','chatbot.flows.manage','chatbot.flows.publish','chatbot.ai.view','chatbot.ai.manage','chatbot.segments.view','chatbot.segments.manage','chatbot.analytics.view','chatbot.sessions.view','chatbot.sessions.takeover','chatbot.audit.view','stores.view','customers.view','orders.view','orders.create'],'sort'=>310],
        'CUSTOMER_SERVICE_AGENT' => ['domain'=>'customer_service','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['whatsapp.inbox.view','whatsapp.conversations.view','whatsapp.conversations.reply','whatsapp.conversations.assign','whatsapp.internal_notes.manage','stores.view','customers.view','orders.view','orders.create'],'sort'=>320],
        'CUSTOMER_SUPPORT' => ['domain'=>'customer_service','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['assistant.use','stores.view','b2b.accounts.view','b2b.accounts.manage','support.view','support.manage','customers.view','customers.edit','customers.manage','orders.view','orders.manage','orders.dispatch','whatsapp.inbox.view','whatsapp.conversations.view','whatsapp.conversations.reply','whatsapp.conversations.assign','whatsapp.internal_notes.manage'],'sort'=>330],

        'OPERATIONS' => ['domain'=>'operations','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','orders.view','orders.edit','orders.approve','orders.manage','orders.dispatch','assistant.use','drivers.tracking.view','drivers.b2b.view','drivers.b2b.manage','reports.view'],'sort'=>400],
        'OPERATIONS_SUPERVISOR' => ['domain'=>'operations','scope'=>'global','business_scope'=>'tenant','level'=>'supervisor','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','orders.view','orders.edit','orders.approve','orders.manage','orders.dispatch','drivers.tracking.view','reports.view'],'sort'=>410],
        'ORDER_OPERATOR' => ['domain'=>'operations','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['orders.view','orders.edit','orders.manage'],'sort'=>420],

        'PROCUREMENT_MANAGER' => ['domain'=>'procurement','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>['procurement'],'optional_modules'=>['inventory_wms','finance'],'permissions'=>['procurement.suppliers.view','procurement.suppliers.manage','procurement.purchase_requests.view','procurement.purchase_requests.manage','procurement.purchase_requests.approve','procurement.purchase_orders.view','procurement.purchase_orders.manage','procurement.purchase_orders.issue','procurement.goods_receipts.view','procurement.goods_receipts.manage','procurement.goods_receipts.post','procurement.purchase_returns.view','procurement.purchase_returns.manage','procurement.purchase_returns.post','procurement.purchase_invoices.view','procurement.purchase_invoices.manage','procurement.purchase_invoices.handoff','procurement.supplier_statements.view','reports.view','reports.export'],'sort'=>500],
        'PROCUREMENT_BUYER' => ['domain'=>'procurement','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>['procurement'],'optional_modules'=>['inventory_wms'],'permissions'=>['procurement.suppliers.view','procurement.suppliers.manage','procurement.purchase_requests.view','procurement.purchase_requests.manage','procurement.purchase_orders.view','procurement.purchase_orders.manage','procurement.goods_receipts.view','procurement.purchase_invoices.view','procurement.supplier_statements.view'],'sort'=>510],
        'PROCUREMENT_APPROVER' => ['domain'=>'procurement','scope'=>'global','business_scope'=>'tenant','level'=>'specialist','required_modules'=>['procurement'],'optional_modules'=>['finance'],'permissions'=>['procurement.suppliers.view','procurement.purchase_requests.view','procurement.purchase_requests.approve','procurement.purchase_orders.view','procurement.purchase_orders.issue','procurement.purchase_invoices.view','procurement.supplier_statements.view','reports.view'],'sort'=>520],

        'INVENTORY' => ['domain'=>'inventory','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>['inventory_wms'],'permissions'=>['stores.view','catalog.view','catalog.create','catalog.edit','catalog.manage','lookups.view','assistant.use','inventory.view','inventory.adjust','inventory.manage'],'sort'=>600],
        'WAREHOUSE_MANAGER' => ['domain'=>'inventory','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>['inventory_wms'],'optional_modules'=>['procurement'],'permissions'=>['inventory.view','inventory.adjust','inventory.manage','catalog.view','reports.view'],'sort'=>610],
        'INVENTORY_CONTROLLER' => ['domain'=>'inventory','scope'=>'global','business_scope'=>'tenant','level'=>'specialist','required_modules'=>['inventory_wms'],'optional_modules'=>[],'permissions'=>['inventory.view','inventory.adjust','catalog.view','reports.view'],'sort'=>620],

        'FINANCE' => ['domain'=>'finance','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>['finance'],'permissions'=>['assistant.use','stores.view','finance.view','finance.manage','finance.post','reports.view','reports.export'],'sort'=>700],
        'ACCOUNTING_MANAGER' => ['domain'=>'finance','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>['finance'],'optional_modules'=>['treasury'],'permissions'=>['finance.view','finance.manage','finance.post','finance.approve','reports.view','reports.export'],'sort'=>710],
        'ACCOUNTANT' => ['domain'=>'finance','scope'=>'global','business_scope'=>'tenant','level'=>'specialist','required_modules'=>['finance'],'optional_modules'=>[],'permissions'=>['finance.view','finance.manage','finance.post','reports.view'],'sort'=>720],
        'AR_OFFICER' => ['domain'=>'finance','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>['finance'],'optional_modules'=>[],'permissions'=>['finance.view','finance.manage','reports.view'],'sort'=>730],
        'AP_OFFICER' => ['domain'=>'finance','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>['finance'],'optional_modules'=>['procurement'],'permissions'=>['finance.view','finance.manage','reports.view'],'sort'=>740],
        'TREASURY_MANAGER' => ['domain'=>'treasury','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>['treasury'],'optional_modules'=>['finance'],'permissions'=>['finance.view','finance.manage','finance.approve','reports.view','reports.export'],'sort'=>750],
        'TREASURY_OFFICER' => ['domain'=>'treasury','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>['treasury'],'optional_modules'=>['finance'],'permissions'=>['finance.view','finance.manage','reports.view'],'sort'=>760],

        'CATALOG_MANAGER' => ['domain'=>'catalog','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['catalog.view','catalog.create','catalog.edit','catalog.delete','catalog.manage','lookups.view','lookups.manage','reports.view'],'sort'=>800],
        'PRODUCT_OFFICER' => ['domain'=>'catalog','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['catalog.view','catalog.create','catalog.edit','lookups.view'],'sort'=>810],

        'B2C_STORE_ADMIN' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE','RESTAURANT'],'level'=>'manager','required_modules'=>[],'optional_modules'=>['smart_ordering'],'permissions'=>['stores.view','smart_ordering.view','smart_ordering.manage','smart_ordering.publish','smart_ordering.analytics.view','smart_ordering.analytics.export','catalog.view','catalog.create','catalog.edit','catalog.delete','catalog.manage','lookups.view','lookups.manage','inventory.view','inventory.adjust','inventory.manage','orders.view','orders.edit','orders.approve','orders.manage','orders.dispatch','customers.view','customers.create','customers.edit','customers.delete','customers.manage','promotions.view','promotions.manage','smart_ordering.qr.view','smart_ordering.qr.manage','finance.view','finance.manage','reports.view','reports.export','settings.view','settings.manage','assistant.use','drivers.tracking.view','drivers.tracking.history','drivers.tracking.manage','drivers.b2c.view','drivers.b2c.manage','notifications.view','notifications.manage','coupons.view','coupons.manage','live_ads.view','live_ads.manage'],'sort'=>900],
        'STORE_SUPERVISOR' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'supervisor','required_modules'=>[],'optional_modules'=>[],'permissions'=>['stores.view','orders.view','orders.edit','orders.approve','orders.manage','inventory.view','inventory.adjust','customers.view','customers.edit','reports.view'],'sort'=>910],
        'STORE_STAFF' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['orders.view','orders.edit','inventory.view','customers.view'],'sort'=>920],
        'RETAIL_OPERATIONS' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['assistant.use','smart_ordering.qr.view','orders.view','orders.edit','orders.approve','orders.manage','orders.dispatch','drivers.tracking.view','drivers.b2c.view','drivers.b2c.manage','reports.view'],'sort'=>930],
        'RETAIL_INVENTORY' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'specialist','required_modules'=>[],'optional_modules'=>['inventory_wms'],'permissions'=>['catalog.view','catalog.create','catalog.edit','catalog.manage','lookups.view','assistant.use','inventory.view','inventory.adjust','inventory.manage','inventory.replenishment_mapping.manage'],'sort'=>940],
        'RETAIL_FINANCE' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'specialist','required_modules'=>[],'optional_modules'=>['finance'],'permissions'=>['assistant.use','finance.view','finance.manage','finance.post','reports.view','reports.export'],'sort'=>950],
        'RETAIL_CUSTOMER_SUPPORT' => ['domain'=>'retail','scope'=>'store','business_scope'=>'store','business_profiles'=>['RETAIL_STORE'],'level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['assistant.use','support.view','support.manage','customers.view','customers.edit','customers.manage','orders.view','orders.manage','orders.dispatch','whatsapp.inbox.view','whatsapp.conversations.view','whatsapp.conversations.reply','whatsapp.conversations.assign','whatsapp.internal_notes.manage'],'sort'=>960],
        'RESTAURANT_DIGITAL_MANAGER' => ['domain'=>'restaurant','scope'=>'store','business_scope'=>'store','business_profiles'=>['RESTAURANT'],'level'=>'manager','required_modules'=>['smart_ordering'],'optional_modules'=>[],'permissions'=>['smart_ordering.view','smart_ordering.manage','smart_ordering.publish','smart_ordering.analytics.view','smart_ordering.analytics.export','smart_ordering.qr.view','smart_ordering.qr.manage','reports.view'],'sort'=>970],

        'FLEET_MANAGER' => ['domain'=>'field_operations','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['field_ops.manage','territories.manage','drivers.tracking.view','drivers.tracking.history','drivers.tracking.manage','drivers.b2b.view','drivers.b2b.manage','reports.view'],'sort'=>1000],
        'DISPATCH_SUPERVISOR' => ['domain'=>'field_operations','scope'=>'global','business_scope'=>'tenant','level'=>'supervisor','required_modules'=>[],'optional_modules'=>[],'permissions'=>['orders.view','orders.dispatch','drivers.tracking.view','drivers.b2b.view','drivers.b2b.manage','reports.view'],'sort'=>1010],
        'B2B_DRIVER' => ['domain'=>'field_operations','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['deliveries.b2b.execute'],'sort'=>1020],
        'B2C_DRIVER' => ['domain'=>'field_operations','scope'=>'global','business_scope'=>'store','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['deliveries.b2c.execute'],'sort'=>1030],
        'VAN_OPERATOR' => ['domain'=>'field_operations','scope'=>'global','business_scope'=>'tenant','level'=>'operator','required_modules'=>[],'optional_modules'=>[],'permissions'=>['van.login'],'sort'=>1040],

        'MARKETING_MANAGER' => ['domain'=>'marketing','scope'=>'global','business_scope'=>'tenant','level'=>'manager','required_modules'=>[],'optional_modules'=>[],'permissions'=>['promotions.view','promotions.manage','notifications.view','notifications.manage','coupons.view','coupons.manage','live_ads.view','live_ads.manage','reports.view','reports.export'],'sort'=>1100],

        'EXECUTIVE_VIEWER' => ['domain'=>'analytics','scope'=>'global','business_scope'=>'tenant','level'=>'viewer','required_modules'=>['enterprise_bi'],'optional_modules'=>[],'permissions'=>['reports.view','reports.export'],'sort'=>1200],
        'BUSINESS_ANALYST' => ['domain'=>'analytics','scope'=>'global','business_scope'=>'tenant','level'=>'specialist','required_modules'=>['enterprise_bi'],'optional_modules'=>[],'permissions'=>['reports.view','reports.export'],'sort'=>1210],
    ],

    'sod_warnings' => [
        [
            'roles' => ['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'],
            'message_key' => 'procurement_maker_checker',
        ],
    ],
];
