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
        $groups = [
            $this->group('overview', 'admin.nav_groups.overview', '⌂', [
                $this->module($user, $channels, 'b2c', 'dashboard', 'admin.channels.b2c', null),
                $this->module($user, $channels, 'b2b', 'dashboard', 'admin.channels.b2b', null),
            ]),
            $this->group('operations', 'admin.nav_groups.operations', '↻', [
                $this->module($user, $channels, 'b2c', 'orders', 'admin.b2c_workspace.modules.orders', 'orders.view'),
                $this->module($user, $channels, 'b2c', 'drivers', 'admin.b2c_workspace.modules.drivers', 'drivers.b2c.view'),
                $this->module($user, $channels, 'b2b', 'orders', 'admin.b2b_workspace.modules.orders', 'orders.view'),
                $this->module($user, $channels, 'b2b', 'drivers', 'admin.b2b_workspace.modules.drivers', 'drivers.b2b.view'),
            ]),
            $this->group('catalog', 'admin.nav_groups.catalog', '▦', [
                $this->module($user, $channels, 'b2c', 'products', 'admin.b2c_workspace.modules.products', 'catalog.view'),
                $this->module($user, $channels, 'b2c', 'inventory', 'admin.b2c_workspace.modules.inventory', 'inventory.view'),
                $this->module($user, $channels, 'b2b', 'products', 'admin.b2b_workspace.modules.products', 'catalog.view'),
                $this->module($user, $channels, 'b2b', 'pricing', 'admin.b2b_workspace.modules.pricing', 'b2b.pricing.view'),
            ]),
            $this->group('accounts', 'admin.nav_groups.accounts', '◎', [
                $this->module($user, $channels, 'b2c', 'customers', 'admin.b2c_workspace.modules.customers', 'customers.view'),
                $this->module($user, $channels, 'b2b', 'clients', 'admin.b2b_workspace.modules.clients', 'b2b.accounts.view'),
            ]),
            $this->group('stores', 'admin.nav_groups.stores', '⌂', [
                $this->module($user, $channels, 'b2b', 'stores', 'admin.b2b_workspace.modules.stores', 'stores.view'),
                $this->module($user, $channels, 'b2c', 'storefront', 'admin.b2c_workspace.modules.storefront', 'stores.view'),
            ]),
            $this->group('marketing', 'admin.nav_groups.marketing', '✦', [
                $this->module($user, $channels, 'b2c', 'promotions', 'admin.b2c_workspace.modules.promotions', 'promotions.view'),
                $this->module($user, $channels, 'b2c', 'content', 'admin.b2c_workspace.modules.content', 'promotions.view'),
                $this->routeItem($user, 'notifications', 'notifications.title', 'admin.notifications.index', 'notifications.view'),
            ]),
            $this->group('analytics', 'admin.nav_groups.analytics', '▥', [
                $this->module($user, $channels, 'b2c', 'reports', 'admin.b2c_workspace.modules.reports', 'reports.view'),
                $this->module($user, $channels, 'b2b', 'reports', 'admin.b2b_workspace.modules.reports', 'reports.view'),
            ]),
            $this->group('administration', 'admin.nav_groups.administration', '⚙', [
                $this->module($user, $channels, 'b2c', 'settings', 'admin.b2c_workspace.modules.settings', null),
                $this->module($user, $channels, 'b2b', 'settings', 'admin.b2b_workspace.modules.settings', null),
                $this->routeItem($user, 'security', 'admin.security_center', 'admin.security.index', 'security.view'),
                $this->routeItem($user, 'translations', 'admin.translation_center', 'admin.translations.index', 'translations.manage'),
                $this->routeItemAny($user, 'mobile_settings', 'mobile_settings.title', 'admin.mobile-settings.index', ['mobile_settings.manage', 'push_settings.manage', 'push_settings.test']),
                $this->routeItem($user, 'app_versions', 'admin.app_versions', 'admin.app-versions.index', 'platform.manage'),
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
            ->whereIn('roles.scope', ['global', 'both'])
            ->whereIn('roles.code', $globalRoles)
            ->exists()) {
            return true;
        }

        $storeRoles = array_values(array_filter((array) ($definition['store_roles'] ?? []), 'is_string'));

        if ($storeRoles === []) {
            return false;
        }

        return $user->storeRoleAssignments()->whereHas('role', fn ($query) => $query
            ->where('roles.is_active', true)
            ->whereIn('roles.scope', ['store', 'both'])
            ->whereIn('roles.code', $storeRoles))->exists();
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

        return $user->storeRoleAssignments()->whereHas('role', fn ($query) => $query
            ->where('roles.is_active', true)
            ->whereIn('roles.scope', ['store', 'both'])
            ->whereIn('roles.code', $storeRoles)
            ->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.code', $permission)))->exists();
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
