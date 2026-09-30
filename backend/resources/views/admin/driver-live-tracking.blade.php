<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.driver_live_tracking.title') }} · FOODEX</title>
@include('admin._brand-components')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/1.9.4/leaflet.css') }}">
<style>
.tracking-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:var(--foodex-space-4)}
.tracking-map-card,.tracking-list-card,.tracking-filter-card{padding:var(--foodex-space-4)}
.tracking-filters{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:10px;align-items:end}
.tracking-filters label{display:grid;gap:6px;font-weight:700}.tracking-filters input,.tracking-filters select{width:100%}
.tracking-actions{display:flex;gap:8px;flex-wrap:wrap}.tracking-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}
.tracking-summary>div{padding:12px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}
.tracking-summary strong{display:block;font-size:1.6rem}.tracking-summary small{color:var(--foodex-muted)}
#driver-map{height:590px;border:1px solid var(--foodex-border);border-radius:18px;overflow:hidden;background:#eef2f5}
.tracking-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.tracking-status{color:var(--foodex-muted);font-size:.9rem}.tracking-list{display:grid;gap:8px;max-height:590px;overflow:auto}
.tracking-driver{width:100%;text-align:start;border:1px solid var(--foodex-border);background:#fff;border-radius:14px;padding:12px;cursor:pointer}
.tracking-driver:hover,.tracking-driver:focus{border-color:var(--foodex-green);outline:none}.tracking-driver strong{display:block}.tracking-driver small{color:var(--foodex-muted)}
.tracking-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-inline-end:6px}.tracking-empty{padding:28px;text-align:center;color:var(--foodex-muted)}
.tracking-error{padding:12px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:12px;margin-bottom:10px}
@media(max-width:1180px){.tracking-grid{grid-template-columns:1fr}.tracking-list{max-height:360px}.tracking-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.tracking-filters{grid-template-columns:1fr}#driver-map{height:480px}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-utility="driver-live-tracking">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · Operations</span>
                <h1>{{ __('admin.driver_live_tracking.title') }}</h1>
                <p>{{ __('admin.driver_live_tracking.description') }}</p>
            </div>
            <div class="foodex-header-actions">@include('admin._live-notifications',['user'=>$user])</div>
        </header>

        <section class="foodex-card tracking-filter-card" aria-label="{{ __('admin.driver_live_tracking.title') }}">
            <div class="tracking-filters">
                <label>{{ __('admin.driver_live_tracking.channel') }}
                    <select id="tracking-channel">
                        <option value="">{{ __('admin.driver_live_tracking.all_channels') }}</option>
                        <option value="b2b">B2B</option>
                        <option value="b2c">B2C</option>
                    </select>
                </label>
                <label>{{ __('admin.driver_live_tracking.store_id') }}<input id="tracking-store" type="number" min="1" inputmode="numeric"></label>
                <label>{{ __('admin.driver_live_tracking.status') }}
                    <select id="tracking-status-filter">
                        <option value="">{{ __('admin.driver_live_tracking.all_statuses') }}</option>
                        <option value="online">{{ __('admin.driver_live_tracking.online') }}</option>
                        <option value="stale">{{ __('admin.driver_live_tracking.stale') }}</option>
                        <option value="offline">{{ __('admin.driver_live_tracking.offline') }}</option>
                    </select>
                </label>
                <label>{{ __('admin.driver_live_tracking.driver_id') }}<input id="tracking-driver-id" type="number" min="1" inputmode="numeric"></label>
                <label>{{ __('admin.driver_live_tracking.order_id') }}<input id="tracking-order-id" type="number" min="1" inputmode="numeric"></label>
            </div>
            <div class="tracking-actions" style="margin-top:12px">
                <button class="btn btn-primary" type="button" id="tracking-apply">{{ __('admin.driver_live_tracking.apply') }}</button>
                <button class="btn" type="button" id="tracking-clear">{{ __('admin.driver_live_tracking.clear') }}</button>
            </div>
        </section>

        <div class="tracking-summary" aria-live="polite">
            <div><strong id="tracking-online">0</strong><small>{{ __('admin.driver_live_tracking.online') }}</small></div>
            <div><strong id="tracking-stale">0</strong><small>{{ __('admin.driver_live_tracking.stale') }}</small></div>
            <div><strong id="tracking-offline">0</strong><small>{{ __('admin.driver_live_tracking.offline') }}</small></div>
        </div>

        <div id="tracking-error" class="tracking-error" hidden>{{ __('admin.driver_live_tracking.load_failed') }}</div>

        <div class="tracking-grid">
            <section class="foodex-card tracking-map-card">
                <div class="tracking-toolbar">
                    <div>
                        <strong>{{ __('admin.driver_live_tracking.title') }}</strong>
                        <div class="tracking-status"><span id="tracking-state">{{ __('admin.driver_live_tracking.loading') }}</span> · {{ __('admin.driver_live_tracking.auto_refresh') }}</div>
                    </div>
                    <button class="btn" type="button" id="tracking-recenter">{{ __('admin.driver_live_tracking.recenter') }}</button>
                </div>
                <div id="driver-map" data-map-provider="openstreetmap" data-map-library="leaflet-1.9.4" data-feed-url="{{ $feedUrl }}"></div>
                <div class="tracking-status" style="margin-top:8px">
                    {{ __('admin.driver_live_tracking.last_updated') }}: <span id="tracking-updated">—</span>
                </div>
            </section>

            <aside class="foodex-card tracking-list-card">
                <label style="display:grid;gap:6px;font-weight:700">{{ __('admin.driver_live_tracking.search') }}
                    <input id="tracking-search" type="search" autocomplete="off">
                </label>
                <h2 style="font-size:1rem;margin:16px 0 10px">{{ __('admin.driver_live_tracking.drivers') }}</h2>
                <div id="tracking-list" class="tracking-list">
                    <div class="tracking-empty">{{ __('admin.driver_live_tracking.loading') }}</div>
                </div>
            </aside>
        </div>
    </main>
</div>

<script src="{{ asset('vendor/leaflet/1.9.4/leaflet.js') }}"></script>
<script>
(() => {
    const feedUrl = @json($feedUrl);
    const i18n = @json($trackingI18n);
    const colors = {online:'#16a34a',stale:'#f59e0b',offline:'#64748b'};
    const map = L.map('driver-map',{zoomControl:true}).setView([29.3759,47.9774],11);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{
        maxZoom:19,
        attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    const layer = L.layerGroup().addTo(map);
    const markers = new Map();
    let latestRows = [];
    let fitted = false;

    const el = id => document.getElementById(id);
    const values = () => ({
        channel: el('tracking-channel').value,
        store_id: el('tracking-store').value,
        status: el('tracking-status-filter').value,
        driver_id: el('tracking-driver-id').value,
        order_id: el('tracking-order-id').value,
    });

    const queryUrl = () => {
        const url = new URL(feedUrl, window.location.origin);
        Object.entries(values()).forEach(([key,value]) => { if (value) url.searchParams.set(key,value); });
        return url.toString();
    };

    const text = (tag, value, className) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        node.textContent = value ?? '—';
        return node;
    };

    const popupFor = row => {
        const box = document.createElement('div');
        box.append(text('strong', row.driver_name || ('#'+row.driver_id)));
        box.append(text('div', (row.channel || '').toUpperCase()+' · '+i18n.store+' '+row.store_id));
        box.append(text('div', i18n.order+': '+(row.order?.number || '—')));
        box.append(text('div', i18n.lastSeen+': '+new Date(row.received_at).toLocaleString()));
        if (row.accuracy != null) box.append(text('div', i18n.accuracy+': '+Number(row.accuracy).toFixed(0)+' m'));
        if (row.speed != null) box.append(text('div', i18n.speed+': '+Number(row.speed).toFixed(1)+' m/s'));
        return box;
    };

    const visibleRows = () => {
        const q = el('tracking-search').value.trim().toLowerCase();
        if (!q) return latestRows;
        return latestRows.filter(row => [
            row.driver_name,row.driver_id,row.order?.number,row.order?.id,row.store_id,row.channel,row.status
        ].some(v => String(v ?? '').toLowerCase().includes(q)));
    };

    const render = () => {
        const rows = visibleRows();
        layer.clearLayers();
        markers.clear();
        const list = el('tracking-list');
        list.replaceChildren();

        const counts = {online:0,stale:0,offline:0};
        latestRows.forEach(row => { if (counts[row.status] !== undefined) counts[row.status]++; });
        Object.entries(counts).forEach(([key,value]) => el('tracking-'+key).textContent = String(value));

        if (!rows.length) {
            list.append(text('div', i18n.noDrivers, 'tracking-empty'));
            return;
        }

        const bounds = [];
        rows.forEach(row => {
            const lat = Number(row.latitude), lng = Number(row.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            const color = colors[row.status] || colors.offline;
            const marker = L.circleMarker([lat,lng],{
                radius:9,color:'#fff',weight:2,fillColor:color,fillOpacity:1
            }).bindPopup(popupFor(row)).addTo(layer);
            markers.set(String(row.driver_id), marker);
            bounds.push([lat,lng]);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tracking-driver';
            const title = document.createElement('strong');
            const dot = document.createElement('span');
            dot.className = 'tracking-dot'; dot.style.backgroundColor = color;
            title.append(dot, document.createTextNode(row.driver_name || ('#'+row.driver_id)));
            button.append(title);
            button.append(text('small',(row.channel || '').toUpperCase()+' · '+i18n.store+' '+row.store_id+' · '+(row.order?.number || '—')));
            button.addEventListener('click',() => { map.setView([lat,lng],16); marker.openPopup(); });
            list.append(button);
        });

        if (!fitted && bounds.length) {
            map.fitBounds(bounds,{padding:[30,30],maxZoom:15});
            fitted = true;
        }
    };

    const refresh = async () => {
        el('tracking-state').textContent = i18n.loading;
        try {
            const response = await fetch(queryUrl(),{headers:{Accept:'application/json'},credentials:'same-origin'});
            if (!response.ok) throw new Error('tracking-feed-'+response.status);
            const payload = await response.json();
            latestRows = Array.isArray(payload.data) ? payload.data : [];
            el('tracking-error').hidden = true;
            el('tracking-state').textContent = latestRows.length ? 'OK' : i18n.noDrivers;
            el('tracking-updated').textContent = new Date(payload.meta?.generated_at || Date.now()).toLocaleString();
            render();
        } catch (_) {
            el('tracking-error').hidden = false;
            el('tracking-state').textContent = i18n.failed;
        }
    };

    el('tracking-apply').addEventListener('click',() => { fitted=false; refresh(); });
    el('tracking-clear').addEventListener('click',() => {
        ['tracking-channel','tracking-store','tracking-status-filter','tracking-driver-id','tracking-order-id','tracking-search']
            .forEach(id => el(id).value='');
        fitted=false; refresh();
    });
    el('tracking-search').addEventListener('input',render);
    el('tracking-recenter').addEventListener('click',() => {
        const points = visibleRows().map(r => [Number(r.latitude),Number(r.longitude)]).filter(p => p.every(Number.isFinite));
        if (points.length) map.fitBounds(points,{padding:[30,30],maxZoom:15});
    });

    refresh();
    window.setInterval(refresh,5000);
})();
</script>
</body>
</html>
