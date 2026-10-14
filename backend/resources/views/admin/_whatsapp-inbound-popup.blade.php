@auth
<style id="foodex-whatsapp-inbound-popup-styles">
.foodex-whatsapp-inbound-popup{position:fixed;z-index:12500;inset:0;display:grid;place-items:center;padding:24px;pointer-events:none}
.foodex-whatsapp-inbound-popup[hidden]{display:none!important}
.foodex-whatsapp-inbound-card{pointer-events:auto;width:min(520px,calc(100vw - 32px));display:grid;gap:14px;padding:20px;background:color-mix(in srgb,var(--foodex-red) 9%,var(--foodex-surface));color:var(--foodex-ink);border:1px solid rgba(239,83,80,.34);border-radius:var(--foodex-radius-card);box-shadow:0 26px 72px rgba(23,32,51,.28)}
.foodex-whatsapp-inbound-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.foodex-whatsapp-inbound-title{display:flex;align-items:center;gap:10px;font-size:1.08rem;font-weight:var(--foodex-font-weight-bold)}
.foodex-whatsapp-inbound-dot{width:11px;height:11px;border-radius:999px;background:var(--foodex-red);box-shadow:0 0 0 6px rgba(239,83,80,.12)}
.foodex-whatsapp-inbound-close{border:0;background:transparent;color:var(--foodex-muted);font:inherit;font-size:24px;line-height:1;cursor:pointer;padding:2px 6px}
.foodex-whatsapp-inbound-customer{display:grid;gap:4px;padding:11px 12px;border-radius:12px;background:rgba(255,255,255,.68);border:1px solid rgba(239,83,80,.18)}
.foodex-whatsapp-inbound-customer strong{font-size:1rem}
.foodex-whatsapp-inbound-phone{direction:ltr;text-align:start;color:var(--foodex-muted);font-size:.88rem}
.foodex-whatsapp-inbound-preview{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.96rem;line-height:1.6}
.foodex-whatsapp-inbound-meta{display:flex;gap:10px 14px;align-items:center;flex-wrap:wrap;color:var(--foodex-muted);font-size:.8rem}
.foodex-whatsapp-inbound-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.foodex-whatsapp-inbound-open{min-height:44px;border:0;border-radius:10px;padding:0 17px;background:var(--foodex-red);color:#fff;font:inherit;font-weight:var(--foodex-font-weight-bold);cursor:pointer}
.foodex-whatsapp-inbound-dismiss{min-height:44px;border:1px solid rgba(239,83,80,.28);border-radius:10px;padding:0 15px;background:rgba(255,255,255,.82);color:var(--foodex-ink);font:inherit;font-weight:var(--foodex-font-weight-bold);cursor:pointer}
.foodex-whatsapp-inbound-count{margin-inline-start:auto;color:var(--foodex-muted);font-size:.78rem}
@media(max-width:560px){.foodex-whatsapp-inbound-popup{padding:12px}.foodex-whatsapp-inbound-card{padding:17px}}
</style>
<script id="foodex-whatsapp-inbound-popup-runtime">
(() => {
    const feedUrl = @json(route('admin.whatsapp-inbound-alerts.feed', [], false));
    const dismissUrlTemplate = @json(route('admin.whatsapp-inbound-alerts.dismiss', ['alert' => '__ID__'], false));
    const openUrlTemplate = @json(route('admin.whatsapp-inbound-alerts.open', ['alert' => '__ID__'], false));
    const csrfToken = @json(csrf_token());
    const userId = @json((int) auth()->id());
    const isArabic = @json(app()->getLocale() === 'ar');
    const labels = {
        title: @json(__('whatsapp.inbound_popup.title')),
        open: @json(__('whatsapp.inbound_popup.open')),
        dismiss: @json(__('whatsapp.inbound_popup.dismiss')),
        close: @json(__('whatsapp.inbound_popup.close')),
        store: @json(__('whatsapp.inbound_popup.store')),
        more: @json(__('whatsapp.inbound_popup.more_waiting')),
    };

    const tabId = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    const claimPrefix = 'foodex:whatsapp-alert:claim:' + userId + ':';
    const channel = typeof BroadcastChannel !== 'undefined'
        ? new BroadcastChannel('foodex-whatsapp-inbound-alerts:' + userId)
        : null;

    let cursor = 0;
    let queue = [];
    let polling = false;
    let stopped = false;
    let failureCount = 0;
    let timer = null;
    let host = null;

    const create = (tag, className, text) => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined && text !== null) el.textContent = String(text);
        return el;
    };

    const claimKey = (id) => claimPrefix + String(id);

    const releaseClaim = (id, announce = true) => {
        try {
            const key = claimKey(id);
            const current = JSON.parse(localStorage.getItem(key) || 'null');
            if (!current || current.tab === tabId) localStorage.removeItem(key);
        } catch (_) {}
        if (announce) channel?.postMessage({type: 'release', alert_id: Number(id)});
    };

    const claim = (item) => {
        const id = Number(item?.alert_id);
        if (!Number.isFinite(id) || id <= 0) return false;

        try {
            const key = claimKey(id);
            const now = Date.now();
            const current = JSON.parse(localStorage.getItem(key) || 'null');
            if (current && current.tab !== tabId && now - Number(current.at || 0) < 45000) {
                return false;
            }

            localStorage.setItem(key, JSON.stringify({tab: tabId, at: now}));
            const verified = JSON.parse(localStorage.getItem(key) || 'null');
            if (verified?.tab !== tabId) return false;
        } catch (_) {}

        channel?.postMessage({type: 'claim', alert_id: id});
        return true;
    };

    const ensureHost = () => {
        if (host) return host;

        host = create('section', 'foodex-whatsapp-inbound-popup');
        host.hidden = true;
        host.setAttribute('role', 'dialog');
        host.setAttribute('aria-live', 'assertive');
        host.setAttribute('aria-label', labels.title);
        document.body.appendChild(host);

        return host;
    };

    const formatTime = (value) => {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';

        return new Intl.DateTimeFormat(isArabic ? 'ar-KW' : 'en-KW', {
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    };

    const removeAlert = (id) => {
        const numeric = Number(id);
        queue = queue.filter((item) => Number(item.alert_id) !== numeric);
        releaseClaim(numeric, false);
        render();
    };

    const acknowledge = async (item, action) => {
        const id = Number(item?.alert_id);
        if (!Number.isFinite(id)) return;

        const template = action === 'open' ? openUrlTemplate : dismissUrlTemplate;
        const endpoint = template.replace('__ID__', String(id));

        try {
            const response = await fetch(endpoint, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-FOODEX-BACKGROUND': '1'
                },
                cache: 'no-store'
            });

            if (response.status === 401 || response.status === 403 || response.status === 404) {
                removeAlert(id);
                channel?.postMessage({type: 'ack', alert_id: id});
                if (response.status !== 404) stopped = true;
                return;
            }
            if (!response.ok) throw new Error('whatsapp-alert-ack-' + response.status);

            const payload = await response.json();
            removeAlert(id);
            channel?.postMessage({type: 'ack', alert_id: id});

            if (action === 'open') {
                const target = String(payload?.url || item?.url || '');
                if (target) window.location.assign(target);
            }
        } catch (_) {
            schedule(Math.min(15000, 2500 * Math.max(1, failureCount + 1)));
        }
    };

    const render = () => {
        const popup = ensureHost();
        popup.replaceChildren();
        const item = queue[0];

        if (!item) {
            popup.hidden = true;
            return;
        }

        popup.hidden = false;
        const card = create('article', 'foodex-whatsapp-inbound-card');

        const head = create('div', 'foodex-whatsapp-inbound-head');
        const title = create('div', 'foodex-whatsapp-inbound-title');
        title.append(create('span', 'foodex-whatsapp-inbound-dot'), create('span', '', labels.title));

        const close = create('button', 'foodex-whatsapp-inbound-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', labels.close);
        close.addEventListener('click', () => acknowledge(item, 'dismiss'));
        head.append(title, close);

        const identity = create('div', 'foodex-whatsapp-inbound-customer');
        identity.append(create('strong', '', item.customer_name || item.customer_phone || 'WhatsApp'));
        if (item.customer_phone) {
            identity.append(create('span', 'foodex-whatsapp-inbound-phone', item.customer_phone));
        }

        const preview = create('p', 'foodex-whatsapp-inbound-preview', item.preview || '');
        const meta = create('div', 'foodex-whatsapp-inbound-meta');
        const time = formatTime(item.occurred_at);
        if (time) meta.append(create('span', '', time));
        if (item.store_name) meta.append(create('span', '', labels.store + ': ' + item.store_name));

        const actions = create('div', 'foodex-whatsapp-inbound-actions');
        const open = create('button', 'foodex-whatsapp-inbound-open', labels.open);
        open.type = 'button';
        open.addEventListener('click', () => acknowledge(item, 'open'));

        const dismiss = create('button', 'foodex-whatsapp-inbound-dismiss', labels.dismiss);
        dismiss.type = 'button';
        dismiss.addEventListener('click', () => acknowledge(item, 'dismiss'));

        actions.append(open, dismiss);
        if (queue.length > 1) {
            actions.append(create(
                'span',
                'foodex-whatsapp-inbound-count',
                '+' + (queue.length - 1) + ' ' + labels.more
            ));
        }

        card.append(head, identity, preview, meta, actions);
        popup.append(card);
    };

    const enqueue = (items) => {
        const existing = new Set(queue.map((item) => Number(item.alert_id)));

        for (const item of Array.isArray(items) ? items : []) {
            const id = Number(item?.alert_id);
            if (!Number.isFinite(id) || existing.has(id) || !claim(item)) continue;
            existing.add(id);
            queue.push(item);
        }

        queue.sort((a, b) => {
            const at = Date.parse(a.occurred_at || '') || 0;
            const bt = Date.parse(b.occurred_at || '') || 0;
            return at === bt ? Number(a.alert_id) - Number(b.alert_id) : at - bt;
        });

        render();
    };

    const schedule = (delay) => {
        clearTimeout(timer);
        if (stopped) return;
        timer = setTimeout(poll, delay);
    };

    const poll = async () => {
        if (stopped || polling) return;

        if (document.visibilityState !== 'visible') {
            schedule(5000);
            return;
        }

        polling = true;
        try {
            const url = new URL(feedUrl, window.location.origin);
            if (cursor > 0) url.searchParams.set('after', String(cursor));

            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-FOODEX-BACKGROUND': '1'
                },
                cache: 'no-store'
            });

            if ([401, 403, 404].includes(response.status)) {
                stopped = true;
                clearTimeout(timer);
                return;
            }
            if (!response.ok) throw new Error('whatsapp-alert-feed-' + response.status);

            const payload = await response.json();
            const nextCursor = Number(payload?.meta?.cursor);
            if (Number.isFinite(nextCursor) && nextCursor >= cursor) cursor = nextCursor;

            enqueue(payload?.data || []);
            failureCount = 0;
            schedule(4000);
        } catch (_) {
            failureCount += 1;
            schedule(Math.min(30000, 4000 * Math.max(1, failureCount)));
        } finally {
            polling = false;
        }
    };

    channel?.addEventListener('message', (event) => {
        const message = event?.data || {};
        const id = Number(message.alert_id);
        if (!Number.isFinite(id)) return;

        if (message.type === 'claim') {
            if (queue.some((item) => Number(item.alert_id) === id)) removeAlert(id);
        } else if (message.type === 'ack') {
            removeAlert(id);
        } else if (message.type === 'release') {
            if (id <= cursor) cursor = Math.max(0, id - 1);
            schedule(50);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !stopped) schedule(50);
    });

    window.addEventListener('beforeunload', () => {
        for (const item of queue) releaseClaim(item.alert_id, true);
    });

    const start = () => {
        ensureHost();
        poll();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, {once: true});
    } else {
        start();
    }
})();
</script>
@endauth
