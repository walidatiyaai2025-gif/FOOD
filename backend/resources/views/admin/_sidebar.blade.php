@include('admin._brand')
@php
    $ar = app()->getLocale() === 'ar';
    $secondaryLocale = $ar ? 'en' : 'ar';
    $containsContext = static function (array $node, string $context) use (&$containsContext): bool {
        if (($node['key'] ?? '') === $context) {
            return true;
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if ($containsContext($child, $context)) {
                return true;
            }
        }

        return false;
    };
    // Counts are derived only from the final already-authorized navigation.
    $countAuthorizedChildren = static function (array $nodes) use (&$countAuthorizedChildren): int {
        $count = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['route'])) {
                $count++;
            }
            $count += $countAuthorizedChildren((array) ($node['children'] ?? []));
        }

        return $count;
    };
    $childIcon = static function (string $key): string {
        return match (true) {
            str_contains($key, 'dashboard') => 'home',
            str_contains($key, 'order') => 'orders',
            str_contains($key, 'product'), str_contains($key, 'catalog'), str_contains($key, 'lookup') => 'products',
            str_contains($key, 'inventor') => 'inventory',
            str_contains($key, 'customer'), str_contains($key, 'client'), $key === 'profile' => 'customers',
            str_contains($key, 'driver'), str_contains($key, 'delivery') => 'delivery',
            str_contains($key, 'store') => 'storefront',
            str_contains($key, 'promotion') => 'promotions',
            str_contains($key, 'notification') => 'bell',
            str_contains($key, 'report'), str_contains($key, 'finance') => 'reports',
            str_contains($key, 'mobile'), str_contains($key, 'version') => 'mobile',
            str_contains($key, 'inspector') => 'inspector',
            str_contains($key, 'translation'), str_contains($key, 'content') => 'content',
            default => 'settings',
        };
    };
