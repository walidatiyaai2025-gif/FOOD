{{-- #2870: preferences only reorder/hide already-authorized navigation nodes. --}}
<style>
.foodex-nav-personalize-bar{display:flex;flex-wrap:wrap;gap:6px}
.foodex-nav-personalize-bar button{min-height:44px;border:1px solid var(--foodex-border);background:var(--foodex-action-green);color:#fff;padding:8px 12px;border-radius:9px;font:inherit;cursor:pointer}
.foodex-nav-personalize-bar button[hidden],.foodex-nav-group[hidden]{display:none!important}
.foodex-nav-personalize-actions{display:none;gap:5px;padding:6px;flex-wrap:wrap}
.foodex-nav[data-personalizing="true"] .foodex-nav-personalize-actions{display:flex}
.foodex-nav-personalize-actions button{min-width:44px;min-height:44px;flex:1;border:1px solid var(--foodex-border);border-radius:8px;background:#fff;color:var(--foodex-green-dark);cursor:pointer}
.foodex-nav-personalize-actions button[aria-pressed="true"]{background:var(--foodex-green-soft)}
.foodex-nav-personalize-actions button:focus-visible,.foodex-nav-personalize-bar button:focus-visible{outline:3px solid var(--foodex-green-dark);outline-offset:2px}
.foodex-nav-personalize-actions button:disabled{opacity:.4;cursor:not-allowed}
.foodex-nav-group.personal-favorite>summary::after{content:"★";color:var(--foodex-green-dark);font-size:.7rem;grid-column:1/-1}
aside.foodex-sidebar-collapsed .foodex-nav-personalize-bar{display:none}
@media(max-width:1023px){aside.foodex-sidebar-collapsed .foodex-nav-personalize-bar{display:flex}}
</style>
<div class="foodex-nav-personalize-bar" data-foodex-nav-personalization>
    <button type="button" data-personalize-toggle aria-pressed="false">{{ __('sidebar_personalization.customize') }}</button>
    <button type="button" data-personalize-reset hidden>{{ __('sidebar_personalization.reset') }}</button>
</div>
<script>
(() => {
    'use strict';
    const shell = document.currentScript?.closest('aside');
    const nav = shell?.querySelector('[data-foodex-nav]');
    const toolbar = shell?.querySelector('[data-foodex-nav-personalization]');
    if (!nav || !toolbar || toolbar.dataset.ready) return;
    toolbar.dataset.ready = '1';
    // Only the server-rendered details are eligible. Never generate a route
    // from localStorage; role/store/module changes take effect on every render.
    const groups = [...nav.querySelectorAll(':scope > details[data-nav-group]')];
    const keys = groups.map(group => group.dataset.navGroup);
    const authorized = new Set(keys);
    const original = new Map(keys.map((key, index) => [key, index]));
    const storageKey = 'foodex.sidebar.personal.v1.' + String(@json($user->id ?? 'guest'));
    const names = {
        favorite: @json(__('sidebar_personalization.favorite')),
        unfavorite: @json(__('sidebar_personalization.unfavorite')),
        up: @json(__('sidebar_personalization.up')),
        down: @json(__('sidebar_personalization.down')),
        hide: @json(__('sidebar_personalization.hide')),
        show: @json(__('sidebar_personalization.show')),
    };
    const toggle = toolbar.querySelector('[data-personalize-toggle]');
    const reset = toolbar.querySelector('[data-personalize-reset]');
    const search = shell.querySelector('[data-foodex-nav-search]');
    const sanitize = input => Array.isArray(input)
        ? [...new Set(input.filter(value => typeof value === 'string' && authorized.has(value)))]
        : [];
    let prefs = {favorites: [], hidden: [], order: []};
    try {
        const raw = localStorage.getItem(storageKey);
        if (raw && raw.length < 8192) {
            const saved = JSON.parse(raw);
            if (saved && typeof saved === 'object' && !Array.isArray(saved)) {
                prefs = {favorites: sanitize(saved.favorites), hidden: sanitize(saved.hidden), order: sanitize(saved.order)};
            }
        }
    } catch (_) {}
    const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(prefs)); } catch (_) {} };
    let editing = false;
    let previousOpen = new Map();
    const sorted = () => [...groups].sort((left, right) => {
        const a = left.dataset.navGroup, b = right.dataset.navGroup;
        const favoriteDifference = Number(prefs.favorites.includes(b)) - Number(prefs.favorites.includes(a));
        if (favoriteDifference) return favoriteDifference;
        const position = key => {
            const saved = prefs.order.indexOf(key);
            return saved < 0 ? groups.length + original.get(key) : saved;
        };
        return position(a) - position(b);
    });
    const applyVisibility = () => {
        const needle = search?.value.trim().toLocaleLowerCase() || '';
        groups.forEach(group => {
            const active = !!group.querySelector('[aria-current="page"]');
            const matches = !needle || [...group.querySelectorAll('a.foodex-nav-link')]
                .some(link => (link.dataset.navLabel || '').includes(needle));
            group.hidden = !matches || (!editing && !active && prefs.hidden.includes(group.dataset.navGroup));
        });
    };
    const render = () => {
        const ordered = sorted();
        ordered.forEach(group => nav.appendChild(group));
        nav.dataset.personalizing = editing ? 'true' : 'false';
        toggle.setAttribute('aria-pressed', editing ? 'true' : 'false');
        reset.hidden = !editing;
        ordered.forEach((group, index) => {
            const key = group.dataset.navGroup, favorite = prefs.favorites.includes(key), hidden = prefs.hidden.includes(key);
            const active = !!group.querySelector('[aria-current="page"]');
            group.classList.toggle('personal-favorite', favorite);
            const title = group.querySelector(':scope > summary > span:nth-child(2)')?.textContent?.trim() || key;
            group.querySelectorAll(':scope > .foodex-nav-personalize-actions button').forEach(button => {
                const action = button.dataset.action;
                const label = action === 'favorite' ? (favorite ? names.unfavorite : names.favorite)
                    : action === 'hide' ? (hidden ? names.show : names.hide) : names[action];
                button.setAttribute('aria-label', label + ': ' + title);
                button.title = label + ': ' + title;
                if (action === 'favorite') { button.textContent = favorite ? '★' : '☆'; button.setAttribute('aria-pressed', String(favorite)); }
                if (action === 'hide') { button.textContent = hidden ? '◉' : '◌'; button.setAttribute('aria-pressed', String(hidden)); button.disabled = active; }
                if (action === 'up' || action === 'down') {
                    const neighbor = ordered[index + (action === 'up' ? -1 : 1)];
                    button.disabled = !neighbor || prefs.favorites.includes(neighbor.dataset.navGroup) !== favorite;
                }
            });
            if (editing) group.open = true;
        });
        applyVisibility();
    };
    groups.forEach(group => {
        const row = document.createElement('div');
        row.className = 'foodex-nav-personalize-actions';
        [['favorite','☆'], ['up','↑'], ['down','↓'], ['hide','◌']].forEach(([action, symbol]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.action = action;
            button.textContent = symbol;
            row.append(button);
        });
        group.querySelector(':scope > summary')?.insertAdjacentElement('afterend', row);
        row.addEventListener('click', event => {
            const button = event.target.closest('button[data-action]');
            if (!editing || !button || button.disabled) return;
            const key = group.dataset.navGroup, action = button.dataset.action;
            if (action === 'favorite' || action === 'hide') {
                if (action === 'hide' && group.querySelector('[aria-current="page"]')) return;
                const field = action === 'favorite' ? 'favorites' : 'hidden';
                prefs[field] = prefs[field].includes(key) ? prefs[field].filter(item => item !== key) : [...prefs[field], key];
            } else {
                const order = sorted().map(item => item.dataset.navGroup);
                const index = order.indexOf(key), other = index + (action === 'up' ? -1 : 1);
                if (other < 0 || other >= order.length || prefs.favorites.includes(order[other]) !== prefs.favorites.includes(key)) return;
                [order[index], order[other]] = [order[other], order[index]];
                prefs.order = order;
            }
            persist();
            render();
            button.focus({preventScroll:true});
        });
    });
    toggle.addEventListener('click', () => {
        editing = !editing;
        if (editing) previousOpen = new Map(groups.map(group => [group, group.open]));
        else groups.forEach(group => { group.open = group.querySelector('[aria-current="page"]') ? true : !!previousOpen.get(group); });
        render();
    });
    reset.addEventListener('click', () => {
        prefs = {favorites: [], hidden: [], order: []};
        persist();
        render();
        toggle.focus({preventScroll:true});
    });
    search?.addEventListener('input', applyVisibility);
    render();
})();
</script>