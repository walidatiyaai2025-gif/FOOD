@php
    $restaurantActor = $user ?? auth()->user();
    $restaurantNav = app(\App\Support\AdminNavigation::class);
    $restaurantStoreId = isset($site) ? (int) $site->store_id : (int) request()->integer('store_id');
    $restaurantSupport = isset($supportAccess)
        ? (bool) $supportAccess
        : (bool) ($restaurantActor?->hasRole('SUPER_ADMIN') && request()->boolean('support_access'));
    $restaurantStoreParams = array_filter([
        'store_id' => $restaurantStoreId > 0 ? $restaurantStoreId : null,
        'support_access' => $restaurantSupport ? 1 : null,
    ], static fn ($value) => $value !== null);
    $restaurantSupportParams = $restaurantSupport ? ['support_access' => 1] : [];
    $restaurantSiteParams = isset($site) ? [$site, ...$restaurantSupportParams] : null;
    $restaurantCanView = $restaurantActor
        && ($restaurantStoreId > 0
            ? $restaurantActor->hasPermission('smart_ordering.view', $restaurantStoreId)
            : $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.view'));
    $restaurantCanOrders = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'orders.view');
    $restaurantCanQr = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.qr.view');
    $restaurantCanAnalytics = $restaurantActor && $restaurantNav->hasPermissionInScope($restaurantActor, 'smart_ordering.analytics.view');
    $restaurantSiteKey = isset($site) ? (string) ($site->public_id ?: $site->slug) : trim((string) request()->query('site', ''));
    $restaurantAppearanceParams = array_filter([
        'site' => $restaurantSiteKey !== '' ? $restaurantSiteKey : null,
    ], static fn ($value) => $value !== null);
    $restaurantSupplyEnabled = false;
    if (isset($site)) {
        $restaurantSupplyEnabled = isset($entitlements)
            ? (bool) ($entitlements['foodex_supply_intelligence'] ?? false)
            : \Illuminate\Support\Facades\DB::table('smart_ordering_entitlements')
                ->where('site_id', $site->getKey())
                ->where('scope_key', 'site')
                ->where('module', 'foodex_supply_intelligence')
                ->where('enabled', true)
                ->exists();
    }
@endphp

<nav class="foodex-tabs" data-restaurant-saas-workspace-nav aria-label="{{ __('smart_ordering.nav_title') }}">
    @if($restaurantCanView)
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.index', $restaurantStoreParams) }}" @if(request()->routeIs('admin.smart-ordering.index')) aria-current="page" @endif>
            {{ __('smart_ordering.settings_tab') }}
        </a>
    @endif
    @if($restaurantCanOrders)
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.operations.index') }}" @if(request()->routeIs('admin.smart-ordering.operations.*')) aria-current="page" @endif>
            {{ __('smart_ordering.operations_tab') }}
        </a>
    @endif
    @if($restaurantCanView)
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.menu.index', $restaurantStoreParams) }}" @if(request()->routeIs('admin.smart-ordering.menu.*')) aria-current="page" @endif>
            {{ __('smart_ordering_menu.nav_title') }}
        </a>
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.appearance.index', $restaurantAppearanceParams) }}" @if(request()->routeIs('admin.smart-ordering.appearance.*')) aria-current="page" @endif>
            {{ __('smart_ordering.appearance.heading') }}
        </a>
    @endif
    @if($restaurantCanQr)
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.qr.index', $restaurantStoreParams) }}" @if(request()->routeIs('admin.smart-ordering.qr.*')) aria-current="page" @endif>
            {{ __('smart_ordering_qr.nav_title') }}
        </a>
    @endif
    @if($restaurantCanAnalytics)
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.analytics.index', $restaurantStoreParams) }}" @if(request()->routeIs('admin.smart-ordering.analytics.*')) aria-current="page" @endif>
            {{ __('smart_ordering.analytics.nav_title') }}
        </a>
    @endif
</nav>

@if($restaurantCanView && $restaurantSiteParams)
    <nav class="foodex-tabs" data-restaurant-saas-context-nav aria-label="{{ __('smart_ordering.title') }}">
        @if($restaurantSupplyEnabled)
            <a class="foodex-tab" href="{{ route('admin.smart-ordering.supply.index', $restaurantSiteParams) }}" @if(request()->routeIs('admin.smart-ordering.supply.*')) aria-current="page" @endif>
                {{ __('smart_ordering_supply.title') }}
            </a>
        @endif
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.integrations.index', $restaurantSiteParams) }}" @if(request()->routeIs('admin.smart-ordering.integrations.*')) aria-current="page" @endif>
            {{ __('restaurant_integrations.title') }}
        </a>
        <a class="foodex-tab" href="{{ route('admin.smart-ordering.growth.index', $restaurantSiteParams) }}" @if(request()->routeIs('admin.smart-ordering.growth.*')) aria-current="page" @endif>
            {{ __('restaurant_growth.title') }}
        </a>
    </nav>
@endif