@endphp
<style id="foodex-authoritative-sidebar">
aside.foodex-sidebar-collapsed{padding:10px!important;width:82px!important;min-width:82px!important;overflow-x:hidden}
aside.foodex-sidebar-expanded{width:var(--foodex-sidebar-width)!important}
.foodex-sidebar-shell{min-height:100%;display:flex;flex-direction:column;gap:10px}
.foodex-sidebar-head{display:flex;align-items:center;justify-content:space-between;gap:8px}
.foodex-sidebar-brand{min-height:48px;display:flex;align-items:center;gap:9px;color:var(--foodex-green-dark);font-weight:800;text-decoration:none;white-space:nowrap;overflow:hidden}
.foodex-sidebar-brand-mark{width:42px;height:34px;flex:0 0 42px;border-radius:8px;display:grid;place-items:center;background:#fff;overflow:hidden;border:1px solid var(--foodex-border)}\n.foodex-sidebar-brand-mark img{width:100%;height:100%;object-fit:contain}
.foodex-sidebar-toggle{width:40px;height:40px;flex:0 0 40px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);cursor:pointer;font-size:18px}
.foodex-sidebar-search{position:relative}.foodex-sidebar-search input{width:100%;padding-inline-start:38px}
.foodex-nav-link[hidden],.foodex-nav-subchildren[hidden],.foodex-nav-group[hidden],.foodex-sidebar-search-empty[hidden]{display:none!important}
.foodex-sidebar-search-empty{margin:4px 8px;padding:10px 12px;border:1px dashed var(--foodex-border);border-radius:10px;color:var(--foodex-muted);font-size:.82rem}.foodex-sidebar-search .foodex-svg-icon{position:absolute;inset-inline-start:11px;top:12px;width:18px;height:18px;color:var(--foodex-muted)}
.foodex-nav{display:grid;gap:6px}.foodex-nav-group{border:1px solid transparent;border-radius:12px}.foodex-nav-group summary{list-style:none;cursor:pointer;min-height:46px;display:grid;grid-template-columns:30px minmax(0,1fr) auto auto;align-items:center;gap:9px;padding:8px 10px;border-radius:11px;font-weight:800;color:var(--foodex-ink)}.foodex-nav-group-icon{display:grid;place-items:center;width:28px;height:28px;font-size:1.35rem;line-height:1}.foodex-nav-group summary::-webkit-details-marker{display:none}.foodex-nav-group summary:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-nav-group-title{display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-width:0;line-height:1.35}
.foodex-nav-group-status{display:inline-flex;align-items:center;max-width:100%;padding:2px 6px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.66rem;font-weight:700;line-height:1.2;white-space:nowrap}
.foodex-nav-group-status[data-group-status="approval_required"]{background:var(--foodex-orange-soft);color:var(--foodex-ink)}
.foodex-nav-group-status[data-group-status="exceptions"]{background:#fff1f2;color:#9f1239}
.foodex-nav-count{grid-column:3;display:inline-flex;align-items:center;justify-content:center;min-width:22px;padding:1px 5px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.7rem;line-height:1.35;font-variant-numeric:tabular-nums}
.foodex-nav-disclosure{grid-column:4;display:inline-flex;align-items:center;justify-self:end;gap:6px;min-width:28px;padding:5px 7px;border:1px solid var(--foodex-border);border-radius:8px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.7rem;font-weight:700;line-height:1.2;white-space:nowrap}
.foodex-nav-disclosure-collapse{display:none}
.foodex-nav-group[open] .foodex-nav-disclosure-expand{display:none}
.foodex-nav-group[open] .foodex-nav-disclosure-collapse{display:inline}
.foodex-nav-group summary:focus-visible{outline:2px solid var(--foodex-green);outline-offset:2px}
.foodex-nav-group summary:hover .foodex-nav-disclosure,.foodex-nav-group summary:focus-visible .foodex-nav-disclosure{border-color:var(--foodex-green);color:var(--foodex-green-dark)}
.foodex-nav-children>.foodex-nav-link{border-inline-start:2px solid transparent}
.foodex-nav-subchildren .foodex-nav-link{font-size:.94em}
@media(max-width:440px){.foodex-nav-disclosure-expand,.foodex-nav-disclosure-collapse{display:none!important}.foodex-nav-disclosure{padding:5px 8px}}
.foodex-nav-group[open]{border-color:var(--foodex-border);background:#fbfcfd}.foodex-nav-group[open] summary{color:var(--foodex-green-dark)}.foodex-nav-group[open] .foodex-nav-chevron{transform:rotate(180deg)}
/* Current page context is distinct from manually expanded groups; logical start supports RTL/LTR. */
.foodex-nav-group.is-current-group{border-color:var(--foodex-border);border-inline-start:3px solid var(--foodex-green);background:var(--foodex-green-soft)}.foodex-nav-group.is-current-group>summary{color:var(--foodex-green-dark)}
.foodex-nav-children{display:grid;gap:3px;padding:0 7px 7px}.foodex-nav-subchildren{display:grid;gap:2px;margin-inline-start:14px;padding-inline-start:8px;border-inline-start:1px dashed var(--foodex-border)}.foodex-nav-subchildren .foodex-nav-link{min-height:40px}.foodex-nav-link{min-height:46px;display:grid;grid-template-columns:28px minmax(0,1fr);align-items:center;gap:9px;padding:7px 9px;border-radius:10px;color:var(--foodex-ink);text-decoration:none}.foodex-nav-link>.foodex-svg-icon{width:24px;height:24px}.foodex-nav-link:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.foodex-nav-link.active{background:var(--foodex-action-green);color:#fff;box-shadow:0 8px 18px rgba(21,138,58,.14)}
.foodex-nav-label{display:block;min-width:0}.foodex-nav-label strong{display:block;font-size:.86rem;line-height:1.2}.foodex-nav-label small{display:block;color:var(--foodex-muted);font-size:.67rem;margin-top:2px}.foodex-nav-link.active small{color:rgba(255,255,255,.8)}
aside.foodex-sidebar-collapsed .foodex-sidebar-brand span:last-child,
aside.foodex-sidebar-collapsed .foodex-sidebar-search,
aside.foodex-sidebar-collapsed .foodex-nav-label,
aside.foodex-sidebar-collapsed .foodex-nav-chevron,
aside.foodex-sidebar-collapsed .foodex-nav-disclosure,
aside.foodex-sidebar-collapsed .foodex-nav-count,
aside.foodex-sidebar-collapsed .foodex-nav-group>summary span:nth-child(2){display:none!important}

aside.foodex-sidebar-collapsed .foodex-sidebar-head{justify-content:center;flex-wrap:wrap}
aside.foodex-sidebar-collapsed .foodex-sidebar-brand{justify-content:center;width:100%}
aside.foodex-sidebar-collapsed .foodex-sidebar-toggle{width:100%}
aside.foodex-sidebar-collapsed .foodex-nav-group{border:0;background:transparent}
aside.foodex-sidebar-collapsed .foodex-nav-group.is-current-group{border-inline-start:3px solid var(--foodex-green);background:var(--foodex-green-soft)}
aside.foodex-sidebar-collapsed .foodex-nav-group summary{grid-template-columns:1fr;place-items:center;padding:7px}
aside.foodex-sidebar-collapsed .foodex-nav-group:not([open]) .foodex-nav-children{display:none}
aside.foodex-sidebar-collapsed .foodex-nav-group[open] .foodex-nav-children{padding:3px 0}
aside.foodex-sidebar-collapsed .foodex-nav-link{grid-template-columns:1fr;place-items:center;padding:7px}
.foodex-admin-layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:minmax(0,1fr) 82px}
html[dir=ltr] .foodex-admin-layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:82px minmax(0,1fr)}
.layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:minmax(0,1fr) 82px}
html[dir=ltr] .layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:82px minmax(0,1fr)}
.dashboard-layout:has(>.dashboard-sidebar.foodex-sidebar-collapsed){grid-template-columns:minmax(0,1fr) 82px}
html[dir=ltr] .dashboard-layout:has(>.dashboard-sidebar.foodex-sidebar-collapsed){grid-template-columns:82px minmax(0,1fr)}
@media(max-width:1023px){aside.foodex-sidebar-collapsed,aside.foodex-sidebar-expanded{width:100%!important;min-width:0!important}.foodex-admin-layout:has(>.sidebar.foodex-sidebar-collapsed),html[dir=ltr] .foodex-admin-layout:has(>.sidebar.foodex-sidebar-collapsed),.layout:has(>.sidebar.foodex-sidebar-collapsed),html[dir=ltr] .layout:has(>.sidebar.foodex-sidebar-collapsed),.dashboard-layout:has(>.dashboard-sidebar.foodex-sidebar-collapsed),html[dir=ltr] .dashboard-layout:has(>.dashboard-sidebar.foodex-sidebar-collapsed){grid-template-columns:1fr}.foodex-sidebar-shell{min-height:auto}}

