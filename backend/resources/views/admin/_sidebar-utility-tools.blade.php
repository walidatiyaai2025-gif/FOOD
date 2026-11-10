{{-- P165: Utilities re-use existing authorized navigation and profile routes. --}}
<style>
.foodex-sidebar-utilities{position:sticky;inset-block-end:0;z-index:10;flex:0 0 auto;margin-block-start:auto;padding:8px 4px calc(8px + env(safe-area-inset-bottom, 0px));border-block-start:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);box-shadow:0 -4px 12px var(--foodex-green-soft)}
.foodex-sidebar-utility-row{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:4px}
.foodex-sidebar-utility-row form{min-width:0;margin:0;display:flex}
.foodex-sidebar-utility{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;width:100%;min-width:0;min-height:44px;padding:5px 2px;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface);color:var(--foodex-green-dark);text-align:center;text-decoration:none;font:inherit;font-weight:700;cursor:pointer}
.foodex-sidebar-utility:hover,.foodex-sidebar-utility[aria-expanded="true"]{background:var(--foodex-green-soft);border-color:var(--foodex-green)}
.foodex-sidebar-utility:focus-visible,.foodex-sidebar-recent-panel button:focus-visible{outline:3px solid var(--foodex-green);outline-offset:2px}
.foodex-sidebar-utility-icon{font-size:1rem;line-height:1;flex:0 0 auto}
.foodex-sidebar-utility-label{font-size:.64rem;line-height:1.2;white-space:normal;overflow-wrap:anywhere}
.foodex-sidebar-recent-panel{position:absolute;inset-inline:4px;inset-block-end:calc(100% + 4px);display:grid;gap:4px;max-height:min(42vh,310px);overflow:auto;padding:10px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);box-shadow:0 8px 22px var(--foodex-green-soft)}
.foodex-sidebar-recent-panel[hidden]{display:none!important}
.foodex-sidebar-recent-panel button{width:100%;min-height:44px;padding:8px 10px;border:1px solid var(--foodex-border);border-radius:8px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);text-align:start;white-space:normal;overflow-wrap:anywhere;font:inherit;cursor:pointer}
.foodex-sidebar-recent-panel p{margin:0;padding:8px;color:var(--foodex-muted);font-size:.8rem}
aside.foodex-sidebar-collapsed .foodex-sidebar-utility-row{grid-template-columns:1fr}
aside.foodex-sidebar-collapsed .foodex-sidebar-utility-label{display:none}
aside.foodex-sidebar-collapsed .foodex-sidebar-recent-panel{position:fixed;inset-block-end:12px;inset-inline-start:90px;inset-inline-end:auto;width:min(280px,calc(100vw - 105px));max-width:none}
@media(max-width:1023px){
    aside.foodex-sidebar-collapsed .foodex-sidebar-utility-row{grid-template-columns:repeat(5,minmax(0,1fr))}
    aside.foodex-sidebar-collapsed .foodex-sidebar-utility-label{display:block}
    aside.foodex-sidebar-collapsed .foodex-sidebar-recent-panel{position:absolute;inset-block-end:calc(100% + 4px);inset-inline:4px;width:auto}
}
</style>
<section class="foodex-sidebar-utilities" data-foodex-sidebar-utilities aria-label="{{ __('sidebar_utilities.tools') }}" role="region">
    <div class="foodex-sidebar-recent-panel" id="foodex-sidebar-recent-panel" data-foodex-recent-panel role="region" aria-label="{{ __('sidebar_utilities.history') }}" hidden>
        <div data-foodex-recent-list></div>
        <p data-foodex-recent-empty>{{ __('sidebar_utilities.no_history') }}</p>
    </div>
    <div class="foodex-sidebar-utility-row">
        <button type="button" class="foodex-sidebar-utility" data-foodex-utility-favorites aria-label="{{ __('sidebar_utilities.favorites') }}" title="{{ __('sidebar_utilities.favorites') }}">
            <span class="foodex-sidebar-utility-icon" aria-hidden="true">★</span><span class="foodex-sidebar-utility-label">{{ __('sidebar_utilities.favorites') }}</span>
        </button>
        <button type="button" class="foodex-sidebar-utility" data-foodex-utility-history aria-controls="foodex-sidebar-recent-panel" aria-expanded="false" aria-label="{{ __('sidebar_utilities.history') }}" title="{{ __('sidebar_utilities.history') }}">
            <span class="foodex-sidebar-utility-icon" aria-hidden="true">◷</span><span class="foodex-sidebar-utility-label">{{ __('sidebar_utilities.history') }}</span>
        </button>
        <a class="foodex-sidebar-utility" data-foodex-utility-help href="{{ route('public.support') }}" aria-label="{{ __('sidebar_utilities.help') }}" title="{{ __('sidebar_utilities.help') }}">
            <span class="foodex-sidebar-utility-icon" aria-hidden="true">?</span><span class="foodex-sidebar-utility-label">{{ __('sidebar_utilities.help') }}</span>
        </a>
        <a class="foodex-sidebar-utility" data-foodex-utility-profile href="{{ route('admin.profile.index') }}" aria-label="{{ __('sidebar_utilities.profile') }}" title="{{ __('sidebar_utilities.profile') }}">
            <span class="foodex-sidebar-utility-icon" aria-hidden="true">◉</span><span class="foodex-sidebar-utility-label">{{ __('sidebar_utilities.profile') }}</span>
        </a>
        <form method="post" action="{{ route('admin.profile.locale') }}" data-foodex-utility-language>
            @csrf
            @method('PATCH')
            <input type="hidden" name="locale" value="{{ $ar ? 'en' : 'ar' }}">
            <button type="submit" class="foodex-sidebar-utility" aria-label="{{ __('sidebar_utilities.switch_language') }}" title="{{ __('sidebar_utilities.switch_language') }}">
                <span class="foodex-sidebar-utility-icon" aria-hidden="true">🌐</span><span class="foodex-sidebar-utility-label">{{ __('sidebar_utilities.language') }}</span>
            </button>
        </form>
    </div>
