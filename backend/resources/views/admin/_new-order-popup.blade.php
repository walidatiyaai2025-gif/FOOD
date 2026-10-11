@auth
<style id="foodex-new-order-popup-styles">
.foodex-new-order-popup{position:fixed;z-index:12000;inset-block-start:18px;inset-inline-end:18px;width:min(420px,calc(100vw - 28px));background:var(--foodex-surface);color:var(--foodex-ink);border:1px solid var(--foodex-border);border-radius:18px;box-shadow:0 22px 55px rgba(23,32,51,.22);padding:18px;display:grid;gap:14px}
.foodex-new-order-popup[hidden]{display:none!important}
.foodex-new-order-popup-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.foodex-new-order-popup-title{display:flex;align-items:center;gap:9px;font-size:1.05rem;font-weight:800}
.foodex-new-order-popup-dot{width:10px;height:10px;border-radius:999px;background:var(--foodex-green);box-shadow:0 0 0 6px rgba(21,138,58,.12)}
.foodex-new-order-popup-close{border:0;background:transparent;color:var(--foodex-muted);font:inherit;font-size:22px;line-height:1;cursor:pointer;padding:2px 6px}
.foodex-new-order-popup-identity{display:grid;gap:4px;padding:10px 12px;border-radius:12px;background:var(--foodex-soft);border:1px solid var(--foodex-border)}
.foodex-new-order-popup-identity strong{font-size:1rem;color:var(--foodex-ink)}
.foodex-new-order-popup-identity span{font-size:.9rem;color:var(--foodex-muted);direction:ltr;text-align:start}
.foodex-new-order-popup-number{font-size:1.15rem;font-weight:800;direction:ltr;text-align:start}
.foodex-new-order-popup-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;font-size:.9rem}
.foodex-new-order-popup-meta span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--foodex-muted)}
.foodex-new-order-popup-total{font-size:1.05rem;font-weight:800}
.foodex-new-order-popup-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.foodex-new-order-popup-open{min-height:42px;border:0;border-radius:10px;padding:0 16px;background:var(--foodex-green);color:white!important;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:800}
.foodex-new-order-popup-later{min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:0 14px;background:var(--foodex-surface);color:var(--foodex-ink);font:inherit;font-weight:700;cursor:pointer}
.foodex-new-order-popup-queue{margin-inline-start:auto;color:var(--foodex-muted);font-size:.8rem}
@media(max-width:520px){.foodex-new-order-popup{inset-block-start:10px;inset-inline:10px;width:auto}.foodex-new-order-popup-meta{grid-template-columns:1fr}}
</style>
<script id="foodex-new-order-popup-runtime">
(() => {
    const endpoint = @json(route('admin.operations.orders.new-order-feed', [], false));
    const storageKey = 'foodex:new-order-popup:cursor:{{ (int) auth()->id() }}';
    const isArabic = @json(app()->getLocale() === 'ar');
    const labels = isArabic ? {
        title: 'طلب جديد من التطبيق',
        open: 'فتح الطلب',
        later: 'لاحقًا',
        close: 'إغلاق',
        channel: 'القناة',
        store: 'المتجر',
        from: 'هذا الطلب من',
        phone: 'الهاتف',
        total: 'الإجمالي',
        queued: 'طلبات أخرى بالانتظار'
    } : {
        title: 'New order from app',
        open: 'View order',
        later: 'Later',
        close: 'Close',
        channel: 'Channel',
        store: 'Store',
        from: 'This order is from',
        phone: 'Phone',
        total: 'Total',
        queued: 'more orders waiting'
    };

    let cursor = Number.parseInt(sessionStorage.getItem(storageKey) || '', 10);
    if (!Number.isFinite(cursor) || cursor < 0) cursor = null;
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

    const ensureHost = () => {
        if (host) return host;
        host = create('section', 'foodex-new-order-popup');
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

    const formatTotal = (item) => {
        const amount = Number(item.grand_total || 0);
        const currency = String(item.currency || '');
        const number = Number.isFinite(amount)
            ? (currency.trim().toUpperCase() === 'EGP' ? amount.toFixed(2) : amount.toFixed(3))
            : (currency.trim().toUpperCase() === 'EGP' ? '0.00' : '0.000');
        return number + ' ' + currency;
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

        const head = create('div', 'foodex-new-order-popup-head');
        const title = create('div', 'foodex-new-order-popup-title');
        title.append(create('span', 'foodex-new-order-popup-dot'), create('span', '', labels.title));
        const close = create('button', 'foodex-new-order-popup-close', '×');
        close.type = 'button';
        close.setAttribute('aria-label', labels.close);
        close.addEventListener('click', () => {
            queue.shift();
            render();
        });
        head.append(title, close);

        const customerName = String(item.customer_name || '').trim() || '—';
        const customerPhone = String(item.customer_phone || '').trim();
        const identity = create('div', 'foodex-new-order-popup-identity');
        identity.append(create('strong', '', labels.from + ': ' + customerName));
        if (customerPhone) {
            identity.append(create('span', '', labels.phone + ': ' + customerPhone));
        }

        const number = create('div', 'foodex-new-order-popup-number', '#' + item.order_id);
        const meta = create('div', 'foodex-new-order-popup-meta');
        const channel = create('span', '', labels.channel + ': ' + String(item.channel || '').toUpperCase());
        const store = create('span', '', labels.store + ': ' + (item.store_name || ('#' + item.store_id)));
        const time = create('span', '', formatTime(item.created_at));
        meta.append(channel, store, time);

        const total = create('div', 'foodex-new-order-popup-total', labels.total + ': ' + formatTotal(item));
        const actions = create('div', 'foodex-new-order-popup-actions');
        const open = create('a', 'foodex-new-order-popup-open', labels.open);
        open.href = item.url;
        const later = create('button', 'foodex-new-order-popup-later', labels.later);
        later.type = 'button';
        later.addEventListener('click', () => {
            queue.shift();
            render();
        });
        actions.append(open, later);

        if (queue.length > 1) {
            actions.append(create(
                'span',
                'foodex-new-order-popup-queue',
                '+' + (queue.length - 1) + ' ' + labels.queued
            ));
        }

        popup.append(head, identity, number, meta, total, actions);
    };

    const enqueue = (items) => {
        const existing = new Set(queue.map((item) => String(item.notification_id)));
        for (const item of Array.isArray(items) ? items : []) {
            const key = String(item.notification_id || '');
            if (!key || existing.has(key)) continue;
            existing.add(key);
            queue.push(item);
        }
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
            const url = new URL(endpoint, window.location.origin);
            if (cursor !== null) url.searchParams.set('after', String(cursor));
            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                cache: 'no-store'
            });

            if (response.status === 401 || response.status === 403) {
                stopped = true;
                clearTimeout(timer);
                return;
            }
            if (!response.ok) throw new Error('new-order-feed-' + response.status);

            const payload = await response.json();
            const nextCursor = Number(payload?.meta?.cursor);
            if (Number.isFinite(nextCursor) && nextCursor >= 0) {
                cursor = nextCursor;
                sessionStorage.setItem(storageKey, String(cursor));
            }
            enqueue(payload?.data || []);
            failureCount = 0;
            schedule(5000);
        } catch (_) {
            failureCount += 1;
            schedule(Math.min(30000, 5000 * Math.max(1, failureCount)));
        } finally {
            polling = false;
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !stopped) schedule(50);
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
