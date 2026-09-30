(() => {
    const roots = document.querySelectorAll('[data-driver-live-map]');
    if (!roots.length || typeof window.L === 'undefined') return;

    const colors = {online:'#16a34a',stale:'#f59e0b',offline:'#64748b'};

    roots.forEach(root => {
        if (root.dataset.driverLiveMapReady === '1') return;
        root.dataset.driverLiveMapReady = '1';

        const find = role => root.querySelector('[data-live-map="' + role + '"]');
        const i18nNode = root.querySelector('[data-driver-live-map-i18n]');
        let i18n = {};
        try {
            i18n = JSON.parse(i18nNode?.textContent || '{}');
        } catch (_) {
            i18n = {};
        }

        const mapNode = find('map');
        const feedUrl = root.dataset.feedUrl;
        if (!mapNode || !feedUrl) return;

        const map = L.map(mapNode,{zoomControl:true}).setView([29.3759,47.9774],11);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{
            maxZoom:19,
            attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        const layer = L.layerGroup().addTo(map);
        const markers = new Map();
        let latestRows = [];
        let fitted = false;
        let refreshing = false;

        const inputValue = role => find(role)?.value || '';
        const values = () => ({
            channel: inputValue('channel'),
            store_id: inputValue('store'),
            status: inputValue('status-filter'),
            driver_id: inputValue('driver-id'),
            order_id: inputValue('order-id'),
        });

        const queryUrl = () => {
            const url = new URL(feedUrl, window.location.origin);
            Object.entries(values()).forEach(([key,value]) => {
                if (value) url.searchParams.set(key,value);
            });
            return url.toString();
        };

        const text = (tag, value, className) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            node.textContent = value ?? '—';
            return node;
        };

        const statusLabel = status => {
            if (!status) return '—';
            return i18n.statuses?.[status] || status;
        };

        const popupFor = row => {
            const box = document.createElement('div');
            box.append(text('strong', row.driver_name || ('#'+row.driver_id)));
            box.append(text('div', (row.channel || '').toUpperCase()+' · '+i18n.store+' '+row.store_id));
            box.append(text('div', i18n.status+': '+statusLabel(row.status)));
            box.append(text('div', i18n.order+': '+(row.order?.number || '—')));
            box.append(text('div', i18n.lastSeen+': '+new Date(row.received_at).toLocaleString()));
            if (row.accuracy != null) box.append(text('div', i18n.accuracy+': '+Number(row.accuracy).toFixed(0)+' m'));
            if (row.speed != null) box.append(text('div', i18n.speed+': '+Number(row.speed).toFixed(1)+' m/s'));
            return box;
        };

        const visibleRows = () => {
            const q = inputValue('search').trim().toLowerCase();
            if (!q) return latestRows;
            return latestRows.filter(row => [
                row.driver_name,row.driver_id,row.order?.number,row.order?.id,row.store_id,row.channel,row.status,statusLabel(row.status)
            ].some(value => String(value ?? '').toLowerCase().includes(q)));
        };

        const updateCount = (key, value) => {
            const target = find('count-' + key);
            if (target) target.textContent = String(value);
        };

        const render = () => {
            const rows = visibleRows();
            layer.clearLayers();
            markers.clear();

            const list = find('list');
            if (list) list.replaceChildren();

            const counts = {online:0,stale:0,offline:0};
            latestRows.forEach(row => {
                if (counts[row.status] !== undefined) counts[row.status]++;
            });
            Object.entries(counts).forEach(([key,value]) => updateCount(key,value));

            if (!rows.length) {
                if (list) list.append(text('div', i18n.noDrivers, 'tracking-empty'));
                return;
            }

            const bounds = [];
            rows.forEach(row => {
                const lat = Number(row.latitude);
                const lng = Number(row.longitude);
                if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                const color = colors[row.status] || colors.offline;
                const marker = L.circleMarker([lat,lng],{
                    radius:9,color:'#fff',weight:2,fillColor:color,fillOpacity:1
                }).bindPopup(popupFor(row)).addTo(layer);

                markers.set(String(row.driver_id), marker);
                bounds.push([lat,lng]);

                if (list) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'tracking-driver';

                    const title = document.createElement('strong');
                    const dot = document.createElement('span');
                    dot.className = 'tracking-dot';
                    dot.style.backgroundColor = color;
                    title.append(dot, document.createTextNode(row.driver_name || ('#'+row.driver_id)));
                    button.append(title);
                    button.append(text(
                        'small',
                        (row.channel || '').toUpperCase()+' · '+i18n.store+' '+row.store_id+' · '+statusLabel(row.status)+' · '+(row.order?.number || '—')
                    ));
                    button.addEventListener('click',() => {
                        map.setView([lat,lng],16);
                        marker.openPopup();
                    });
                    list.append(button);
                }
            });

            if (!fitted && bounds.length) {
                map.fitBounds(bounds,{padding:[30,30],maxZoom:15});
                fitted = true;
            }
        };

        const refresh = async () => {
            if (refreshing) return;
            refreshing = true;

            const state = find('state');
            if (state) state.textContent = i18n.loading;

            try {
                const response = await fetch(queryUrl(),{
                    headers:{Accept:'application/json'},
                    credentials:'same-origin'
                });
                if (!response.ok) throw new Error('tracking-feed-'+response.status);

                const payload = await response.json();
                latestRows = Array.isArray(payload.data) ? payload.data : [];

                const error = find('error');
                if (error) error.hidden = true;
                if (state) state.textContent = latestRows.length ? (i18n.ready || 'OK') : i18n.noDrivers;

                const updated = find('updated');
                if (updated) {
                    updated.textContent = new Date(payload.meta?.generated_at || Date.now()).toLocaleString();
                }

                render();
            } catch (_) {
                const error = find('error');
                if (error) error.hidden = false;
                if (state) state.textContent = i18n.failed;
            } finally {
                refreshing = false;
            }
        };

        find('apply')?.addEventListener('click',() => {
            fitted=false;
            refresh();
        });

        find('clear')?.addEventListener('click',() => {
            ['channel','store','status-filter','driver-id','order-id','search'].forEach(role => {
                const control = find(role);
                if (control) control.value='';
            });
            fitted=false;
            refresh();
        });

        find('search')?.addEventListener('input',render);
        find('recenter')?.addEventListener('click',() => {
            const points = visibleRows()
                .map(row => [Number(row.latitude),Number(row.longitude)])
                .filter(point => point.every(Number.isFinite));
            if (points.length) map.fitBounds(points,{padding:[30,30],maxZoom:15});
        });

        root.foodexDriverLiveMap = {
            refresh,
            recenter: () => find('recenter')?.click(),
            rows: () => latestRows.slice(),
        };

        refresh();
        const pollMs = Math.max(1000, Number(root.dataset.pollMs || 5000));
        window.setInterval(refresh,pollMs);
    });
})();
