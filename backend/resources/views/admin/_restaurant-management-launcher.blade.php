@php
    $restaurantWorkspaceQuery = array_filter([
        'store_id' => $restaurantStoreId > 0 ? $restaurantStoreId : null,
        'support_access' => $restaurantSupport ? 1 : null,
    ], static fn ($value) => $value !== null);
    $restaurantOperationsUrl = static fn (string $tab, ?string $anchor = null): string =>
        route('admin.smart-ordering.operations.index', [...$restaurantWorkspaceQuery, 'tab' => $tab]).($anchor ? '#'.$anchor : '');
    $restaurantModuleUrl = static fn (string $module): string =>
        route('admin.b2c.module', [...$restaurantWorkspaceQuery, 'module' => $module]);
    $restaurantWholesalePurchasesUrl = $restaurantStoreId > 0
        ? route('admin.restaurant.wholesale-purchases.index', ['retail_store_id' => $restaurantStoreId])
        : null;

    $restaurantPrimaryTasks = [
        ['key'=>'customer_orders','href'=>$restaurantOperationsUrl('orders'),'visible'=>$restaurantCanOrders],
        ['key'=>'menu','href'=>route('admin.smart-ordering.menu.index',$restaurantWorkspaceQuery),'visible'=>$restaurantCanManage],
        ['key'=>'operations','href'=>route('admin.smart-ordering.operations.index',$restaurantWorkspaceQuery),'visible'=>$restaurantCanOrders],
        ['key'=>'wholesale_purchases','href'=>$restaurantWholesalePurchasesUrl,'visible'=>$restaurantCanOrders && $restaurantWholesalePurchasesUrl],
        ['key'=>'settings','href'=>route('admin.smart-ordering.index',$restaurantWorkspaceQuery),'visible'=>true],
    ];

    $restaurantWorkspaceGroups = [
        'service' => [
            ['key' => 'overview', 'href' => route('admin.smart-ordering.index', $restaurantWorkspaceQuery), 'visible' => true],
            ['key' => 'orders', 'href' => $restaurantOperationsUrl('orders'), 'visible' => $restaurantCanOrders],
            ['key' => 'kds', 'href' => $restaurantOperationsUrl('kitchen', 'restaurant-kds-live'), 'visible' => $restaurantCanOrders],
            ['key' => 'kitchen_config', 'href' => $restaurantOperationsUrl('kitchen', 'restaurant-kitchen-config'), 'visible' => $restaurantCanOrders],
            ['key' => 'floor', 'href' => $restaurantOperationsUrl('tables'), 'visible' => $restaurantCanOrders],
            ['key' => 'reservations', 'href' => $restaurantOperationsUrl('reservations'), 'visible' => $restaurantCanOrders],
            ['key' => 'waitlist', 'href' => $restaurantOperationsUrl('waitlist'), 'visible' => $restaurantCanOrders],
            ['key' => 'guest_book', 'href' => $restaurantOperationsUrl('guest_book'), 'visible' => $restaurantCanOrders],
            ['key' => 'pos', 'href' => null, 'visible' => $restaurantCanOrders, 'state' => 'partial'],
            ['key' => 'dispatch', 'href' => $restaurantOperationsUrl('orders'), 'visible' => $restaurantCanOrders, 'state' => 'partial'],
        ],
        'menu_growth' => [
            ['key' => 'menu', 'href' => route('admin.smart-ordering.menu.index', $restaurantWorkspaceQuery), 'visible' => $restaurantCanManage],
            ['key' => 'appearance', 'href' => route('admin.smart-ordering.appearance.index', $restaurantAppearanceParams), 'visible' => true],
            ['key' => 'qr', 'href' => route('admin.smart-ordering.qr.index', $restaurantWorkspaceQuery), 'visible' => $restaurantCanQr],
            ['key' => 'customers', 'href' => $restaurantSiteParams ? route('admin.smart-ordering.growth.index', $restaurantSiteParams) : null, 'visible' => $restaurantCanCustomers && $restaurantSiteParams],
            ['key' => 'offers_loyalty', 'href' => $restaurantSiteParams ? route('admin.smart-ordering.growth.index', $restaurantSiteParams) : null, 'visible' => $restaurantCanPromotions && $restaurantSiteParams],
        ],
        'people_supply' => [
            ['key' => 'staff', 'href' => $restaurantOperationsUrl('workforce'), 'visible' => $restaurantCanOrders],
            ['key' => 'inventory', 'href' => $restaurantCanInventory && $restaurantStoreId > 0 ? $restaurantModuleUrl('inventory') : null, 'visible' => $restaurantCanInventory && $restaurantStoreId > 0],
            ['key' => 'wholesale_purchases', 'href' => $restaurantStoreId > 0 ? route('admin.restaurant.wholesale-purchases.index', ['retail_store_id' => $restaurantStoreId]) : null, 'visible' => $restaurantStoreId > 0],
            ['key' => 'recipes_cost', 'href' => $restaurantSupplyEnabled && $restaurantSiteParams ? route('admin.smart-ordering.supply.index', $restaurantSiteParams) : null, 'visible' => $restaurantSupplyEnabled && $restaurantSiteParams],
            ['key' => 'purchasing', 'href' => $restaurantSupplyEnabled && $restaurantSiteParams ? route('admin.smart-ordering.supply.index', $restaurantSiteParams) : null, 'visible' => $restaurantSupplyEnabled && $restaurantSiteParams, 'state' => 'partial'],
        ],
        'finance_insights' => [
            ['key' => 'payments', 'href' => $restaurantOperationsUrl('payments'), 'visible' => $restaurantCanFinance],
            ['key' => 'finance', 'href' => $restaurantCanFinance && $restaurantStoreId > 0 ? $restaurantModuleUrl('finance') : null, 'visible' => $restaurantCanFinance && $restaurantStoreId > 0],
            ['key' => 'analytics', 'href' => route('admin.smart-ordering.analytics.index', $restaurantWorkspaceQuery), 'visible' => $restaurantCanAnalytics],
            ['key' => 'reports', 'href' => $restaurantCanReports ? route('admin.reports.index', array_filter(['store_id' => $restaurantStoreId ?: null, 'channel' => 'b2c'])) : null, 'visible' => $restaurantCanReports],
        ],
        'connections_settings' => [
            ['key' => 'integrations', 'href' => $restaurantSiteParams ? route('admin.smart-ordering.integrations.index', $restaurantSiteParams) : null, 'visible' => (bool) $restaurantSiteParams],
            ['key' => 'settings', 'href' => route('admin.smart-ordering.index', $restaurantWorkspaceQuery), 'visible' => true],
        ],
    ];
