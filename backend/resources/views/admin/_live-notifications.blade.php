@php
    $foodexCanManageNotifications = $user->hasPermission('notifications.manage');
@endphp
<div class="foodex-live-notifications" data-foodex-live-notifications
     data-feed-url="{{ route('admin.notifications.live') }}"
     data-read-url="{{ route('admin.notifications.read', ['notification' => '__ID__']) }}"
     data-read-all-url="{{ route('admin.notifications.read-all') }}"
     data-center-url="{{ $foodexCanManageNotifications ? route('admin.notifications.index') : '' }}">
    <button class="foodex-live-bell" type="button" aria-expanded="false" aria-controls="foodex-live-panel"
            aria-label="{{ app()->getLocale()==='ar' ? 'الإشعارات الحية' : 'Live notifications' }}">
        @include('admin._premium-icon',['name'=>'bell'])
        <span class="foodex-live-count" data-live-count hidden>0</span>
    </button>
    <section id="foodex-live-panel" class="foodex-live-panel" hidden>
        <header>
            <strong>{{ app()->getLocale()==='ar' ? 'الإشعارات' : 'Notifications' }}</strong>
            <button type="button" class="foodex-live-read-all" data-live-read-all>
                {{ app()->getLocale()==='ar' ? 'تحديد الكل كمقروء' : 'Mark all read' }}
            </button>
        </header>
        <div class="foodex-live-state" data-live-state>
            {{ app()->getLocale()==='ar' ? 'جاري تحميل الإشعارات…' : 'Loading notifications…' }}
        </div>
        <div class="foodex-live-list" data-live-list></div>
        @if($foodexCanManageNotifications)
            <a class="foodex-live-center" href="{{ route('admin.notifications.index') }}">
                {{ app()->getLocale()==='ar' ? 'فتح مركز الإشعارات' : 'Open Notifications Center' }}
            </a>
        @endif
    </section>
    <div class="foodex-live-toasts" data-live-toasts aria-live="polite" aria-atomic="false"></div>
</div>