.foodex-mobile-web-bar,.foodex-mobile-nav-backdrop{display:none}
.foodex-mobile-web-brand{display:inline-flex;align-items:center;min-height:44px;color:var(--foodex-green-dark);font-weight:800;text-decoration:none}
.foodex-mobile-nav-launcher{width:44px;height:44px;display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);color:var(--foodex-green-dark);font:inherit;font-size:20px;cursor:pointer}
@media(max-width:767px){
    .foodex-mobile-web-bar{position:fixed;z-index:10120;inset-block-start:6px;inset-inline-start:8px;inset-inline-end:auto;width:44px;height:44px;display:block;padding:0;background:transparent;border:0;box-shadow:none;backdrop-filter:none;pointer-events:none}
    .foodex-mobile-web-bar>.foodex-mobile-nav-launcher{pointer-events:auto}
    .foodex-mobile-web-brand{display:none!important}
    .foodex-mobile-nav-backdrop{position:fixed;z-index:10090;inset:56px 0 0;display:none;width:100%;height:auto;border:0;background:rgba(15,23,42,.42);padding:0;cursor:pointer}
    body.foodex-mobile-nav-open{overflow:hidden}
    body.foodex-mobile-nav-open .foodex-mobile-nav-backdrop{display:block}
    aside.sidebar,aside.dashboard-sidebar,.shell-sidebar,
    aside.foodex-sidebar-collapsed,aside.foodex-sidebar-expanded{
        position:fixed!important;z-index:10100!important;inset-block-start:56px!important;inset-block-end:0!important;inset-inline-start:0!important;inset-inline-end:auto!important;
        width:min(88vw,340px)!important;min-width:0!important;max-width:340px!important;height:auto!important;min-height:0!important;max-height:none!important;
        overflow:auto!important;padding:12px!important;background:var(--foodex-surface)!important;border:0!important;border-inline-start:1px solid var(--foodex-border)!important;
        box-shadow:-18px 0 44px rgba(15,23,42,.16)!important;transform:translateX(105%);transition:transform .2s ease;overscroll-behavior:contain
    }
    html[dir=ltr] aside.sidebar,html[dir=ltr] aside.dashboard-sidebar,html[dir=ltr] .shell-sidebar,
    html[dir=ltr] aside.foodex-sidebar-collapsed,html[dir=ltr] aside.foodex-sidebar-expanded{
        inset-inline-start:0!important;inset-inline-end:auto!important;border-inline-start:0!important;border-inline-end:1px solid var(--foodex-border)!important;
        box-shadow:18px 0 44px rgba(15,23,42,.16)!important;transform:translateX(-105%)
    }
    body.foodex-mobile-nav-open aside.sidebar,
    body.foodex-mobile-nav-open aside.dashboard-sidebar,
    body.foodex-mobile-nav-open .shell-sidebar,
    body.foodex-mobile-nav-open aside.foodex-sidebar-collapsed,
    body.foodex-mobile-nav-open aside.foodex-sidebar-expanded{transform:translateX(0)}
    .foodex-sidebar-shell{height:100%;min-height:0;overflow:auto;padding-bottom:calc(18px + env(safe-area-inset-bottom))}
    aside.foodex-sidebar-collapsed .foodex-sidebar-brand span:last-child,
    aside.foodex-sidebar-collapsed .foodex-sidebar-search,
    aside.foodex-sidebar-collapsed .foodex-nav-label,
    aside.foodex-sidebar-collapsed .foodex-nav-chevron,
    aside.foodex-sidebar-collapsed .foodex-nav-group>summary span:nth-child(2){display:initial!important}
    aside.foodex-sidebar-collapsed .foodex-sidebar-search{display:block!important}
    aside.foodex-sidebar-collapsed .foodex-sidebar-head{justify-content:space-between;flex-wrap:nowrap}
    aside.foodex-sidebar-collapsed .foodex-sidebar-brand{justify-content:flex-start;width:auto}
    aside.foodex-sidebar-collapsed .foodex-sidebar-toggle{width:44px;height:44px}
    aside.foodex-sidebar-collapsed .foodex-nav-group summary{grid-template-columns:30px minmax(0,1fr) auto auto;place-items:initial;padding:8px 10px}
    aside.foodex-sidebar-collapsed .foodex-nav-disclosure{display:inline-flex!important}
    aside.foodex-sidebar-collapsed .foodex-nav-count{display:inline-flex!important}
    aside.foodex-sidebar-collapsed .foodex-nav-group:not([open]) .foodex-nav-children{display:grid}
    aside.foodex-sidebar-collapsed .foodex-nav-link{grid-template-columns:28px minmax(0,1fr);place-items:initial;padding:7px 9px}
    .foodex-admin-layout>.foodex-admin-main,html[dir=ltr] .foodex-admin-layout>.foodex-admin-main,
    .layout>.foodex-admin-main,html[dir=ltr] .layout>.foodex-admin-main,
    .layout>.main,html[dir=ltr] .layout>.main,
    .dashboard-layout>.dashboard-shell,html[dir=ltr] .dashboard-layout>.dashboard-shell,
    .module-layout>main,html[dir=ltr] .module-layout>main,
    .catalog-layout>main,html[dir=ltr] .catalog-layout>main,
    .lookup-layout>main,html[dir=ltr] .lookup-layout>main,
    .page>main,html[dir=ltr] .page>main,
    .shell>.main,html[dir=ltr] .shell>.main{grid-column:1!important;grid-row:1!important}
}
@media(prefers-reduced-motion:reduce){
    aside.sidebar,aside.dashboard-sidebar,.shell-sidebar{transition:none!important}
}