</section>
<script>
(() => {
    'use strict';
    const shell = document.currentScript?.closest('aside');
    const tools = shell?.querySelector('[data-foodex-sidebar-utilities]');
    const nav = shell?.querySelector('[data-foodex-nav]');
    if (!tools || !nav || tools.dataset.ready === '1') return;
    tools.dataset.ready = '1';

    const favoriteButton = tools.querySelector('[data-foodex-utility-favorites]');
    const historyButton = tools.querySelector('[data-foodex-utility-history]');
    const panel = tools.querySelector('[data-foodex-recent-panel]');
    const recentList = tools.querySelector('[data-foodex-recent-list]');
    const emptyText = tools.querySelector('[data-foodex-recent-empty]');
    const storageKey = 'foodex.sidebar.history.v1.' + String(@json($user->id ?? 'guest'));
    const openLinks = () => new Map([...nav.querySelectorAll('a.foodex-nav-link[data-nav-item]')]
        .filter(link => link.dataset.navItem && link.getAttribute('href'))
        .map(link => [link.dataset.navItem, link]));
    const permitted = openLinks();
    const sanitize = raw => Array.isArray(raw)
        ? [...new Set(raw.filter(key => typeof key === 'string' && permitted.has(key)))].slice(0, 5)
        : [];

    // Only store opaque item keys, not URLs; rebuild every target from the
    // current server-authorized Sidebar, protecting store/channel changes.
    let history = [];
    try {
        const saved = localStorage.getItem(storageKey);
        if (saved && saved.length < 4096) history = sanitize(JSON.parse(saved));
    } catch (_) { history = []; }
    const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(history)); } catch (_) {} };
    const labelFor = link => link.querySelector('.foodex-nav-label strong')?.textContent?.trim()
        || link.getAttribute('aria-label') || link.dataset.navItem;
    const closeHistory = () => {
        panel.hidden = true;
        historyButton.setAttribute('aria-expanded', 'false');
    };
    const renderHistory = () => {
        // Do not trust stale localStorage keys, paths or labels.
        history = sanitize(history);
        recentList.replaceChildren();
        history.forEach(key => {
            const link = permitted.get(key);
            if (!link) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = labelFor(link);
            button.dataset.recentItem = key;
            recentList.appendChild(button);
        });
        emptyText.hidden = recentList.childElementCount > 0;
    };
    const remember = key => {
        if (!permitted.has(key)) return;
        history = [key, ...history.filter(previous => previous !== key)].slice(0, 5);
        persist();
        renderHistory();
    };

    nav.querySelectorAll('a.foodex-nav-link[data-nav-item]').forEach(link => {
        link.addEventListener('click', () => remember(link.dataset.navItem));
    });
    const current = nav.querySelector('a.foodex-nav-link[aria-current="page"][data-nav-item]');
    if (current) remember(current.dataset.navItem);
    else renderHistory();

    favoriteButton.addEventListener('click', () => {
        closeHistory();
        const favorites = shell.querySelector('[data-foodex-nav-favorites]');
        if (favorites && !favorites.hidden && favorites.querySelector('button')) {
            favorites.scrollIntoView({ block: 'nearest' });
            favorites.querySelector('button')?.focus({ preventScroll: true });
            return;
        }
        const personalize = shell.querySelector('[data-personalize-toggle]');
        if (!personalize) return;
        if (personalize.getAttribute('aria-pressed') !== 'true') personalize.click();
        const firstFavorite = nav.querySelector('.foodex-nav-personalize-actions button[data-action="favorite"]');
        (firstFavorite || personalize).focus({ preventScroll: true });
    });
    historyButton.addEventListener('click', () => {
        if (!panel.hidden) {
            closeHistory();
            return;
        }
        renderHistory();
        panel.hidden = false;
        historyButton.setAttribute('aria-expanded', 'true');
        (recentList.querySelector('button') || panel).focus({ preventScroll: true });
    });
    recentList.addEventListener('click', event => {
        const button = event.target.closest('button[data-recent-item]');
        if (!button || !recentList.contains(button)) return;
        const link = openLinks().get(button.dataset.recentItem);
        closeHistory();
        if (link) link.click(); // Resolve only a current server-rendered authorized URL.
    });
    tools.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) {
            closeHistory();
            historyButton.focus({ preventScroll: true });
        }
    });
    document.addEventListener('click', event => {
        if (!panel.hidden && !tools.contains(event.target)) closeHistory();
    });
})();
</script>
