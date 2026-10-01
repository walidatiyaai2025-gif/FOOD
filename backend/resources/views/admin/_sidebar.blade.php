@include('admin._brand')
@php
    $ar = app()->getLocale() === 'ar';
    $secondaryLocale = $ar ? 'en' : 'ar';
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
.foodex-sidebar-search{position:relative}.foodex-sidebar-search input{width:100%;padding-inline-start:38px}.foodex-sidebar-search .foodex-svg-icon{position:absolute;inset-inline-start:11px;top:12px;width:18px;height:18px;color:var(--foodex-muted)}
.foodex-nav{display:grid;gap:6px}.foodex-nav-group{border:1px solid transparent;border-radius:12px}.foodex-nav-group summary{list-style:none;cursor:pointer;min-height:44px;display:grid;grid-template-columns:24px minmax(0,1fr) 18px;align-items:center;gap:9px;padding:8px 10px;border-radius:11px;font-weight:800;color:var(--foodex-ink)}.foodex-nav-group summary::-webkit-details-marker{display:none}.foodex-nav-group summary:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-nav-group[open]{border-color:var(--foodex-border);background:#fbfcfd}.foodex-nav-group[open] summary{color:var(--foodex-green-dark)}.foodex-nav-group[open] .foodex-nav-chevron{transform:rotate(180deg)}
.foodex-nav-children{display:grid;gap:3px;padding:0 7px 7px}.foodex-nav-link{min-height:44px;display:grid;grid-template-columns:22px minmax(0,1fr);align-items:center;gap:9px;padding:7px 9px;border-radius:10px;color:var(--foodex-ink);text-decoration:none}.foodex-nav-link:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.foodex-nav-link.active{background:var(--foodex-green);color:#fff;box-shadow:0 8px 18px rgba(21,138,58,.14)}
.foodex-nav-label{display:block;min-width:0}.foodex-nav-label strong{display:block;font-size:.86rem;line-height:1.2}.foodex-nav-label small{display:block;color:var(--foodex-muted);font-size:.67rem;margin-top:2px}.foodex-nav-link.active small{color:rgba(255,255,255,.8)}
aside.foodex-sidebar-collapsed .foodex-sidebar-brand span:last-child,
aside.foodex-sidebar-collapsed .foodex-sidebar-search,
aside.foodex-sidebar-collapsed .foodex-nav-label,
aside.foodex-sidebar-collapsed .foodex-nav-chevron,
aside.foodex-sidebar-collapsed .foodex-nav-group>summary span:nth-child(2){display:none!important}

aside.foodex-sidebar-collapsed .foodex-sidebar-head{justify-content:center;flex-wrap:wrap}
aside.foodex-sidebar-collapsed .foodex-sidebar-brand{justify-content:center;width:100%}
aside.foodex-sidebar-collapsed .foodex-sidebar-toggle{width:100%}
aside.foodex-sidebar-collapsed .foodex-nav-group{border:0;background:transparent}
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
</style>

<div class="foodex-sidebar-shell" data-foodex-brand="v1">
    <div class="foodex-sidebar-head">
        <a class="foodex-sidebar-brand" href="{{ route('admin.index') }}"><span class="foodex-sidebar-brand-mark"><img src="{{ asset('brand/foodex-economical-group.webp') }}" alt="FOODEX Economical Group"></span><span>FOODEX</span></a>
        <button class="foodex-sidebar-toggle" type="button" aria-label="{{ __('admin.sidebar_toggle') }}" aria-expanded="false" data-foodex-sidebar-toggle>☰</button>
    </div>

    <div class="foodex-sidebar-search">
        @include('admin._premium-icon',['name'=>'search'])
        <input type="search" data-foodex-nav-search placeholder="{{ __('admin.sidebar_search') }}" autocomplete="off">
    </div>

    <nav class="foodex-nav" aria-label="{{ __('admin.navigation') }}" data-foodex-nav>
        @foreach($navGroups as $group)
            @php($groupActive = collect($group['children'])->contains(fn($child)=>($navContext??'')===$child['key']))
            <details class="foodex-nav-group" data-group="{{ $group['key'] }}" data-nav-group="{{ $group['key'] }}" {{ $groupActive?'open':'' }}>
                <summary>
                    <span aria-hidden="true">{{ $group['icon'] }}</span>
                    <span>{{ __($group['label']) }}</span>
                    <span class="foodex-nav-chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="foodex-nav-children">
                    @foreach($group['children'] as $child)
                        <a class="foodex-nav-link {{ ($navContext??'')===$child['key']?'active':'' }}" href="{{ route($child['route'],$child['params']) }}" data-nav-label="{{ mb_strtolower(__($child['label'])) }}" @if(($navContext??'')===$child['key']) aria-current="page" @endif>
                            @include('admin._premium-icon',['name'=>$childIcon($child['key'])])
                            <span class="foodex-nav-label"><strong>{{ __($child['label']) }}</strong><small lang="{{ $secondaryLocale }}">{{ __($child['label'],[],$secondaryLocale) }}</small></span>
                        </a>
                    @endforeach
                </div>
            </details>
        @endforeach
    </nav>

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

    const setExpanded = (expanded) => {
        shell.classList.toggle('foodex-sidebar-expanded', expanded);
        shell.classList.toggle('foodex-sidebar-collapsed', !expanded);
        toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        localStorage.setItem(stateKey, expanded ? '1' : '0');
    };

    const mobile = matchMedia('(max-width:1023px)').matches;
    setExpanded(mobile || localStorage.getItem(stateKey) === '1');
    toggle?.addEventListener('click', () => setExpanded(shell.classList.contains('foodex-sidebar-collapsed')));

    const groups = [...(nav?.querySelectorAll('[data-group]') || [])];
    groups.forEach(group => {
        if (!group.querySelector('[aria-current="page"]') && !mobile) group.open = false;
    });

    search?.addEventListener('input', () => {
        const needle = search.value.trim().toLocaleLowerCase();
        groups.forEach(group => {
            let visible=false;
            group.querySelectorAll('.foodex-nav-link').forEach(link => {
                const match = needle==='' || (link.dataset.navLabel||'').includes(needle);
                link.hidden=!match; visible ||= match;
            });
            group.hidden=!visible;
            if(needle!=='' && visible) group.open=true;
        });
    });
})();
</script>