@endphp

<style>
.restaurant-primary-tasks{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin:12px 0}
.restaurant-primary-task{display:flex;align-items:center;justify-content:center;min-height:46px;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);color:inherit;text-decoration:none;font-weight:800;text-align:center}
.restaurant-primary-task:hover,.restaurant-primary-task:focus-visible{background:var(--foodex-green-soft);outline:none}
.restaurant-workspace-launcher{margin:12px 0 18px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface);overflow:hidden}
.restaurant-workspace-launcher>summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px;padding:10px 14px;font-weight:800}
.restaurant-workspace-launcher>summary::-webkit-details-marker{display:none}
.restaurant-workspace-launcher>summary::after{content:"+";font-size:1.15rem}
.restaurant-workspace-launcher[open]>summary::after{content:"−"}
.restaurant-workspace-groups{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:0 12px 12px}
.restaurant-workspace-group{min-width:0;padding:10px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
.restaurant-workspace-group h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--foodex-muted);margin:0 0 8px}
.restaurant-workspace-links{display:grid;gap:4px}
.restaurant-workspace-link{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:38px;padding:7px 8px;border-radius:9px;text-decoration:none;color:inherit;font-size:.86rem;font-weight:700}
a.restaurant-workspace-link:hover,a.restaurant-workspace-link:focus-visible{background:var(--foodex-green-soft);outline:none}
.restaurant-workspace-link.is-partial{border:1px dashed var(--foodex-border)}
.restaurant-workspace-link.is-unavailable{color:var(--foodex-muted);cursor:default}
.restaurant-workspace-state{font-size:.68rem;font-weight:800;padding:3px 6px;border-radius:999px;background:var(--foodex-orange-soft);color:var(--foodex-ink)}
@media(max-width:1280px){.restaurant-workspace-groups{grid-template-columns:repeat(3,minmax(0,1fr))}.restaurant-primary-tasks{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1024px){.restaurant-primary-task{min-height:44px}}
@media(max-width:768px){.restaurant-workspace-groups{grid-template-columns:1fr 1fr}.restaurant-primary-tasks{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:430px){.restaurant-primary-tasks{grid-template-columns:1fr 1fr}.restaurant-primary-task{font-size:.82rem;padding:8px}}
@media(max-width:390px){.restaurant-primary-task{min-height:42px}}
@media(max-width:360px){.restaurant-workspace-groups,.restaurant-primary-tasks{grid-template-columns:1fr}}
</style>

<nav class="restaurant-primary-tasks" data-restaurant-task-first-nav aria-label="{{ __('smart_ordering.workspace.tasks_label') }}">
    @foreach($restaurantPrimaryTasks as $task)
        @if($task['visible'] && $task['href'])
            <a class="restaurant-primary-task" data-restaurant-primary-task="{{ $task['key'] }}" href="{{ $task['href'] }}">{{ __('smart_ordering.workspace.tasks.'.$task['key']) }}</a>
        @endif
    @endforeach
</nav>

<details class="restaurant-workspace-launcher" data-restaurant-management-workspace>
    <summary>
        <span>{{ __('smart_ordering.workspace.title') }}</span>
        <small>{{ __('smart_ordering.workspace.help') }}</small>
    </summary>
    <div class="restaurant-workspace-groups">
        @foreach($restaurantWorkspaceGroups as $group => $items)
            @php($visibleItems = collect($items)->filter(fn (array $item): bool => (bool) ($item['visible'] ?? false)))
            @if($visibleItems->isNotEmpty())
                <section class="restaurant-workspace-group" data-workspace-group="{{ $group }}">
                    <h3>{{ __('smart_ordering.workspace.groups.'.$group) }}</h3>
                    <div class="restaurant-workspace-links">
                        @foreach($visibleItems as $item)
                            @php($state = $item['state'] ?? null)
                            @if($item['href'])
                                <a class="restaurant-workspace-link {{ $state ? 'is-'.$state : '' }}" href="{{ $item['href'] }}" data-workspace-item="{{ $item['key'] }}">
                                    <span>{{ __('smart_ordering.workspace.items.'.$item['key']) }}</span>
                                    @if($state)<span class="restaurant-workspace-state">{{ __('smart_ordering.workspace.states.'.$state) }}</span>@endif
                                </a>
                            @else
                                <span class="restaurant-workspace-link is-unavailable {{ $state ? 'is-'.$state : '' }}" data-workspace-item="{{ $item['key'] }}" role="status">
                                    <span>{{ __('smart_ordering.workspace.items.'.$item['key']) }}</span>
                                    <span class="restaurant-workspace-state">{{ __('smart_ordering.workspace.states.'.$state) }}</span>
                                </span>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </div>
    <p class="foodex-state" style="margin:0 12px 12px">{{ __('smart_ordering.workspace.partial_note') }}</p>
</details>
