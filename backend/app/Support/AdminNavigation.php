<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

class AdminNavigation
{
    /** @return array<string, array<string, mixed>> */
    public function for(User $user): array
    {
        $visible = [];

        foreach ((array) config('admin.channels', []) as $channel => $definition) {
            if (is_string($channel) && is_array($definition) && $this->canAccess($user, $channel)) {
                $visible[$channel] = $definition;
            }
        }

        return $visible;
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
        $isSuperAdmin = $user->hasRole('SUPER_ADMIN');

        // Business-first order: enter a channel, define stores/catalog/accounts, execute operations,
        // then marketing/reporting. Administration stays last and never invents cross-channel links.
        $groups = [
            $this->group('overview', 'admin.nav_groups.overview', '⌂', [
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'dashboard', 'admin.premium_nav.dashboard', null),
                $this->module($user, $channels, 'b2b', 'dashboard', 'admin.channels.b2b', null),
            ]),
            $this->group('stores', 'admin.nav_groups.stores', '⌂', [
                $this->routeItem($user, 'retail_store_provisioning', 'admin.retail_store_provisioning', 'admin.retail-stores.index', 'platform.manage'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'storefront', 'admin.b2c_workspace.modules.storefront', 'stores.view'),
                $this->module($user, $channels, 'b2b', 'storefront', 'admin.b2b_workspace.modules.storefront', 'settings.view'),
            ]),
            $this->group('catalog', 'admin.nav_groups.catalog', '▦', [
                $this->routeItem($user, 'catalog_management', 'admin.catalog_management', 'admin.catalog.index', 'catalog.view'),
                $this->routeItem($user, 'commercial_sales_control', 'admin.commercial_sales_control', 'admin.commercial.sales-control', 'catalog.view'),
                $this->routeItemScoped($user, 'lookup_management', 'admin.lookup_management', 'admin.lookups.index', 'lookups.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'products', 'admin.b2c_workspace.modules.products', 'catalog.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'inventory', 'admin.b2c_workspace.modules.inventory', 'inventory.view'),
                $this->module($user, $channels, 'b2b', 'products', 'admin.b2b_workspace.modules.products', 'catalog.view'),
                $this->module($user, $channels, 'b2b', 'inventory', 'admin.b2b_workspace.modules.inventory', 'inventory.view'),
                $this->module($user, $channels, 'b2b', 'pricing', 'admin.b2b_workspace.modules.pricing', 'b2b.pricing.view'),
            ]),
            $this->group('accounts', 'admin.nav_groups.accounts', '◎', [
                $this->routeItemScoped($user, 'customer_360', 'admin.customer_360', 'admin.customer-360.index', 'customers.view')
                    ?? $this->routeItem($user, 'customer_360', 'admin.customer_360', 'admin.customer-360.index', 'b2b.accounts.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'customers', 'admin.b2c_workspace.modules.customers', 'customers.view'),
                $this->module($user, $channels, 'b2b', 'clients', 'admin.b2b_workspace.modules.clients', 'b2b.accounts.view'),
            ]),
            $this->group('operations', 'admin.nav_groups.operations', '↻', [
                $this->routeItemScoped($user, 'system_lookups', 'admin.system_lookups', 'admin.operations.lookups.index', 'lookups.view'),
                $this->routeItemScoped($user, 'order_operations', 'admin.order_management', 'admin.operations.orders.index', 'orders.view'),
                $this->routeItemScoped($user, 'driver_live_tracking', 'admin.driver_live_tracking.title', 'admin.driver-live-tracking.index', 'drivers.tracking.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'incoming_orders', 'admin.b2c_workspace.modules.incoming_orders', 'orders.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'orders', 'admin.b2c_workspace.modules.orders', 'orders.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'drivers', 'admin.b2c_workspace.modules.drivers', 'drivers.b2c.view'),
                $this->module($user, $channels, 'b2b', 'orders', 'admin.b2b_workspace.modules.orders', 'orders.view'),
                $this->module($user, $channels, 'b2b', 'drivers', 'admin.b2b_workspace.modules.drivers', 'drivers.b2b.view'),
            ]),
            $this->group('field_operations', 'admin.nav_groups.field_operations', '⌖', [
                $this->routeItemAny($user, 'field_ops_overview', 'admin.field_operations.overview', 'admin.field-operations.overview', ['field_ops.manage', 'drivers.b2b.view', 'drivers.tracking.view', 'customers.view', 'territories.manage', 'finance.view']),
                $this->routeItemAny($user, 'field_ops_fleet', 'admin.field_operations.fleet', 'admin.field-operations.fleet', ['drivers.tracking.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_vans', 'admin.field_operations.vans', 'admin.field-operations.vans', ['drivers.b2b.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_assignments', 'admin.field_operations.assignments', 'admin.field-operations.assignments', ['drivers.b2b.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_customers', 'admin.field_operations.customers', 'admin.field-operations.customers', ['customers.view', 'drivers.b2b.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_visits', 'admin.field_operations.visits', 'admin.field-operations.visits', ['drivers.b2b.view', 'customers.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_territories', 'admin.field_operations.territories', 'admin.field-operations.territories', ['territories.manage', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_address_quality', 'admin.field_operations.address_quality', 'admin.field-operations.address-quality', ['customers.view', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_routing', 'admin.field_operations.routing', 'admin.field-operations.routing', ['territories.manage', 'field_ops.manage']),
                $this->routeItemAny($user, 'field_ops_finance', 'admin.field_operations.finance', 'admin.field-operations.finance', ['finance.view', 'field_ops.manage']),
                $this->routeItemFlaggedAny($user, 'field_ops_commercial_rules', 'admin.field_operations.commercial_rules', 'admin.commercial.sales-control', ['catalog.view', 'field_ops.manage'], 'commercial_rules_enabled'),
                $this->routeItemFlaggedAny($user, 'field_ops_van_offers', 'admin.field_operations.van_offers', 'admin.commercial.flash-offers', ['promotions.view', 'field_ops.manage'], 'van_offers_enabled'),
            ]),
            $this->group('marketing', 'admin.nav_groups.marketing', '✦', [
                $this->routeItem($user, 'commercial_flash_offers', 'admin.flash_offers', 'admin.commercial.flash-offers', 'promotions.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'promotions', 'admin.b2c_workspace.modules.promotions', 'promotions.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'content', 'admin.b2c_workspace.modules.content', 'promotions.view'),
            ]),
            $this->group('advertising', 'admin.nav_groups.advertising', '◉', [
                $this->routeItemAdvertising($user, 'notification_campaigns', 'notifications.sidebar_campaigns', 'admin.notification-campaigns.index', 'notifications.view', 'advertising_enabled'),
                $this->routeItemAdvertising($user, 'live_ads', 'live_ads.title', 'admin.live-ads.index', 'live_ads.view', 'live_ads_enabled'),
                $this->routeItemAdvertising($user, 'coupons', 'coupons.title', 'admin.coupons.index', 'coupons.view', 'coupons_enabled'),
            ]),
            $this->group('analytics', 'admin.nav_groups.analytics', '▥', [
                $this->routeItem($user, 'reports_center', 'reports.title', 'admin.reports.index', 'reports.view'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'finance', 'admin.b2c_workspace.modules.finance', 'finance.view'),
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
                $this->routeItemOpen('profile', 'admin.profile', 'admin.profile.index'),
                $isSuperAdmin ? null : $this->module($user, $channels, 'b2c', 'settings', 'admin.b2c_workspace.modules.settings', null),
                $this->module($user, $channels, 'b2b', 'settings', 'admin.b2b_workspace.modules.settings', 'settings.view'),
                $this->routeItem($user, 'security', 'admin.security_center', 'admin.security.index', 'security.view'),
                $this->routeItem($user, 'demo_data', 'admin.security.demo_data.title', 'admin.security.demo-data.index', 'demo_data.manage'),
                $this->routeItem($user, 'translations', 'admin.translation_center', 'admin.translations.index', 'translations.manage'),
                $this->routeItemAny($user, 'assistant_settings', 'admin.assistant_settings', 'admin.assistant-settings.index', ['settings.view', 'settings.manage']),
                $this->routeItemAny($user, 'mobile_settings', 'mobile_settings.title', 'admin.mobile-settings.index', ['mobile_settings.manage', 'push_settings.manage', 'push_settings.test']),
                $this->routeItem($user, 'app_versions', 'admin.app_versions', 'admin.app-versions.index', 'platform.manage'),
                $this->routeItem($user, 'system_inspector', 'admin.system_inspector', 'admin.inspector.index', 'platform.manage'),
                $this->routeItem($user, 'system_update', 'admin.system_update', 'admin.system-update.index', 'system.update'),
            ]),
        ];

        return array_values(array_filter($groups, static fn (array $group): bool => $group['children'] !== []));
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

    public function canUseDashboard(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($this->for($user) !== []) {
            return true;
        }

        foreach ((array) config('admin.dashboard_permissions', []) as $permission) {
            if (is_string($permission) && $user->hasPermission($permission)) {
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
        if ($permission === null || $user->hasPermission($permission)) {
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
    private function routeItemScoped(User $user, string $key, string $label, string $route, string $permission): ?array
    {
        if (! Route::has($route)) {
            return null;
        }

        if (! $user->hasPermission($permission)) {
            $hasScopedPermission = $user->storeRoleAssignments()
                ->whereIn('store_id', app(TenantContextResolver::class)->retailStoreIds($user))
                ->whereHas('store', fn ($query) => $query->where('stores.is_active', true))
                ->whereHas('role', fn ($query) => $query
                    ->where('roles.is_active', true)
                    ->where('roles.scope', 'store')
                    ->whereHas('permissions', fn ($permissions) => $permissions
                        ->where('permissions.code', $permission)))
                ->exists();

            if (! $hasScopedPermission) {
                return null;
            }
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

        if ($user->hasRole('SUPER_ADMIN') || $user->hasPermission($permission)) {
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
            if ($user->hasPermission($permission)) {
                return ['key' => $key, 'label' => $label, 'route' => $route, 'params' => [], 'permission' => null];
            }
        }

        return null;
    }

    /** @return array{key:string,label:string,route:string,params:array<string,string>,permission:?string}|null */
    private function routeItemFlaggedAny(
        User $user,
        string $key,
        string $label,
        string $route,
        array $permissions,
        string $featureFlag,
    ): ?array {
        if (! app(\App\Services\CommercialFeatureFlags::class)->enabled($featureFlag)) {
            return null;
        }

        return $this->routeItemAny($user, $key, $label, $route, $permissions);
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
        if (Route::has($route) === false || $user->hasPermission($permission) === false) {
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