</style>

<div class="foodex-sidebar-shell" data-foodex-brand="v1">
    <div class="foodex-sidebar-head">
        <a class="foodex-sidebar-brand" href="{{ route('admin.index') }}"><span class="foodex-sidebar-brand-mark"><img src="{{ asset('brand/foodex-economical-group.webp') }}" alt="FOODEX Economical Group"></span><span>FOODEX</span></a>
        <button class="foodex-sidebar-toggle" type="button" aria-label="{{ __('admin.sidebar_toggle') }}" aria-expanded="false" data-foodex-sidebar-toggle>☰</button>
    </div>

    <div class="foodex-sidebar-search" role="search">
        @include('admin._premium-icon',['name'=>'search'])
        <input type="search" data-foodex-nav-search aria-label="{{ __('admin.sidebar_search') }}" placeholder="{{ __('admin.sidebar_search') }}" autocomplete="off">
    </div>

    <nav class="foodex-nav" aria-label="{{ __('admin.navigation') }}" data-foodex-nav>
        @foreach($navGroups as $group)
            @php($groupActive = $containsContext($group, (string)($navContext??'')))
            @php($groupPrimary = $group['label_text'] ?? __($group['label']))
            @php($authorizedLinkCount = $countAuthorizedChildren((array) ($group['children'] ?? [])))
            @php($groupStatus = app(\App\Support\V2SidebarGroupStatus::class)->forAuthorizedGroup($group))
            <details class="foodex-nav-group {{ $groupActive?'is-current-group':'' }}" data-group="{{ $group['key'] }}" data-nav-group="{{ $group['key'] }}" {{ $groupActive?'open':'' }}>
                <summary>
                    <span class="foodex-nav-group-icon" aria-hidden="true">{{ $group['icon'] }}</span>
                    <span class="foodex-nav-group-title">
                        {{ $groupPrimary }}
                        @if($groupStatus !== null)
                            <span class="foodex-nav-group-status" data-status-group="{{ $group['key'] }}" data-group-status="{{ $groupStatus }}" aria-label="{{ __('sidebar_group_status.'.$groupStatus) }}">{{ __('sidebar_group_status.'.$groupStatus) }}</span>
                        @endif
                    </span>
                    @if($authorizedLinkCount > 1)
                        <span class="foodex-nav-count" aria-label="{{ __('sidebar_admin.authorized_pages_count', ['count' => $authorizedLinkCount]) }}" data-authorized-page-count="{{ $authorizedLinkCount }}">{{ $authorizedLinkCount }}</span>
                    @endif
                    <span class="foodex-nav-disclosure" aria-hidden="true">
                        <span class="foodex-nav-disclosure-expand">{{ __('sidebar_admin.expand_group') }}</span>
                        <span class="foodex-nav-disclosure-collapse">{{ __('sidebar_admin.collapse_group') }}</span>
                        <span class="foodex-nav-chevron">⌄</span>
                    </span>
                </summary>
                <div class="foodex-nav-children">
                    @foreach($group['children'] as $child)
                        @include('admin._sidebar-node',['child'=>$child,'childIcon'=>$childIcon,'secondaryLocale'=>$secondaryLocale,'navContext'=>($navContext??'')])
                    @endforeach
                </div>
            </details>
        @endforeach
    </nav>
    <p class="foodex-sidebar-search-empty" data-foodex-nav-search-empty role="status" aria-live="polite" hidden>{{ __('sidebar_admin.search_empty') }}</p>

    @include('admin._sidebar-personalization')
    @include('admin._assistant-chat')
