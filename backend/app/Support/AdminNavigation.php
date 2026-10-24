<?php

namespace App\Support;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Store;
use App\Models\User;
use App\Services\AdminSidebarManager;
use Illuminate\Support\Facades\Route;

class AdminNavigation
{
    /** @var array<string, bool> */
    private array $enterpriseModuleAccessMemo = [];

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $visibleChannelsMemo = [];

    /** @var array<int, bool> */
    private array $superAdminMemo = [];

    /** @var array<string, bool> */
    private array $permissionMemo = [];

    public function __construct(
        private readonly ModuleRegistry $enterpriseModules,
        private readonly ModuleEntitlementResolver $enterpriseEntitlements,
        private readonly TenantContextResolver $tenantContext,
        private readonly SmartOrderingEnterpriseEntitlementBridge $restaurantSaasAccess,
        private readonly AdminSidebarManager $sidebarManager,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function for(User $user): array
    {
        $memoKey = (int) $user->getKey();
        if (array_key_exists($memoKey, $this->visibleChannelsMemo)) {
            return $this->visibleChannelsMemo[$memoKey];
        }

        $visible = [];

        foreach ((array) config('admin.channels', []) as $channel => $definition) {
            if (is_string($channel) && is_array($definition) && $this->canAccess($user, $channel)) {
                $visible[$channel] = $definition;
            }
        }

        return $this->visibleChannelsMemo[$memoKey] = $visible;
    }

    /**
     * @return list<array{
     *     key:string,
     *     label:string,
     *     icon:string,
     *     children:list<array{key:string,label:string,route:string,params:array<string,string>,permission:?string}>
     * }>
     */
    public function groupsFor(User $user): array
    {
        $channels = $this->for($user);
        $isSuperAdmin = $this->isSuperAdmin($user);
        $canAccessPlatformErp = $this->canAccessPlatformErp($user);
        $canAccessPlatformNavigation = $canAccessPlatformErp;
        $useCustomerServiceDashboardProfile = $this->usesCustomerServiceDashboardProfile($user);

        // Business-first order: enter a channel, define stores/catalog/accounts, execute operations,
        // then marketing/reporting. Administration stays last and never invents cross-channel links.
        $groups = [
            $this->group('overview', 'admin.nav_groups.overview', '⌂', [
                $canAccessPlatformNavigation && ! $useCustomerServiceDashboardProfile
                    ? $this->routeItemOpen('enterprise_action_center', 'enterprise_action_center.nav_title', 'admin.action-center.index')
                    : null,
                $canAccessPlatformNavigation && ! $useCustomerServiceDashboardProfile
                    ? $this->routeItemOpen('enterprise_workspaces', 'enterprise_workspaces.nav_title', 'admin.enterprise-workspaces.index')
                    : null,
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'dashboard', 'admin.premium_nav.dashboard', null),
                $this->module($user, $channels, 'b2b', 'dashboard', 'admin.channels.b2b', null),
                $useCustomerServiceDashboardProfile
                    ? ($this->routeItemScoped($user, 'erp_customer_service', 'admin.erp_center.modules.customer_service.title', 'admin.erp.customer-service', 'customers.view')
                        ?? $this->routeItem($user, 'erp_customer_service', 'admin.erp_center.modules.customer_service.title', 'admin.erp.customer-service', 'b2b.accounts.view'))
                    : null,
            ]),
            $this->group('erp', 'admin.nav_groups.erp', '▤', $canAccessPlatformErp && ! $useCustomerServiceDashboardProfile ? [
                $this->routeItemOpen('erp_center', 'admin.erp_center.nav_title', 'admin.erp.index'),
                $this->routeItemEnterpriseModule($user, 'erp_procurement', 'admin.erp_center.modules.procurement.title', 'admin.erp.procurement.index', 'procurement', 'procurement.suppliers.view'),
                $this->routeItemEnterpriseModule($user, 'erp_wms', 'admin.erp_center.modules.wms.title', 'admin.erp.wms.index', 'inventory_wms', 'inventory.view'),
                $this->routeItemEnterpriseModule($user, 'erp_finance', 'admin.erp_center.modules.finance.title', 'admin.erp.finance.index', 'finance', 'finance.view'),
                $this->routeItemEnterpriseModule($user, 'erp_treasury', 'admin.erp_center.modules.treasury.title', 'admin.erp.treasury.index', 'treasury', 'finance.view'),
                $this->routeItemScoped($user, 'erp_customer_service', 'admin.erp_center.modules.customer_service.title', 'admin.erp.customer-service', 'customers.view')
                    ?? $this->routeItem($user, 'erp_customer_service', 'admin.erp_center.modules.customer_service.title', 'admin.erp.customer-service', 'b2b.accounts.view'),
                $this->routeItemEnterpriseModule($user, 'erp_bi', 'admin.erp_center.modules.bi.title', 'admin.erp.bi', 'enterprise_bi', 'reports.view'),
                $this->routeItemOpen('erp_my_work', 'admin.erp_center.modules.my_work.title', 'admin.action-center.index'),
            ] : []),
            $this->group('stores', 'admin.nav_groups.stores', '⌂', [
                $this->routeItem($user, 'retail_store_provisioning', 'admin.retail_store_provisioning', 'admin.retail-stores.index', 'platform.manage'),
                $this->restaurantSaasItem($user),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'storefront', 'admin.b2c_workspace.modules.storefront', 'stores.view'),
                $this->module($user, $channels, 'b2b', 'storefront', 'admin.b2b_workspace.modules.storefront', 'settings.view'),
                $this->module($user, $channels, 'b2b', 'web_storefront', 'admin.b2b_workspace.modules.web_storefront', 'settings.view'),
            ]),
            $this->group('catalog', 'admin.nav_groups.catalog', '▦', [
                $this->routeItem($user, 'catalog_management', 'admin.catalog_management', 'admin.catalog.index', 'catalog.view'),
                isset($channels['b2b'])
                    ? $this->routeItem($user, 'commercial_sales_control', 'admin.commercial_sales_control', 'admin.commercial.sales-control', 'catalog.view')
                    : null,
                $this->routeItemScoped($user, 'lookup_management', 'admin.lookup_management', 'admin.lookups.index', 'lookups.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'products', 'admin.b2c_workspace.modules.products', 'catalog.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'inventory', 'admin.b2c_workspace.modules.inventory', 'inventory.view'),
                $this->module($user, $channels, 'b2b', 'products', 'admin.b2b_sidebar.product_management', 'catalog.view'),
                $useCustomerServiceDashboardProfile
                    ? $this->module($user, $channels, 'b2b', 'inventory', 'admin.b2b_sidebar.inventory_management', 'inventory.view')
                    : null,
            ]),
            $this->group('accounts', 'admin.nav_groups.accounts', '◎', [
                $this->routeItemScoped($user, 'customer_360', 'admin.customer_360', 'admin.customer-360.index', 'customers.view')
                    ?? $this->routeItem($user, 'customer_360', 'admin.customer_360', 'admin.customer-360.index', 'b2b.accounts.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'customers', 'admin.b2c_workspace.modules.customers', 'customers.view'),
            ]),
            $this->group('operations', 'admin.nav_groups.operations', '↻', [
                $canAccessPlatformNavigation ? $this->whatsAppInboxItem($user) : null,
                $this->routeItemScoped($user, 'order_operations', 'admin.order_management', 'admin.operations.orders.index', 'orders.view'),
                $useCustomerServiceDashboardProfile
                    ? null
                    : $this->routeItemScoped($user, 'driver_live_tracking', 'admin.driver_live_tracking.title', 'admin.driver-live-tracking.index', 'drivers.tracking.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'incoming_orders', 'admin.b2c_workspace.modules.incoming_orders', 'orders.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'drivers', 'admin.b2c_workspace.modules.drivers', 'drivers.b2c.view'),
                $this->module($user, $channels, 'b2b', 'drivers', 'admin.b2b_workspace.modules.drivers', 'drivers.b2b.view'),
            ]),
            $this->group('field_operations', 'admin.nav_groups.field_operations', '⌖', $useCustomerServiceDashboardProfile ? [] : [
                $this->routeItemAny($user, 'field_ops_overview', 'admin.field_operations.overview', 'admin.field-operations.overview', ['field_ops.manage', 'drivers.b2b.view', 'drivers.tracking.view', 'customers.view', 'territories.manage', 'finance.view']),
                $this->routeItemAny($user, 'field_ops_fleet', 'admin.field_operations.fleet', 'admin.field-operations.fleet', ['drivers.tracking.view']),
                $this->routeItemAny($user, 'field_ops_vans', 'admin.field_operations.vans', 'admin.field-operations.vans', ['drivers.b2b.view']),
                $this->routeItemAny($user, 'field_ops_assignments', 'admin.field_operations.assignments', 'admin.field-operations.assignments', ['drivers.b2b.view', 'field_ops.manage']),
                $this->routeItemAll($user, 'field_ops_customers', 'admin.field_operations.customers', 'admin.field-operations.customers', ['customers.view', 'drivers.b2b.view']),
                $this->routeItemAll($user, 'field_ops_visits', 'admin.field_operations.visits', 'admin.field-operations.visits', ['drivers.b2b.view', 'customers.view']),
                $this->routeItemAny($user, 'field_ops_territories', 'admin.field_operations.territories', 'admin.field-operations.territories', ['territories.manage', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_routing', 'admin.field_operations.routing', 'admin.field-operations.routing', ['territories.manage', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_finance', 'admin.field_operations.finance', 'admin.field-operations.finance', ['finance.view']),
            ]),
            $this->group('marketing', 'admin.nav_groups.marketing', '✦', [
                $useCustomerServiceDashboardProfile
                    ? $this->routeItem($user, 'whatsapp_campaigns', 'whatsapp.campaigns.nav_title', 'admin.whatsapp-campaigns.index', 'whatsapp.campaigns.view')
                    : $this->whatsAppCampaignsItem($user),
                $useCustomerServiceDashboardProfile
                    ? null
                    : $this->routeItem($user, 'whatsapp_chatbot', 'chatbot.nav_title', 'admin.whatsapp-chatbot.index', 'chatbot.view'),
                $this->routeItem($user, 'commercial_flash_offers', 'admin.flash_offers', 'admin.commercial.flash-offers', 'promotions.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'promotions', 'admin.b2c_workspace.modules.promotions', 'promotions.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'content', 'admin.b2c_workspace.modules.content', 'promotions.view'),
                $useCustomerServiceDashboardProfile
                    ? $this->routeItemAdvertising($user, 'notification_campaigns', 'notifications.sidebar_campaigns', 'admin.notification-campaigns.index', 'notifications.view', 'advertising_enabled')
                    : null,
                $useCustomerServiceDashboardProfile
                    ? $this->routeItemAdvertising($user, 'live_ads', 'live_ads.title', 'admin.live-ads.index', 'live_ads.view', 'live_ads_enabled')
                    : null,
                $useCustomerServiceDashboardProfile
                    ? $this->routeItemAdvertising($user, 'coupons', 'coupons.title', 'admin.coupons.index', 'coupons.view', 'coupons_enabled')
                    : null,
            ]),
            $this->group('advertising', 'admin.nav_groups.advertising', '◉', $useCustomerServiceDashboardProfile ? [] : [
                $this->routeItemAdvertising($user, 'notification_campaigns', 'notifications.sidebar_campaigns', 'admin.notification-campaigns.index', 'notifications.view', 'advertising_enabled'),
                $this->routeItemAdvertising($user, 'live_ads', 'live_ads.title', 'admin.live-ads.index', 'live_ads.view', 'live_ads_enabled'),
                $this->routeItemAdvertising($user, 'coupons', 'coupons.title', 'admin.coupons.index', 'coupons.view', 'coupons_enabled'),
            ]),
            $this->group('finance', 'admin.nav_groups.finance', '¤', [
                $canAccessPlatformNavigation ? $this->routeItemScoped($user, 'treasury_master', 'treasury.nav_title', 'admin.treasury.master.index', 'finance.view') : null,
            ]),
            $this->group('analytics', 'admin.nav_groups.analytics', '▥', [
                $canAccessPlatformNavigation ? $this->whatsAppSupervisorItem($user) : null,
                $this->routeItem($user, 'reports_center', 'reports.title', 'admin.reports.index', 'reports.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'reports', 'admin.b2c_workspace.modules.reports', 'reports.view'),
                $this->module($user, $channels, 'b2b', 'finance', 'admin.b2b_workspace.modules.finance', 'finance.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2b', 'reports', 'admin.b2b_workspace.modules.reports', 'reports.view'),
            ]),
            $this->group('applications', 'admin.nav_groups.applications', '▣', [
                $this->routeItemScoped($user, 'app_preview', 'admin.app_preview', 'admin.app-preview.index', 'app_preview.view'),
                $this->routeItem($user, 'mobile_customer_download', 'admin.mobile_apps.customer_download', 'admin.mobile-apps.customer.download', 'platform.manage'),
                $this->routeItem($user, 'mobile_driver_download', 'admin.mobile_apps.driver_download', 'admin.mobile-apps.driver.download', 'platform.manage'),
                $this->routeItem($user, 'mobile_van_download', 'admin.mobile_apps.van_download', 'admin.mobile-apps.van.download', 'platform.manage'),
            ]),
            $this->group('administration', 'admin.nav_groups.administration', '⚙', [
                $canAccessPlatformNavigation && ! $useCustomerServiceDashboardProfile
                    ? $this->routeItemOpen('administration_hub', 'admin.administration_hub.title', 'admin.administration.index')
                    : null,
                $this->routeItem($user, 'sidebar_role_access', 'sidebar_admin.role_access_nav', 'admin.sidebar-access.roles', 'roles.manage'),
                $this->routeItem($user, 'sidebar_layout', 'sidebar_admin.layout_nav', 'admin.sidebar-layout.index', 'roles.manage'),
            ]),
        ];

        if (! $isSuperAdmin) {
            $retailStoreIds = $this->tenantContext->retailStoreIds($user);
            $hasRetailWorkspace = $retailStoreIds !== []
                && Store::query()
                    ->whereIn('stores.id', $retailStoreIds)
                    ->businessProfile(StoreBusinessProfile::RETAIL_STORE)
                    ->exists();

            $retailB2cKeys = [
                'b2c_dashboard',
                'b2c_products',
                'b2c_inventory',
                'b2c_orders',
                'b2c_customers',
                'b2c_promotions',
                'b2c_reports',
                'b2c_settings',
            ];

            foreach ($groups as &$group) {
                $group['children'] = array_values(array_filter(
                    $group['children'],
                    static function (array $item) use ($hasRetailWorkspace, $retailB2cKeys): bool {
                        $key = (string) $item['key'];
                        if (! str_starts_with($key, 'b2c_')) {
                            return true;
                        }

                        return $hasRetailWorkspace && in_array($key, $retailB2cKeys, true);
                    },
                ));
            }
            unset($group);
        }

        $groups = array_values(array_filter($groups, static fn (array $group): bool => $group['children'] !== []));

        return $this->sidebarManager->apply($user, $groups);
    }

    /**
     * Resolve the user's default management destination from the canonical RBAC
     * navigation and built-in job-role catalog. Explicit intended URLs are handled
     * by AdminLoginController before this default is used.
     */
    public function defaultWorkspaceUrl(User $user, string $channel, ?int $storeId = null): string
    {
        $channels = $this->for($user);
        $channelDefinition = $channels[$channel] ?? null;

        if (! is_array($channelDefinition) || ! is_string($channelDefinition['route'] ?? null)) {
            return route('admin.index');
        }

        if ($this->usesCustomerServiceDashboardProfile($user)) {
            return route('admin.erp.customer-service');
        }

        $visibleItems = [];
        foreach ($this->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $visibleItems[$item['key']] = $item;
            }
        }

        foreach ($this->workspaceRoleDefinitions($user, $channel, $storeId) as $role) {
            $resolved = $this->workspaceUrlForRole($user, $channel, $role, $visibleItems);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return route($channelDefinition['route']);
    }

    /**
     * @param  array{code:string,domain:string,level:string,sort:int}  $role
     * @param  array<string,array{key:string,label:string,route:string,params:array<string,string>,permission:?string}>  $visibleItems
     */
    private function workspaceUrlForRole(User $user, string $channel, array $role, array $visibleItems): ?string
    {
        if ($channel === 'b2c') {
            $module = match ($role['code']) {
                'RETAIL_OPERATIONS' => 'orders',
                'RETAIL_INVENTORY' => 'inventory',
                'RETAIL_FINANCE' => 'finance',
                'RETAIL_CUSTOMER_SUPPORT' => 'customers',
                default => null,
            };

            if ($module !== null) {
                return $this->channelModuleWorkspaceUrl($user, $channel, $module);
            }

            if ($role['domain'] === 'restaurant') {
                return $this->firstVisibleWorkspaceUrl($visibleItems, ['smart_ordering']);
            }

            // General Retail management intentionally lands on its concise control center.
            if ($role['domain'] === 'retail') {
                return null;
            }
        }

        $keys = match ($role['domain']) {
            'customer_service' => ['erp_customer_service', 'whatsapp_inbox', 'customer_360'],
            'operations' => ['order_operations', 'field_ops_overview'],
            'procurement' => ['erp_procurement'],
            'inventory' => ['erp_wms'],
            'finance' => ['erp_finance'],
            'treasury' => ['erp_treasury', 'treasury_master'],
            'catalog' => ['catalog_management'],
            'sales' => ['commercial_'.'sales_control', 'order_operations'],
            'field_operations' => ['field_ops_overview', 'driver_live_tracking'],
            'marketing' => ['whatsapp_campaigns', 'notification_campaigns'],
            'analytics' => ['reports_center', 'erp_bi'],
            default => [],
        };

        $visible = $this->firstVisibleWorkspaceUrl($visibleItems, $keys);
        if ($visible !== null) {
            return $visible;
        }

        if ($channel !== 'b2b') {
            return null;
        }

        $module = match ($role['domain']) {
            'operations', 'sales' => 'orders',
            'inventory' => 'inventory',
            'finance', 'treasury' => 'finance',
            'catalog' => 'products',
            default => null,
        };

        return $module === null ? null : $this->channelModuleWorkspaceUrl($user, $channel, $module);
    }

    /**
     * @return list<array{code:string,domain:string,level:string,sort:int}>
     */
    private function workspaceRoleDefinitions(User $user, string $channel, ?int $storeId = null): array
    {
        $codes = [];

        if ($channel === 'b2c') {
            $storeIds = $this->tenantContext->retailStoreIds($user);

            if ($storeId !== null) {
                $storeIds = in_array($storeId, $storeIds, true) ? [$storeId] : [];
            }

            if ($storeIds !== []) {
                $codes = $user->storeRoleAssignments()
                    ->whereIn('store_id', $storeIds)
                    ->whereHas('store', fn ($query) => $query->where('stores.is_active', true))
                    ->whereHas('role', fn ($query) => $query
                        ->where('roles.is_active', true)
                        ->where('roles.scope', 'store'))
                    ->with('role:id,code')
                    ->get()
                    ->map(fn ($assignment): string => $assignment->role->code)
                    ->filter()
                    ->values()
                    ->all();
            }
        } else {
            $codes = $user->roles()
                ->where('roles.is_active', true)
                ->where('roles.scope', 'global')
                ->pluck('roles.code')
                ->filter(fn ($code) => is_string($code) && $code !== '')
                ->values()
                ->all();
        }

        $catalog = (array) config('job_roles.roles', []);
        $definitions = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $definition = $catalog[$code] ?? null;
            if (! is_array($definition) || ! is_string($definition['domain'] ?? null)) {
                continue;
            }

            $definitions[] = [
                'code' => $code,
                'domain' => $definition['domain'],
                'level' => is_string($definition['level'] ?? null) ? $definition['level'] : 'operator',
                'sort' => (int) ($definition['sort'] ?? PHP_INT_MAX),
            ];
        }

        $levelRank = ['manager' => 5, 'supervisor' => 4, 'specialist' => 3, 'operator' => 2, 'viewer' => 1];

        usort($definitions, static function (array $left, array $right) use ($levelRank): int {
            $rankComparison = ($levelRank[$right['level']] ?? 0) <=> ($levelRank[$left['level']] ?? 0);

            return $rankComparison !== 0
                ? $rankComparison
                : ($left['sort'] <=> $right['sort']);
        });

        return $definitions;
    }

    /**
     * @param  array<string,array{key:string,label:string,route:string,params:array<string,string>,permission:?string}>  $visibleItems
     * @param  list<string>  $keys
     */
    private function firstVisibleWorkspaceUrl(array $visibleItems, array $keys): ?string
    {
        foreach ($keys as $key) {
            $item = $visibleItems[$key] ?? null;
            if ($item !== null) {
                return route($item['route'], $item['params']);
            }
        }

        return null;
    }

    private function channelModuleWorkspaceUrl(User $user, string $channel, string $module): ?string
    {
        $permissions = [
            'products' => 'catalog.view',
            'inventory' => 'inventory.view',
            'orders' => 'orders.view',
            'finance' => 'finance.view',
            'customers' => 'customers.view',
        ];
        $permission = $permissions[$module] ?? null;

        if ($permission === null
            || ! Route::has("admin.{$channel}.module")
            || ! $this->hasChannelPermission($user, $channel, $permission)) {
            return null;
        }

        return route("admin.{$channel}.module", ['module' => $module]);
    }

    public function canAccess(User $user, string $channel): bool
    {
        $definition = config("admin.channels.{$channel}");

        if (! is_array($definition)) {
            return false;
        }

        $globalRoles = array_values(array_filter((array) ($definition['global_roles'] ?? []), 'is_string'));

        if ($globalRoles !== [] && $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $globalRoles)
            ->exists()) {
            return true;
        }

        $storeRoles = array_values(array_filter((array) ($definition['store_roles'] ?? []), 'is_string'));

        if ($storeRoles === []) {
            return false;
        }

        $retailStoreIds = app(TenantContextResolver::class)->retailStoreIds($user);

        return $retailStoreIds !== [] && $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereIn('roles.code', $storeRoles))
            ->exists();
    }

    public function canAccessPlatformErp(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->exists();
    }

    public function usesCustomerServiceDashboardProfile(User $user): bool
    {
        if ($this->isSuperAdmin($user)) {
            return false;
        }

        return $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', ['CUSTOMER_SERVICE_MANAGER', 'CUSTOMER_SERVICE_SUPERVISOR', 'CUSTOMER_SERVICE_AGENT'])
            ->exists();
    }

    public function canUseDashboard(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($this->for($user) !== []) {
            return true;
        }

        foreach ((array) config('admin.dashboard_permissions', []) as $permission) {
            if (is_string($permission) && $this->hasPermissionInScope($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null>  $children
     * @return array{key:string,label:string,icon:string,children:list<array{key:string,label:string,route:string,params:array<string,string>,permission:?string}>}
     */
    private function group(string $key, string $label, string $icon, array $children): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'children' => array_values(array_filter($children)),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     * @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null
     */
    private function module(User $user, array $channels, string $channel, string $module, string $label, ?string $permission): ?array
    {
        if (array_key_exists($channel, $channels) === false || $this->hasChannelPermission($user, $channel, $permission) === false) {
            return null;
        }

        return [
            'key' => "{$channel}_{$module}",
            'label' => $label,
            'route' => "admin.{$channel}.module",
            'params' => ['module' => $module],
            'permission' => $permission,
        ];
    }

    private function hasChannelPermission(User $user, string $channel, ?string $permission): bool
    {
        if ($permission === null || $this->hasPermission($user, $permission)) {
            return true;
        }

        $storeRoles = array_values(array_filter((array) config("admin.channels.{$channel}.store_roles", []), 'is_string'));

        if ($storeRoles === []) {
            return false;
        }

        $retailStoreIds = app(TenantContextResolver::class)->retailStoreIds($user);

        return $retailStoreIds !== [] && $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereIn('roles.code', $storeRoles)
                ->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.code', $permission)))
            ->exists();
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function restaurantSaasItem(User $user): ?array
    {
        if ($this->isSuperAdmin($user) || ! $this->canAccess($user, 'b2c')) {
            return null;
        }

        $retailStoreIds = $this->tenantContext->retailStoreIds($user);
        $restaurantStoreIds = $retailStoreIds === []
            ? []
            : Store::query()
                ->whereIn('stores.id', $retailStoreIds)
                ->businessProfile(StoreBusinessProfile::RESTAURANT)
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        if ($restaurantStoreIds === [] || $this->restaurantSaasAccess->enabledStoreIds($restaurantStoreIds) === []) {
            return null;
        }

        $destinations = [
            ['route' => 'admin.smart-ordering.index', 'permission' => 'smart_ordering.view'],
            ['route' => 'admin.smart-ordering.operations.index', 'permission' => 'orders.view'],
            ['route' => 'admin.smart-ordering.qr.index', 'permission' => 'smart_ordering.qr.view'],
            ['route' => 'admin.smart-ordering.analytics.index', 'permission' => 'smart_ordering.analytics.view'],
        ];

        foreach ($destinations as $destination) {
            if (Route::has($destination['route']) && $this->hasPermissionInScope($user, $destination['permission'])) {
                return [
                    'key' => 'smart_ordering',
                    'label' => 'smart_ordering.nav_title',
                    'route' => $destination['route'],
                    'params' => [],
                    'permission' => $destination['permission'],
                ];
            }
        }

        return null;
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function whatsAppInboxItem(User $user): ?array
    {
        if (! app(RuntimePolicy::class)->allowsAgentInbox()) {
            return null;
        }

        return $this->routeItemScoped(
            $user,
            'whatsapp_inbox',
            'whatsapp.inbox_title',
            'admin.whatsapp-inbox.index',
            'whatsapp.inbox.view',
        );
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function whatsAppCampaignsItem(User $user): ?array
    {
        if (! app(RuntimePolicy::class)->allowsCampaignManagement()) {
            return null;
        }

        return $this->routeItem(
            $user,
            'whatsapp_campaigns',
            'whatsapp.campaigns.nav_title',
            'admin.whatsapp-campaigns.index',
            'whatsapp.campaigns.view',
        );
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function whatsAppSupervisorItem(User $user): ?array
    {
        if (! app(RuntimePolicy::class)->allowsAgentInbox()) {
            return null;
        }

        return $this->routeItemScoped(
            $user,
            'whatsapp_supervisor',
            'whatsapp_supervisor.nav_title',
            'admin.whatsapp-supervisor.index',
            'whatsapp.customer_service.supervise',
        );
    }

    public function hasPermissionInScope(User $user, string $permission): bool
    {
        if ($this->hasPermission($user, $permission)) {
            return true;
        }

        $retailStoreIds = $this->tenantContext->retailStoreIds($user);
        if ($retailStoreIds === []) {
            return false;
        }

        return $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('store', fn ($query) => $query->where('stores.is_active', true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', $permission)))
            ->exists();
    }

    /** @param list<string> $permissions */
    public function hasAnyPermissionInScope(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionInScope($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemScoped(
        User $user,
        string $key,
        string $label,
        string $route,
        string $permission,
        array $params = [],
    ): ?array {
        if (! Route::has($route) || ! $this->hasPermissionInScope($user, $permission)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'params' => $params,
            'permission' => null,
        ];
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemEnterpriseModule(
        User $user,
        string $key,
        string $label,
        string $route,
        string $moduleCode,
        string $permission,
        array $params = [],
    ): ?array {
        $item = $this->routeItemScoped($user, $key, $label, $route, $permission, $params);

        if ($item === null || ! $this->enterpriseModuleAccessible($user, $moduleCode)) {
            return null;
        }

        return $item;
    }

    private function enterpriseModuleAccessible(User $user, string $moduleCode): bool
    {
        $memoKey = $user->getKey().'|'.$moduleCode;
        if (array_key_exists($memoKey, $this->enterpriseModuleAccessMemo)) {
            return $this->enterpriseModuleAccessMemo[$memoKey];
        }

        if (! $this->enterpriseModules->isEnabled($moduleCode)) {
            return $this->enterpriseModuleAccessMemo[$memoKey] = false;
        }

        if ($this->enterpriseEntitlements->allows($moduleCode, EntitlementScope::platform())) {
            return $this->enterpriseModuleAccessMemo[$memoKey] = true;
        }

        foreach ($this->tenantContext->retailStoreIds($user) as $storeId) {
            if ($this->enterpriseEntitlements->allows(
                $moduleCode,
                new EntitlementScope(EntitlementScope::STORE, $storeId),
            )) {
                return $this->enterpriseModuleAccessMemo[$memoKey] = true;
            }
        }

        return $this->enterpriseModuleAccessMemo[$memoKey] = false;
    }

    private function isSuperAdmin(User $user): bool
    {
        $memoKey = (int) $user->getKey();

        return $this->superAdminMemo[$memoKey]
            ??= $user->hasRole('SUPER_ADMIN');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $memoKey = (int) $user->getKey().'|'.$permission;

        return $this->permissionMemo[$memoKey]
            ??= $user->hasPermission($permission);
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemAdvertising(
        User $user,
        string $key,
        string $label,
        string $route,
        string $permission,
        string $featureColumn,
    ): ?array {
        if (! Route::has($route)) {
            return null;
        }

        if ($this->isSuperAdmin($user) || $this->hasPermission($user, $permission)) {
            return [
                'key' => $key,
                'label' => $label,
                'route' => $route,
                'params' => [],
                'permission' => null,
            ];
        }

        $retailStoreIds = app(TenantContextResolver::class)->retailStoreIds($user);
        if ($retailStoreIds === []) {
            return null;
        }

        $allowed = $user->storeRoleAssignments()
            ->whereIn('store_id', $retailStoreIds)
            ->whereHas('store', fn ($query) => $query
                ->where('stores.is_active', true)
                ->where("stores.{$featureColumn}", true))
            ->whereHas('role', fn ($query) => $query
                ->where('roles.is_active', true)
                ->where('roles.scope', 'store')
                ->whereHas('permissions', fn ($permissions) => $permissions
                    ->where('permissions.code', $permission)))
            ->exists();

        if (! $allowed) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'params' => [],
            'permission' => null,
        ];
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemAny(User $user, string $key, string $label, string $route, array $permissions): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        foreach ($permissions as $permission) {
            if ($this->hasPermission($user, $permission)) {
                return ['key' => $key, 'label' => $label, 'route' => $route, 'params' => [], 'permission' => null];
            }
        }

        return null;
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemAll(User $user, string $key, string $label, string $route, array $permissions): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        foreach ($permissions as $permission) {
            if (! $this->hasPermission($user, $permission)) {
                return null;
            }
        }

        return ['key' => $key, 'label' => $label, 'route' => $route, 'params' => [], 'permission' => null];
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemOpen(string $key, string $label, string $route): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        return ['key' => $key, 'label' => $label, 'route' => $route, 'params' => [], 'permission' => null];
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItem(User $user, string $key, string $label, string $route, string $permission): ?array
    {
        if (Route::has($route) === false || $this->hasPermission($user, $permission) === false) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'params' => [],
            'permission' => $permission,
        ];
    }
}
