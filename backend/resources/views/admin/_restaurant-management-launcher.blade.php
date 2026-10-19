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

    $restaurantOperationsTab = (string) request()->query('tab', '');
    $restaurantQrGate = collect($restaurantSetupStatus['gates'] ?? [])->firstWhere('key', 'qr');
    $restaurantQrRequired = (bool) ($restaurantQrGate['required'] ?? false);
    $restaurantSettingsUrl = route('admin.smart-ordering.index', $restaurantWorkspaceQuery);
    $restaurantSetupUrls = [
        'identity' => $restaurantSettingsUrl.'#settings-launch',
        'channels' => $restaurantSettingsUrl.'#settings-modules',
        'operations' => $restaurantOperationsUrl('branches'),
        'menu' => route('admin.smart-ordering.menu.index', [...$restaurantWorkspaceQuery, 'setup' => 'menu']),
        'entry' => $restaurantQrRequired && $restaurantCanQr
            ? route('admin.smart-ordering.qr.index', $restaurantWorkspaceQuery)
            : $restaurantSettingsUrl.'#settings-launch',
        'review' => $restaurantSettingsUrl.'#settings-launch',
    ];
    $restaurantPrimaryTasks = [
        ['key'=>'overview','href'=>route('admin.smart-ordering.index',$restaurantWorkspaceQuery),'visible'=>true,'active'=>request()->routeIs('admin.smart-ordering.index')],
        ['key'=>'customer_orders','href'=>$restaurantOperationsUrl('orders'),'visible'=>$restaurantCanOrders,'active'=>request()->routeIs('admin.smart-ordering.operations.*') && in_array($restaurantOperationsTab, ['', 'orders'], true)],
        ['key'=>'operations','href'=>route('admin.smart-ordering.operations.index',$restaurantWorkspaceQuery),'visible'=>$restaurantCanOrders,'active'=>request()->routeIs('admin.smart-ordering.operations.*') && ! in_array($restaurantOperationsTab, ['', 'orders'], true)],
        ['key'=>'menu','href'=>route('admin.smart-ordering.menu.index',[...$restaurantWorkspaceQuery,'setup'=>'menu']),'visible'=>$restaurantCanManage,'active'=>request()->routeIs('admin.smart-ordering.menu.*')],
        ['key'=>'wholesale_purchases','href'=>$restaurantWholesalePurchasesUrl,'visible'=>$restaurantCanOrders && $restaurantWholesalePurchasesUrl,'active'=>request()->routeIs('admin.restaurant.wholesale-purchases.*')],
        ['key'=>'settings','href'=>route('admin.smart-ordering.index',$restaurantWorkspaceQuery).'#settings-site','visible'=>true,'active'=>false],
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
            ['key' => 'menu', 'href' => route('admin.smart-ordering.menu.index', [...$restaurantWorkspaceQuery, 'setup' => 'menu']), 'visible' => $restaurantCanManage],
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
.restaurant-setup-shell{margin:12px 0 14px;padding:12px;border:1px solid var(--foodex-border);border-radius:14px;background:linear-gradient(180deg,var(--foodex-surface),var(--foodex-background))}
.restaurant-setup-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.restaurant-setup-head h2{margin:0;font-size:.9rem}.restaurant-setup-head p{margin:3px 0 0;color:var(--foodex-muted);font-size:.72rem}
.restaurant-setup-steps{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:7px}
.restaurant-setup-step{min-width:0;display:grid;grid-template-columns:28px minmax(0,1fr);gap:8px;align-items:center;min-height:58px;padding:8px;border:1px solid var(--foodex-border);border-radius:11px;background:var(--foodex-surface);color:inherit;text-decoration:none}
.restaurant-setup-step-number{width:28px;height:28px;border-radius:999px;display:grid;place-items:center;background:var(--foodex-background);font-size:.72rem;font-weight:900}
.restaurant-setup-step strong{display:block;font-size:.72rem;line-height:1.25}.restaurant-setup-step small{display:block;margin-top:2px;color:var(--foodex-muted);font-size:.61rem;line-height:1.25}.restaurant-setup-step-rule{font-weight:800}.restaurant-setup-step.is-locked .restaurant-setup-step-rule{color:var(--foodex-danger-text)}.restaurant-setup-step.is-current .restaurant-setup-step-rule{color:var(--foodex-green-dark)}
.restaurant-setup-step.is-current{border-color:var(--foodex-action-green);box-shadow:0 0 0 2px rgba(21,138,58,.08)}.restaurant-setup-step.is-current .restaurant-setup-step-number{background:var(--foodex-action-green);color:#fff}
.restaurant-setup-step.is-complete{background:var(--foodex-green-soft);border-color:var(--foodex-palette-bedbc5)}.restaurant-setup-step.is-complete .restaurant-setup-step-number{background:var(--foodex-action-green);color:#fff}
.restaurant-setup-step.is-locked{opacity:.55;cursor:not-allowed}.restaurant-setup-step.is-locked .restaurant-setup-step-number{background:var(--foodex-border)}
a.restaurant-setup-step:hover,a.restaurant-setup-step:focus-visible{border-color:var(--foodex-action-green);outline:none}
.restaurant-primary-tasks{display:flex;align-items:stretch;gap:8px;flex-wrap:wrap;margin:12px 0 8px;padding:8px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface)}
.restaurant-primary-task{display:flex;align-items:center;justify-content:center;min-height:42px;padding:9px 15px;border:1px solid transparent;border-radius:10px;background:transparent;color:var(--foodex-ink);text-decoration:none;font-weight:800;text-align:center;white-space:nowrap;transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease}
.restaurant-primary-task:hover,.restaurant-primary-task:focus-visible{background:var(--foodex-green-soft);border-color:var(--foodex-border);color:var(--foodex-green-dark);outline:none}
.restaurant-primary-task.is-active{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff;box-shadow:0 6px 16px rgba(21,138,58,.15)}
.restaurant-workspace-launcher{margin:0 0 18px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface);overflow:hidden}
.restaurant-workspace-launcher>summary{cursor:pointer;list-style:none;display:grid;grid-template-columns:minmax(0,1fr) auto 24px;align-items:center;gap:12px;min-height:48px;padding:10px 14px;font-weight:800}
.restaurant-workspace-launcher>summary::-webkit-details-marker{display:none}
.restaurant-workspace-launcher>summary::after{content:"+";font-size:1.15rem;text-align:center;color:var(--foodex-green-dark)}
.restaurant-workspace-launcher[open]>summary::after{content:"−"}
.restaurant-workspace-launcher>summary small{font-size:.72rem;font-weight:600;color:var(--foodex-muted)}
.restaurant-workspace-groups{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;padding:2px 12px 12px}
.restaurant-workspace-group{min-width:0;padding:10px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}
.restaurant-workspace-group h3{font-size:.78rem;letter-spacing:.02em;color:var(--foodex-green-dark);margin:0 0 8px}
.restaurant-workspace-links{display:grid;gap:3px}
.restaurant-workspace-link{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:38px;padding:7px 8px;border-radius:9px;text-decoration:none;color:inherit;font-size:.84rem;font-weight:700}
a.restaurant-workspace-link:hover,a.restaurant-workspace-link:focus-visible{background:var(--foodex-green-soft);color:var(--foodex-green-dark);outline:none}
.restaurant-workspace-link.is-partial{border:1px dashed var(--foodex-border)}
.restaurant-workspace-link.is-unavailable{color:var(--foodex-muted);cursor:default}
.restaurant-workspace-state{font-size:.66rem;font-weight:800;padding:3px 6px;border-radius:999px;background:var(--foodex-orange-soft);color:var(--foodex-ink)}
.restaurant-primary-tasks.is-compact{margin-block:8px}
.restaurant-primary-tasks.is-compact .restaurant-primary-task{min-height:40px;padding:8px 13px}
.restaurant-workspace-launcher.is-compact{margin-bottom:12px}
.restaurant-workspace-launcher.is-compact>summary{min-height:44px}
@media(max-width:1280px){.restaurant-setup-steps{grid-template-columns:repeat(3,minmax(0,1fr))}.restaurant-workspace-groups{grid-template-columns:repeat(3,minmax(0,1fr))}.restaurant-primary-tasks{overflow-x:auto;flex-wrap:nowrap;scrollbar-width:thin}}
@media(max-width:1024px){.restaurant-primary-task{min-height:44px}.restaurant-workspace-launcher>summary{grid-template-columns:minmax(0,1fr) 24px}.restaurant-workspace-launcher>summary small{display:none}}
@media(max-width:768px){.restaurant-setup-steps{grid-template-columns:1fr 1fr}.restaurant-workspace-groups{grid-template-columns:1fr 1fr}.restaurant-primary-tasks{margin-top:8px}}
@media(max-width:430px){.restaurant-setup-steps{grid-template-columns:1fr}.restaurant-primary-task{font-size:.82rem;padding:8px 11px}.restaurant-workspace-groups{grid-template-columns:1fr}}
@media(max-width:390px){.restaurant-primary-task{min-height:42px}}
@media(max-width:360px){.restaurant-workspace-groups{grid-template-columns:1fr}}
</style>

@if(! $restaurantLaunched)
<section class="restaurant-setup-shell" data-restaurant-progressive-setup>
    <div class="restaurant-setup-head">
        <div>
            <h2>{{ __('restaurant_onboarding.flow.title') }}</h2>
            <p>{{ __('restaurant_onboarding.flow.description') }}</p>
        </div>
        <span class="foodex-status-badge" data-state="warning">{{ (int)($restaurantSetupStatus['completion_percent'] ?? 0) }}%</span>
    </div>
    <nav class="restaurant-setup-steps" aria-label="{{ __('restaurant_onboarding.flow.title') }}">
        @foreach(($restaurantSetupStatus['setup_flow'] ?? []) as $step)
            @php($stepUrl = $restaurantSetupUrls[$step['key']] ?? null)
            @php($stepState = (string)($step['state'] ?? 'locked'))
            @php($previousStep = $loop->first ? null : (($restaurantSetupStatus['setup_flow'] ?? [])[$loop->index - 1] ?? null))
            @php($previousTitle = is_array($previousStep) ? __('restaurant_onboarding.flow.steps.'.$previousStep['key'].'.title') : null)
            @if(($step['available'] ?? false) && $stepUrl)
                <a class="restaurant-setup-step is-{{ $stepState }}" href="{{ $stepUrl }}" data-restaurant-setup-step="{{ $step['key'] }}" @if($stepState==='current') aria-current="step" @endif>
            @else
                <span class="restaurant-setup-step is-locked" data-restaurant-setup-step="{{ $step['key'] }}" aria-disabled="true">
            @endif
                    <span class="restaurant-setup-step-number">{{ $step['number'] }}</span>
                    <span>
                        <strong>{{ __('restaurant_onboarding.flow.steps.'.$step['key'].'.title') }}</strong>
                        <small>{{ $stepState === 'complete' ? __('restaurant_onboarding.flow.complete') : ($stepState === 'current' ? __('restaurant_onboarding.flow.current') : __('restaurant_onboarding.flow.locked')) }}</small>
                        <small class="restaurant-setup-step-rule">
                            @if($loop->first)
                                {{ __('restaurant_onboarding.flow.start_here') }}
                            @elseif($stepState === 'locked')
                                {{ __('restaurant_onboarding.flow.blocked_by', ['step' => $previousTitle]) }}
                            @else
                                {{ __('restaurant_onboarding.flow.after', ['step' => $previousTitle]) }}
                            @endif
                        </small>
                    </span>
            @if(($step['available'] ?? false) && $stepUrl)
                </a>
            @else
                </span>
            @endif
        @endforeach
    </nav>
</section>
@else
<nav class="restaurant-primary-tasks {{ ($restaurantManagementCompact ?? false) ? 'is-compact' : '' }}" data-restaurant-task-first-nav aria-label="{{ __('smart_ordering.workspace.tasks_label') }}">
    @foreach($restaurantPrimaryTasks as $task)
        @if($task['visible'] && $task['href'])
            <a class="restaurant-primary-task {{ ($task['active'] ?? false) ? 'is-active' : '' }}"
                data-restaurant-primary-task="{{ $task['key'] }}"
                href="{{ $task['href'] }}"
                @if($task['active'] ?? false) aria-current="page" @endif>
                {{ __('smart_ordering.workspace.tasks.'.$task['key']) }}
            </a>
        @endif
    @endforeach
</nav>

<details class="restaurant-workspace-launcher {{ ($restaurantManagementCompact ?? false) ? 'is-compact' : '' }}" data-restaurant-management-workspace>
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

@endif