<style>
.foodex-live-notifications{position:relative;display:inline-flex;align-items:center}
.foodex-live-bell{position:relative;width:var(--foodex-touch-target);height:var(--foodex-touch-target);display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:50%;background:var(--foodex-surface);color:var(--foodex-ink);cursor:pointer}
.foodex-live-bell:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-live-count{position:absolute;inset-block-start:-5px;inset-inline-end:-6px;min-width:20px;height:20px;padding:0 5px;display:grid;place-items:center;border:2px solid var(--foodex-surface);border-radius:999px;background:var(--foodex-orange);color:#fff;font-size:.66rem;font-weight:var(--foodex-font-weight-bold)}
.foodex-live-panel{position:absolute;z-index:70;inset-block-start:calc(100% + 10px);inset-inline-end:0;width:min(390px,calc(100vw - 28px));max-height:min(560px,72vh);overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-raised);text-align:start}
.foodex-live-panel>header{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border)}
.foodex-live-read-all{border:0;background:transparent;color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);cursor:pointer}
.foodex-live-state{padding:18px 16px;color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
.foodex-live-item{display:grid;gap:4px;padding:13px 16px;border-bottom:1px solid var(--foodex-border);cursor:pointer;background:var(--foodex-surface)}
.foodex-live-item[data-unread="true"]{background:var(--foodex-green-soft)}
.foodex-live-item strong{font-size:var(--foodex-text-sm)}
.foodex-live-item p{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-xs);line-height:1.55}
.foodex-live-item time{color:var(--foodex-muted);font-size:.68rem}
.foodex-live-center{display:block;padding:12px 16px;text-align:center;color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold);font-size:var(--foodex-text-xs);text-decoration:none}
.foodex-live-toasts{position:fixed;z-index:100;inset-block-start:78px;inset-inline-end:20px;width:min(360px,calc(100vw - 32px));display:grid;gap:8px;pointer-events:none}
.foodex-live-toast{padding:12px 14px;border:1px solid #b7dfc4;border-radius:var(--foodex-radius-md);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-raised);animation:foodex-toast-in .18s ease-out}
.foodex-live-toast strong{display:block;margin-bottom:3px}.foodex-live-toast span{color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
.foodex-live-offline{border-color:#ffd0a6!important}
@keyframes foodex-toast-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@media(max-width:767px){.foodex-live-panel{position:fixed;inset-inline:14px;inset-block-start:70px;width:auto}.foodex-live-toasts{inset-inline:16px;width:auto}}
</style>

<script>
(() => {
    const root = document.querySelector('[data-foodex-live-notifications]');
    if (!root || root.dataset.initialized === '1') return;
    root.dataset.initialized = '1';

    const bell = root.querySelector('.foodex-live-bell');
    const panel = root.querySelector('.foodex-live-panel');
    const count = root.querySelector('[data-live-count]');
    const list = root.querySelector('[data-live-list]');
    const state = root.querySelector('[data-live-state]');
    const toasts = root.querySelector('[data-live-toasts]');
    const readAll = root.querySelector('[data-live-read-all]');
    const csrf = @json(csrf_token());
    let latestId = 0;
    let initialized = false;
    let timer = null;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[char]));

    const setCount = (value) => {
        const total = Math.max(0, Number(value) || 0);
        count.textContent = total > 99 ? '99+' : String(total);
        count.hidden = total === 0;
    };

    const itemHtml = (item) => {
        const date = item.published_at ? new Date(item.published_at).toLocaleString() : '';
        return `<article class="foodex-live-item" data-notification-id="${item.id}" data-unread="${item.read ? 'false' : 'true'}">
            <strong>${escapeHtml(item.title)}</strong>
            <p>${escapeHtml(item.body)}</p>
            <time>${escapeHtml(date)}</time>
        </article>`;
    };

    const toast = (item) => {
        const node = document.createElement('div');
        node.className = 'foodex-live-toast';
        node.innerHTML = `<strong>${escapeHtml(item.title)}</strong><span>${escapeHtml(item.body)}</span>`;
        toasts.prepend(node);
        window.setTimeout(() => node.remove(), 6000);
    };

    const schedule = (delay = 4000) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(poll, delay);
    };

    async function poll() {
        try {
            const url = new URL(root.dataset.feedUrl, window.location.origin);
            if (latestId > 0) url.searchParams.set('after_id', String(latestId));
            const response = await fetch(url, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('live-feed-' + response.status);
            const payload = await response.json();
            const items = Array.isArray(payload.data) ? payload.data : [];
            setCount(payload.meta?.unread_count ?? 0);
            root.classList.remove('foodex-live-offline');

            if (!initialized) {
                list.innerHTML = items.map(itemHtml).join('');
                state.hidden = items.length > 0;
                state.textContent = @json(app()->getLocale()==='ar' ? 'لا توجد إشعارات جديدة.' : 'No notifications yet.');
                initialized = true;
            } else if (items.length > 0) {
                items.slice().reverse().forEach((item) => {
                    list.insertAdjacentHTML('afterbegin', itemHtml(item));
                    toast(item);
                });
                state.hidden = true;
            }

            latestId = Math.max(latestId, Number(payload.meta?.latest_id ?? 0));
            schedule();
        } catch (_) {
            root.classList.add('foodex-live-offline');
            state.hidden = false;
            state.textContent = @json(app()->getLocale()==='ar' ? 'تعذر الاتصال اللحظي. ستتم إعادة المحاولة تلقائياً.' : 'Live connection unavailable. Retrying automatically.');
            schedule(8000);
        }
    }

    bell.addEventListener('click', () => {
        const opening = panel.hidden;
        panel.hidden = !opening;
        bell.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            panel.hidden = true;
            bell.setAttribute('aria-expanded', 'false');
        }
    });

    list.addEventListener('click', async (event) => {
        const item = event.target.closest('[data-notification-id]');
        if (!item || item.dataset.unread !== 'true') return;
        const url = root.dataset.readUrl.replace('__ID__', item.dataset.notificationId);
        const response = await fetch(url, {
            method:'POST',
            headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        if (response.ok) {
            item.dataset.unread = 'false';
            const current = Number(count.textContent) || 0;
            setCount(current - 1);
        }
    });

    readAll.addEventListener('click', async () => {
        const response = await fetch(root.dataset.readAllUrl, {
            method:'POST',
            headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
        });
        if (response.ok) {
            list.querySelectorAll('[data-unread="true"]').forEach((item) => item.dataset.unread = 'false');
            setCount(0);
        }
    });

    poll();
})();
</script>
