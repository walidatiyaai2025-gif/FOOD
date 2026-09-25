<style>
    .sidebar{background:#0f172a;color:#e2e8f0;padding:20px;overflow:auto}.brand-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px}.brand-link{color:#fff;text-decoration:none;font-weight:800;letter-spacing:.08em}.sidebar-toggle{display:none;border:1px solid #334155;background:#1e293b;color:#fff;border-radius:9px;padding:7px 10px;cursor:pointer}.nav-search{width:100%;border:1px solid #334155;background:#111827;color:#fff;border-radius:10px;padding:10px 12px;margin-bottom:12px}.nav-home,.nav-child{display:flex;gap:8px;align-items:center;color:#cbd5e1;text-decoration:none;border-radius:10px;padding:9px 11px}.nav-home{margin-bottom:8px}.nav-home:hover,.nav-child:hover,.nav-home.active,.nav-child.active{background:#1e293b;color:#fff}.nav-group{border-top:1px solid rgba(148,163,184,.14);padding-top:6px;margin-top:6px}.nav-group summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;padding:10px 8px;border-radius:10px;font-weight:650}.nav-group summary::-webkit-details-marker{display:none}.nav-group summary:hover{background:#172033}.nav-group-title{display:flex;align-items:center;gap:9px}.nav-children{display:grid;gap:2px;padding:0 8px 7px}.nav-child{font-size:.92rem;padding-inline-start:30px}.nav-group[open] .nav-chevron{transform:rotate(180deg)}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    @media(max-width:760px){.sidebar-toggle{display:block}.sidebar.collapsed #admin-navigation,.sidebar.collapsed .nav-search-wrap{display:none}}
</style>
@php
    $brandSuffix = str_starts_with((string) ($navContext ?? ''), 'b2b_')
        ? ' · B2B'
        : (str_starts_with((string) ($navContext ?? ''), 'b2c_') ? ' · B2C' : '');
@endphp
<div class="brand-row">
    <a class="brand-link" href="{{ route('admin.index') }}">FOODEX{{ $brandSuffix }}</a>
    <button class="sidebar-toggle" type="button" aria-label="{{ __('admin.sidebar_toggle') }}" aria-controls="admin-navigation" aria-expanded="true">☰</button>
</div>

<div class="nav-search-wrap">
    <label class="sr-only" for="admin-nav-search">{{ __('admin.sidebar_search') }}</label>
    <input id="admin-nav-search" class="nav-search" type="search" placeholder="{{ __('admin.sidebar_search') }}" autocomplete="off">
</div>

<nav id="admin-navigation" aria-label="{{ __('admin.navigation') }}">
    <a class="nav-home {{ ($navContext ?? '') === 'overview' ? 'active' : '' }}" href="{{ route('admin.index') }}">
        <span aria-hidden="true">⌂</span>
        <span>{{ __('admin.overview') }}</span>
    </a>

    @foreach ($navGroups as $group)
        @php
            $groupActive = collect($group['children'])->contains(fn ($child) => ($navContext ?? '') === $child['key']);
        @endphp
        <details class="nav-group" data-nav-group="{{ $group['key'] }}" {{ $groupActive ? 'open' : '' }}>
            <summary>
                <span class="nav-group-title"><span aria-hidden="true">{{ $group['icon'] }}</span> {{ __($group['label']) }}</span>
                <span class="nav-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="nav-children">
                @foreach ($group['children'] as $child)
                    <a
                        class="nav-child {{ ($navContext ?? '') === $child['key'] ? 'active' : '' }}"
                        href="{{ route($child['route'], $child['params']) }}"
                        data-nav-label="{{ mb_strtolower(__($child['label'])) }}"
                        @if (($navContext ?? '') === $child['key']) aria-current="page" @endif
                    >
                        {{ __($child['label']) }}
                    </a>
                @endforeach
            </div>
        </details>
    @endforeach
</nav>

<script>
(() => {
    const root = document.getElementById('admin-navigation');
    if (!root) return;

    const userKey = @json('foodex.admin.nav.'.($user->id ?? 'guest'));
    const groups = [...root.querySelectorAll('[data-nav-group]')];

    groups.forEach((group) => {
        const key = userKey + '.' + group.dataset.navGroup;
        const persisted = localStorage.getItem(key);
        if (persisted === 'open') group.open = true;
        if (persisted === 'closed' && !group.querySelector('[aria-current="page"]')) group.open = false;
        group.addEventListener('toggle', () => localStorage.setItem(key, group.open ? 'open' : 'closed'));
    });

    const search = document.getElementById('admin-nav-search');
    search?.addEventListener('input', () => {
        const needle = search.value.trim().toLocaleLowerCase();
        groups.forEach((group) => {
            let visible = false;
            group.querySelectorAll('.nav-child').forEach((link) => {
                const match = needle === '' || (link.dataset.navLabel || '').includes(needle);
                link.hidden = !match;
                visible ||= match;
            });
            group.hidden = !visible;
            if (needle !== '' && visible) group.open = true;
        });
    });

    const toggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    toggle?.addEventListener('click', () => {
        const collapsed = sidebar?.classList.toggle('collapsed') ?? false;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
})();
</script>