</div>

<script>
(() => {
    const shell = document.currentScript?.closest('aside') || document.querySelector('aside.sidebar,aside.dashboard-sidebar');
    if (!shell || shell.dataset.foodexSidebarReady === '1') return;
    shell.dataset.foodexSidebarReady = '1';

    const toggle = shell.querySelector('[data-foodex-sidebar-toggle]');
    const nav = shell.querySelector('[data-foodex-nav]');
    const search = shell.querySelector('[data-foodex-nav-search]');
    const stateKey = 'foodex.sidebar.expanded.{{ $user->id ?? 'guest' }}';
    const defaultCollapsed = @json((bool)($sidebarDefaultCollapsed ?? false));
    const desktopToggleLabel = @json(__('admin.sidebar_toggle'));
    const mobileOpenLabel = @json(__('admin.sidebar_open'));
    const mobileCloseLabel = @json(__('admin.sidebar_close'));
    const navigationLabel = @json(__('admin.navigation'));
    const originalShellState = {
        role: shell.getAttribute('role'),
        ariaModal: shell.getAttribute('aria-modal'),
        ariaHidden: shell.getAttribute('aria-hidden'),
        ariaLabel: shell.getAttribute('aria-label'),
        tabIndex: shell.getAttribute('tabindex'),
        inert: shell.hasAttribute('inert'),
    };
    if (!shell.id) shell.id = 'foodex-mobile-navigation';

    const restoreAttribute = (name, value) => {
        if (value === null) shell.removeAttribute(name);
        else shell.setAttribute(name, value);
    };

    const setExpanded = (expanded, persist = true) => {
        shell.classList.toggle('foodex-sidebar-expanded', expanded);
        shell.classList.toggle('foodex-sidebar-collapsed', !expanded);
        if (!matchMedia('(max-width:767px)').matches) {
            toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
        if (persist) localStorage.setItem(stateKey, expanded ? '1' : '0');
    };

    const compactMedia = matchMedia('(max-width:1023px)');
    const mobileMedia = matchMedia('(max-width:767px)');
    setExpanded(compactMedia.matches || (!defaultCollapsed && localStorage.getItem(stateKey) === '1'), false);

    const brand = shell.querySelector('.foodex-sidebar-brand');
    const bar = document.createElement('div');
    bar.className = 'foodex-mobile-web-bar';
    bar.setAttribute('data-foodex-mobile-web-bar', '1');

    const launcher = document.createElement('button');
    launcher.type = 'button';
    launcher.className = 'foodex-mobile-nav-launcher';
    launcher.setAttribute('aria-label', mobileOpenLabel);
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-controls', shell.id);
    launcher.textContent = '☰';

    bar.append(launcher);

    const backdrop = document.createElement('button');
    backdrop.type = 'button';
    backdrop.className = 'foodex-mobile-nav-backdrop';
    backdrop.setAttribute('aria-label', mobileCloseLabel);
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.tabIndex = -1;

    document.body.append(backdrop, bar);

    const backgroundTargets = () => [...new Set([
        ...document.querySelectorAll('.foodex-admin-main,.dashboard-shell,.layout>.main,.module-layout>main,.catalog-layout>main,.lookup-layout>main,.page>main,.shell>.main'),
    ])].filter((target) => target !== shell && !shell.contains(target));

    const setBackgroundInert = (inert) => {
        backgroundTargets().forEach((target) => {
            if (inert) {
                if (!target.hasAttribute('inert')) {
                    target.setAttribute('inert', '');
                    target.dataset.foodexMobileNavInert = '1';
                }
            } else if (target.dataset.foodexMobileNavInert === '1') {
                target.removeAttribute('inert');
                delete target.dataset.foodexMobileNavInert;
            }
        });
        if (inert) {
            bar.setAttribute('inert', '');
        } else {
            bar.removeAttribute('inert');
        }
    };

    const focusableInDrawer = () => [...shell.querySelectorAll(
        'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),summary,[tabindex]:not([tabindex="-1"])'
    )].filter((element) => {
        if (element.hidden || element.closest('[inert]')) return false;
        const style = getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    });

    const restoreDesktopShellSemantics = () => {
        restoreAttribute('role', originalShellState.role);
        restoreAttribute('aria-modal', originalShellState.ariaModal);
        restoreAttribute('aria-hidden', originalShellState.ariaHidden);
        restoreAttribute('aria-label', originalShellState.ariaLabel);
        restoreAttribute('tabindex', originalShellState.tabIndex);
        if (originalShellState.inert) shell.setAttribute('inert', '');
        else shell.removeAttribute('inert');
        setBackgroundInert(false);
    };

    let previousFocus = null;

    const setDrawer = (open, { restoreFocus = true } = {}) => {
        const enabled = mobileMedia.matches;
        const wasOpen = document.body.classList.contains('foodex-mobile-nav-open');
        const shouldOpen = enabled && open;

        if (shouldOpen && !wasOpen) {
            previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : launcher;
        }

        document.body.classList.toggle('foodex-mobile-nav-open', shouldOpen);
        launcher.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        launcher.setAttribute('aria-label', shouldOpen ? mobileCloseLabel : mobileOpenLabel);

        if (!enabled) {
            launcher.setAttribute('aria-label', mobileOpenLabel);
            toggle?.setAttribute('aria-label', desktopToggleLabel);
            if (toggle) toggle.textContent = '☰';
            restoreDesktopShellSemantics();
            previousFocus = null;
            return;
        }

        shell.setAttribute('role', 'dialog');
        shell.setAttribute('aria-label', navigationLabel);
        shell.setAttribute('tabindex', '-1');
        toggle?.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
        toggle?.setAttribute('aria-label', shouldOpen ? mobileCloseLabel : mobileOpenLabel);
        if (toggle) toggle.textContent = shouldOpen ? '×' : '☰';

        if (shouldOpen) {
            shell.removeAttribute('inert');
            shell.setAttribute('aria-hidden', 'false');
            shell.setAttribute('aria-modal', 'true');
            setBackgroundInert(true);
            requestAnimationFrame(() => {
                const target = search || toggle || focusableInDrawer()[0] || shell;
                target.focus({ preventScroll: true });
            });
            return;
        }

        setBackgroundInert(false);
        shell.removeAttribute('aria-modal');
        shell.setAttribute('aria-hidden', 'true');
        shell.setAttribute('inert', '');

        if (wasOpen && restoreFocus) {
            const target = previousFocus?.isConnected && !previousFocus.closest('[inert]') ? previousFocus : launcher;
            target.focus({ preventScroll: true });
        }
        previousFocus = null;
    };

    const syncViewport = () => {
        const compact = compactMedia.matches;
        const mobile = mobileMedia.matches;
        document.body.classList.toggle('foodex-mobile-web', mobile);
        setDrawer(false, { restoreFocus: false });
        setExpanded(compact ? true : localStorage.getItem(stateKey) === '1', false);
    };

    launcher.addEventListener('click', () => setDrawer(!document.body.classList.contains('foodex-mobile-nav-open')));
    backdrop.addEventListener('click', () => setDrawer(false));
    toggle?.addEventListener('click', () => {
        if (mobileMedia.matches) {
            setDrawer(false);
            return;
        }
        setExpanded(shell.classList.contains('foodex-sidebar-collapsed'));
    });
    document.addEventListener('keydown', (event) => {
        if (!mobileMedia.matches || !document.body.classList.contains('foodex-mobile-nav-open')) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            setDrawer(false);
            return;
        }

        if (event.key !== 'Tab') return;
        const focusable = focusableInDrawer();
        if (!focusable.length) {
            event.preventDefault();
            shell.focus({ preventScroll: true });
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (!shell.contains(active)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus({ preventScroll: true });
        } else if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus({ preventScroll: true });
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    });
    nav?.addEventListener('click', (event) => {
        if (mobileMedia.matches && event.target.closest('a.foodex-nav-link')) setDrawer(false);
    });
    compactMedia.addEventListener?.('change', syncViewport);
    mobileMedia.addEventListener?.('change', syncViewport);
    syncViewport();

    const groups = [...(nav?.querySelectorAll('[data-group]') || [])];
    const emptyState = shell.querySelector('[data-foodex-nav-search-empty]');

    // Disclosure state belongs to this signed-in user, never to the role, store,
    // or another account. Only current server-authorized group keys are read.
    const groupStateKey = 'foodex.sidebar.groups.{{ $user->id ?? 'guest' }}';
    let savedGroupState = {};
    try {
        const stored = JSON.parse(localStorage.getItem(groupStateKey) || 'null');
        if (stored && typeof stored === 'object' && !Array.isArray(stored)) savedGroupState = stored;
    } catch {
        // Private browsing or a malformed historic preference cannot hide navigation.
    }

    groups.forEach(group => {
        const key = group.dataset.navGroup;
        const isCurrent = !!group.querySelector('[aria-current="page"]');
        if (!isCurrent && key && typeof savedGroupState[key] === 'boolean') {
            group.open = savedGroupState[key];
        } else if (!isCurrent && !compactMedia.matches) {
            group.open = false;
        }

        // Native <summary> already supports Enter/Space and reports expanded
        // state to assistive tech. Capture only genuine user disclosure actions:
        // automatic search expansions must not overwrite a saved preference.
        group.querySelector(':scope > summary')?.addEventListener('click', () => {
            if (search?.value.trim() || !key) return;
            requestAnimationFrame(() => {
                const next = { ...savedGroupState, [key]: group.open };
                try {
                    localStorage.setItem(groupStateKey, JSON.stringify(next));
                    savedGroupState = next;
                } catch {
                    // Storage denied/full: native disclosure continues to work.
                }
            });
        });
    });

    // Search only the already RBAC/tenant/module-authorized links rendered by
    // AdminNavigation. Never request or index hidden server-side destinations.
    const normalized = value => (value || '').trim().normalize('NFKC').toLocaleLowerCase();
    let previousExpansion = null;
    const updateSearch = () => {
        const needle = normalized(search?.value);
        if (needle && previousExpansion === null) {
            previousExpansion = new Map(groups.map(group => [group, group.open]));
        }

        let matchingLinks = 0;
        groups.forEach(group => {
            const groupLabel = normalized(group.querySelector('summary')?.textContent);
            const matchGroup = needle !== '' && groupLabel.includes(needle);
            const links = [...group.querySelectorAll('.foodex-nav-link')];
            links.forEach(link => {
                const label = normalized(link.dataset.navLabel || link.textContent);
                link.hidden = needle !== '' && !matchGroup && !label.includes(needle);
            });

            // Keep a parent destination visible when its nested child matches,
            // so a search result retains its actual business/navigation context.
            group.querySelectorAll('.foodex-nav-subchildren').forEach(children => {
                const hasMatch = !!children.querySelector('.foodex-nav-link:not([hidden])');
                children.hidden = needle !== '' && !hasMatch;
                if (needle !== '' && hasMatch) {
                    const parent = children.previousElementSibling;
                    if (parent?.matches('.foodex-nav-link')) parent.hidden = false;
                }
            });

            const visibleCount = group.querySelectorAll('.foodex-nav-link:not([hidden])').length;
            matchingLinks += visibleCount;
            group.hidden = needle !== '' && visibleCount === 0;
            if (needle !== '') group.open = visibleCount > 0;
            else if (previousExpansion !== null) group.open = previousExpansion.get(group) ?? false;
        });
        if (!needle) previousExpansion = null;
        if (emptyState) emptyState.hidden = !needle || matchingLinks > 0;
    };

    search?.addEventListener('input', updateSearch);
    updateSearch(); // Honor browser-restored search values without exposing an unfiltered list.
    search?.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !search.value) return;
        // First Escape clears search; a second Escape can close the mobile drawer.
        event.stopPropagation();
        event.preventDefault();
        search.value = '';
        updateSearch();
        search.focus({ preventScroll: true });
    });
})();
</script>
