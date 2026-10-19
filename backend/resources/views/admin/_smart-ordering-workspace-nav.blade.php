@php
    $restaurantActor = $user ?? auth()->user();
    $restaurantNav = app(\App\Support\AdminNavigation::class);
    $restaurantSiteKey = isset($site) ? (string) ($site->public_id ?: $site->slug) : trim((string) request()->query('site', ''));
    $restaurantStoreId = isset($site) ? (int) $site->store_id : (int) request()->integer('store_id');
    if ($restaurantStoreId <= 0 && $restaurantSiteKey !== '') {
        $restaurantStoreId = (int) (\Illuminate\Support\Facades\DB::table('smart_ordering_sites')
            ->where('public_id', $restaurantSiteKey)
            ->orWhere('slug', $restaurantSiteKey)
            ->value('store_id') ?? 0);
    }
    if ($restaurantStoreId <= 0 && $restaurantActor) {
        $restaurantStoreId = (int) (app(\App\Support\TenantContextResolver::class)->retailStoreIds($restaurantActor)[0] ?? 0);
    }
    if ($restaurantSiteKey === '' && $restaurantStoreId > 0) {
        $restaurantSiteKey = (string) (\Illuminate\Support\Facades\DB::table('smart_ordering_sites')
            ->where('store_id', $restaurantStoreId)
            ->value('public_id') ?? '');
    }
    $restaurantSupport = isset($supportAccess)
        ? (bool) $supportAccess
        : (bool) $restaurantActor?->hasRole('SUPER_ADMIN');
    $restaurantStoreParams = array_filter([
        'store_id' => $restaurantStoreId > 0 ? $restaurantStoreId : null,
        'support_access' => $restaurantSupport ? 1 : null,
    ], static fn ($value) => $value !== null);
    $restaurantSupportParams = $restaurantSupport ? ['support_access' => 1] : [];
    $restaurantSiteId = isset($site) ? (int) $site->getKey() : 0;
    if ($restaurantSiteId <= 0 && $restaurantStoreId > 0) {
        $restaurantSiteId = (int) (\Illuminate\Support\Facades\DB::table('smart_ordering_sites')
            ->where('store_id', $restaurantStoreId)
            ->value('id') ?? 0);
    }
    $restaurantSiteParams = $restaurantSiteId > 0
        ? ['site' => $restaurantSiteId, ...$restaurantSupportParams]
        : null;
    $restaurantCanView = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('smart_ordering.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.view'));
    $restaurantCanManage = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('smart_ordering.manage', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.manage'));
    $restaurantCanOrders = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'orders.view');
    $restaurantCanQr = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.qr.view');
    $restaurantCanAnalytics = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.analytics.view');
    $restaurantCanInventory = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('inventory.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'inventory.view'));
    $restaurantCanCustomers = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('customers.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'customers.view'));
    $restaurantCanPromotions = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('promotions.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'promotions.view'));
    $restaurantCanFinance = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('finance.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'finance.view'));
    $restaurantCanReports = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('reports.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'reports.view'));
    $restaurantAppearanceParams = array_filter([
        'site' => $restaurantSiteKey !== '' ? $restaurantSiteKey : null,
        'store_id' => $restaurantStoreId > 0 ? $restaurantStoreId : null,
        'support_access' => $restaurantSupport ? 1 : null,
    ], static fn ($value) => $value !== null);
    $restaurantSupplyEnabled = false;
    if ($restaurantSiteId > 0) {
        $restaurantSupplyEnabled = isset($entitlements)
            ? (bool) ($entitlements['foodex_supply_intelligence'] ?? false)
            : \Illuminate\Support\Facades\DB::table('smart_ordering_entitlements')
                ->where('site_id', $restaurantSiteId)
                ->where('scope_key', 'site')
                ->where('module', 'foodex_supply_intelligence')
                ->where('enabled', true)
                ->exists();
    }
@endphp

@if($restaurantCanView)
    <section class="restaurant-saas-navigation-shell"
        data-restaurant-saas-workspace-nav
        data-restaurant-saas-context-nav
        aria-label="{{ __('smart_ordering.workspace.title') }}">
        @include('admin._restaurant-management-launcher')
    </section>
@endif
