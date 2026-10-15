(() => {
    const roots = document.querySelectorAll('[data-driver-live-map]');
    if (!roots.length) return;

    const colors = {online:'#16a34a',stale:'#f59e0b',offline:'#64748b'};

    roots.forEach(root => {
        if (root.dataset.driverLiveMapReady === '1') return;

        const find = role => root.querySelector('[data-live-map="' + role + '"]');
        const i18nNode = root.querySelector('[data-driver-live-map-i18n]');
        const storesNode = root.querySelector('[data-driver-live-map-stores]');
        let i18n = {};
        let storeLabels = {};
        try {
            i18n = JSON.parse(i18nNode?.textContent || '{}');
        } catch (_) {
            i18n = {};
        }
        try {
            storeLabels = JSON.parse(storesNode?.textContent || '{}');
        } catch (_) {
            storeLabels = {};
        }

        const mapNode = find('map');
        const feedUrl = root.dataset.feedUrl;
        const secondaryFeedUrl = root.dataset.secondaryFeedUrl || '';
        const lookupUrl = root.dataset.lookupUrl || '';
        const actorKind = root.dataset.actorKind || 'driver';
        const fail = (code, key, preserveRenderedData = false) => {
            const message = i18n[key] || i18n.failed || 'Unable to load live locations.';
            root.dataset.liveMapError = code;
            const error = find('error');
            if (error) error.hidden = preserveRenderedData;
            const detail = find('error-message');
            if (detail) detail.textContent = message;
            const state = find('state');
            if (state) state.textContent = message;
            if (preserveRenderedData) return;
            ['online','stale','offline'].forEach(status => {
                const counter = find('count-' + status);
                if (counter) counter.textContent = '—';
            });
            const list = find('list');
            if (list) list.textContent = message;
        };
        if (!mapNode || !feedUrl || (actorKind === 'mixed' && !secondaryFeedUrl)) {
            fail('map-configuration', 'mapFailed');
            return;
        }
        if (!window.L || typeof window.L.map !== 'function') {
            fail('map-assets', 'assetsFailed');
            return;
        }

        let map;
        let layer;
        try {
            map = L.map(mapNode,{zoomControl:true}).setView([26.8206,30.8025],6);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{
                maxZoom:19,
                attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            layer = L.layerGroup().addTo(map);
        } catch (_) {
            map?.remove();
            fail('map-initialization', 'mapFailed');
            return;
        }

        root.dataset.driverLiveMapReady = '1';
        const markers = new Map();
        let latestRows = [];
        let fitted = false;
        let refreshing = false;
        let consecutiveFailures = 0;
        let pollTimer = null;
        let activeController = null;
        let hasSuccessfulRender = false;
        let disposed = false;
        let suspended = false;
        let resumePending = false;
        const pollMs = Math.max(1000, Number(root.dataset.pollMs || 5000));
        const scheduleRefresh = (delay = pollMs) => {
            window.clearTimeout(pollTimer);
            pollTimer = null;
            if (disposed || suspended || root.isConnected === false) return;
            pollTimer = window.setTimeout(refresh, delay);
        };

        const inputValue = role => find(role)?.value || '';
        const rowKind = row => row.actor_type || (row.actor_id != null ? 'van' : 'driver');
        const kindLabel = kind => kind === 'van'
            ? (i18n.van || 'Van')
            : (i18n.driver || 'Driver');

        const commonValues = () => ({
            channel: inputValue('channel'),
            store_id: inputValue('store'),
            status: inputValue('status-filter'),
            order_id: inputValue('order-id'),
        });

        const selectedEntityKind = () => inputValue('entity-kind');
        const selectedEntityId = () => inputValue('entity-id');

        const values = kind => ({
            ...commonValues(),
            ...(kind === 'van' ? {
                actor_type: 'van',
                actor_id: selectedEntityKind() === 'van'
                    ? selectedEntityId()
                    : (actorKind === 'van' ? inputValue('actor-id') : ''),
                route_key: inputValue('route-key'),
            } : {
                driver_id: selectedEntityKind() === 'driver'
                    ? selectedEntityId()
                    : (actorKind === 'driver' ? inputValue('driver-id') : ''),
            }),
        });

        const queryUrl = (baseUrl, kind) => {
            const url = new URL(baseUrl, window.location.origin);
            Object.entries(values(kind)).forEach(([key,value]) => {
                if (value) url.searchParams.set(key,value);
            });
            return url.toString();
        };

        const feedSpecs = () => {
            if (actorKind === 'van') return [{url:feedUrl,kind:'van'}];
            if (actorKind === 'driver') return [{url:feedUrl,kind:'driver'}];

            const picked = selectedEntityKind();
            if (picked === 'driver') return [{url:feedUrl,kind:'driver'}];
            if (picked === 'van') return [{url:secondaryFeedUrl,kind:'van'}];
            if (inputValue('route-key')) return [{url:secondaryFeedUrl,kind:'van'}];

            const selected = inputValue('entity-type');
            if (selected === 'driver') return [{url:feedUrl,kind:'driver'}];
            if (selected === 'van') return [{url:secondaryFeedUrl,kind:'van'}];
            return [
                {url:feedUrl,kind:'driver'},
                {url:secondaryFeedUrl,kind:'van'},
            ];
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

        const entityId = row => row.actor_id ?? row.driver_id;
        const entityName = row => row.entity_name
            || row.driver_name
            || (kindLabel(rowKind(row))+' #'+entityId(row));
        const storeLabel = row => storeLabels[String(row.store_id)] || i18n.store || 'Store';
        const routeOrOrder = row => rowKind(row) === 'van'
            ? (row.route_key || '—')
            : (row.order?.number || '—');

        const popupFor = row => {
            const kind = rowKind(row);
            const box = document.createElement('div');
            box.append(text('strong', kindLabel(kind)+': '+entityName(row)));
            box.append(text('div', (row.channel || '').toUpperCase()+' · '+storeLabel(row)));
            box.append(text('div', i18n.status+': '+statusLabel(row.status)));
            box.append(text('div', (kind === 'van' ? (i18n.route || 'Route') : i18n.order)+': '+routeOrOrder(row)));
            if (kind === 'van' && row.assignment) {
                box.append(text(
                    'div',
                    (i18n.assignment || 'Assignment')+': '
                    +(row.assignment.operator_name || ('#'+row.assignment.id))
                    +' · '+(row.assignment.territory_key || '—')
                ));
            }
            if (kind === 'van' && row.van?.plate_number) box.append(text('div', row.van.plate_number));
            box.append(text('div', i18n.lastSeen+': '+new Date(row.received_at).toLocaleString()));
            if (row.accuracy != null) box.append(text('div', i18n.accuracy+': '+Number(row.accuracy).toFixed(0)+' m'));
            if (row.speed != null) box.append(text('div', i18n.speed+': '+Number(row.speed).toFixed(1)+' m/s'));
            return box;
        };

        const visibleRows = () => {
            const selectedKind = inputValue('entity-type');
            const q = inputValue('search').trim().toLowerCase();
            return latestRows.filter(row => {
                if (selectedKind && rowKind(row) !== selectedKind) return false;
                if (!q) return true;
                return [
                    kindLabel(rowKind(row)),entityName(row),entityId(row),row.order?.number,row.order?.id,
                    row.route_key,row.assignment?.territory_key,row.assignment?.operator_name,
                    row.van?.plate_number,row.store_id,row.channel,row.status,statusLabel(row.status)
                ].some(value => String(value ?? '').toLowerCase().includes(q));
            });
        };

        const updateCount = (key, value) => {
            const target = find('count-' + key);
            if (target) target.textContent = String(value);
        };

        const markerFor = (row, lat, lng, color) => {
            const kind = rowKind(row);
            if (typeof L.divIcon === 'function' && typeof L.marker === 'function') {
                const symbol = kind === 'van' ? '🚐' : '👤';
                const icon = L.divIcon({
                    className:'foodex-tracking-entity-marker foodex-tracking-entity-marker-'+kind+' foodex-tracking-entity-marker-'+(row.status || 'offline'),
                    html:'<span aria-hidden="true">'+symbol+'</span>',
                    iconSize:[30,30],
                    iconAnchor:[15,15],
                });
                return L.marker([lat,lng],{icon});
            }

            return L.circleMarker([lat,lng],{
                radius:kind === 'van' ? 11 : 9,
                color:'#fff',
                weight:2,
                fillColor:color,
                fillOpacity:1,
            });
        };

        const render = () => {
            const rows = visibleRows();
            layer.clearLayers();
            markers.clear();

            const list = find('list');
            if (list) list.replaceChildren();

            const counts = {online:0,stale:0,offline:0};
            rows.forEach(row => {
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
                const kind = rowKind(row);
                const marker = markerFor(row,lat,lng,color)
                    .bindPopup(popupFor(row))
                    .addTo(layer);

                markers.set(kind+':'+String(entityId(row)), marker);
                bounds.push([lat,lng]);

                if (list) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'tracking-driver tracking-'+kind;

                    const title = document.createElement('strong');
                    const dot = document.createElement('span');
                    dot.className = 'tracking-dot';
                    dot.style.backgroundColor = color;
                    title.append(dot, document.createTextNode(kindLabel(kind)+': '+entityName(row)));
                    button.append(title);
                    button.append(text(
                        'small',
                        (row.channel || '').toUpperCase()+' · '+storeLabel(row)+' · '+statusLabel(row.status)+' · '+routeOrOrder(row)
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

        const fetchPayload = async (spec, controller) => {
            let failure = ['feed-network', 'networkFailed'];
            try {
                const response = await fetch(queryUrl(spec.url,spec.kind),{
                    headers:{Accept:'application/json','X-FOODEX-BACKGROUND':'1'},
                    credentials:'same-origin',
                    signal:controller.signal,
                });
                if (!response.ok) {
                    failure = response.status === 401 ? ['feed-401', 'sessionExpired']
                        : response.status === 403 ? ['feed-403', 'forbidden']
                        : response.status === 503 ? ['feed-maintenance', 'serverFailed']
                        : response.status >= 500 ? ['feed-server', 'serverFailed']
                        : ['feed-http', 'failed'];
                    throw new Error('tracking-feed');
                }
                failure = ['feed-invalid', 'invalidFeed'];
                if (response.redirected) {
                    failure = ['feed-session', 'sessionExpired'];
                    throw new Error('tracking-session');
                }

                const payload = await response.json();
                if (!payload || !Array.isArray(payload.data)
                    || payload.data.some(row => !row || typeof row !== 'object' || Array.isArray(row))) {
                    throw new Error('tracking-payload');
                }
                return {payload,kind:spec.kind};
            } catch (error) {
                error.foodexFailure = failure;
                throw error;
            }
        };

        const refresh = async () => {
            if (disposed || suspended) return;
            if (root.isConnected === false) {
                dispose();
                return;
            }
            if (refreshing) return;
            window.clearTimeout(pollTimer);
            pollTimer = null;
            refreshing = true;

            const state = find('state');
            if (!hasSuccessfulRender && state) state.textContent = i18n.loading;

            const controller = new AbortController();
            activeController = controller;
            const timeout = window.setTimeout(() => controller.abort(), 10000);
            try {
                const payloads = await Promise.all(
                    feedSpecs().map(spec => fetchPayload(spec,controller))
                );
                latestRows = payloads.flatMap(({payload,kind}) =>
                    payload.data.map(row => ({
                        ...row,
                        actor_type: row.actor_type || kind,
                    }))
                );

                render();
                hasSuccessfulRender = true;
                consecutiveFailures = 0;
                const error = find('error');
                if (error) error.hidden = true;
                delete root.dataset.liveMapError;
                delete root.dataset.liveMapStale;
                if (state) state.textContent = latestRows.length ? (i18n.ready || 'OK') : i18n.noDrivers;

                const generatedTimes = payloads
                    .map(({payload}) => Date.parse(payload.meta?.generated_at || ''))
                    .filter(Number.isFinite);
                const updated = find('updated');
                if (updated) {
                    const generatedAt = generatedTimes.length ? Math.max(...generatedTimes) : Date.now();
                    updated.textContent = new Date(generatedAt).toLocaleString();
                }
            } catch (error) {
                if (controller.signal.aborted && controller.foodexPageCachePause === true) return;
                consecutiveFailures = Math.min(consecutiveFailures + 1,4);
                const classified = controller.signal.aborted
                    ? ['feed-timeout','timeout']
                    : (error.foodexFailure || ['feed-network','networkFailed']);
                fail(classified[0],classified[1],hasSuccessfulRender);
            } finally {
                window.clearTimeout(timeout);
                if (activeController === controller) activeController = null;
                refreshing = false;
                const maintenance = root.dataset.liveMapError === 'feed-maintenance';
                const retryDelay = consecutiveFailures === 0
                    ? pollMs
                    : Math.max(maintenance ? 30000 : 0, Math.min(60000,pollMs * (2 ** consecutiveFailures)));
                if (resumePending && !disposed && !suspended) {
                    resumePending = false;
                    scheduleRefresh(0);
                } else {
                    scheduleRefresh(retryDelay);
                }
            }
        };

        const lookupRegistry = new Map();
        const lookupDisposers = [];

        const setupLookup = name => {
            if (!lookupUrl) return null;
            const search = root.querySelector('[data-live-lookup-search="' + name + '"]');
            const results = root.querySelector('[data-live-lookup-results="' + name + '"]');
            const state = root.querySelector('[data-live-lookup-state="' + name + '"]');
            const clearButton = root.querySelector('[data-live-lookup-clear="' + name + '"]');
            if (!search || !results) return null;

            let timer = null;
            let controller = null;
            let requestSequence = 0;
            let options = [];
            let optionNodes = [];
            let activeIndex = -1;

            const hiddenControls = () => name === 'entity'
                ? [find('entity-id'), find('entity-kind')].filter(Boolean)
                : [find(name === 'order' ? 'order-id' : 'route-key')].filter(Boolean);

            const clearHidden = () => {
                hiddenControls().forEach(control => { control.value = ''; });
                delete search.dataset.selectedValue;
                delete search.dataset.selectedKind;
                if (clearButton) clearButton.hidden = search.value.trim() === '';
            };

            const setExpanded = expanded => {
                search.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                results.hidden = !expanded;
                if (!expanded) {
                    activeIndex = -1;
                    search.removeAttribute('aria-activedescendant');
                }
            };

            const setState = message => {
                if (state) state.textContent = message || '';
            };

            const markActive = index => {
                if (!optionNodes.length) return;
                activeIndex = Math.max(0, Math.min(index, optionNodes.length - 1));
                optionNodes.forEach((node, current) => {
                    node.setAttribute('aria-selected', current === activeIndex ? 'true' : 'false');
                });
                const active = optionNodes[activeIndex];
                if (active?.id) search.setAttribute('aria-activedescendant', active.id);
                active?.scrollIntoView?.({block:'nearest'});
            };

            const select = item => {
                if (!item || item.value === undefined || item.value === null) return;
                if (name === 'entity') {
                    const entityId = find('entity-id');
                    const entityKind = find('entity-kind');
                    if (entityId) entityId.value = String(item.value);
                    if (entityKind) entityKind.value = String(item.kind || '');
                    const entityType = find('entity-type');
                    if (entityType && ['driver','van'].includes(String(item.kind || ''))) {
                        entityType.value = String(item.kind);
                    }
                    if (item.kind === 'driver') lookupRegistry.get('route')?.clear();
                } else {
                    const hidden = find(name === 'order' ? 'order-id' : 'route-key');
                    if (hidden) hidden.value = String(item.value);
                    if (name === 'route') {
                        const entityType = find('entity-type');
                        if (entityType) entityType.value = 'van';
                        if (selectedEntityKind() === 'driver') lookupRegistry.get('entity')?.clear();
                    }
                }

                search.value = String(item.label || '');
                search.dataset.selectedValue = String(item.value);
                search.dataset.selectedKind = String(item.kind || name);
                if (clearButton) clearButton.hidden = false;
                setState(String(item.context || ''));
                setExpanded(false);
            };

            const renderOptions = rows => {
                options = Array.isArray(rows) ? rows : [];
                optionNodes = [];
                activeIndex = -1;
                results.replaceChildren();

                options.forEach((item, index) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'tracking-lookup-option';
                    option.id = (results.id || ('live-lookup-' + name)) + '-option-' + index;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');

                    const label = document.createElement('strong');
                    label.textContent = String(item.label || '—');
                    option.append(label);
                    if (item.context) {
                        const context = document.createElement('small');
                        context.textContent = String(item.context);
                        option.append(context);
                    }

                    option.addEventListener('mousedown', event => event.preventDefault());
                    option.addEventListener('click', () => select(item));
                    results.append(option);
                    optionNodes.push(option);
                });

                if (!options.length) {
                    setState(i18n.lookupNoResults || 'No matches.');
                    setExpanded(false);
                    return;
                }

                setState('');
                setExpanded(true);
            };

            const load = async () => {
                controller?.abort();
                controller = new AbortController();
                const sequence = ++requestSequence;
                setState(i18n.lookupLoading || 'Searching…');

                const url = new URL(lookupUrl, window.location.origin);
                const entityType = inputValue('entity-type');
                const kind = name === 'entity' && ['driver','van'].includes(entityType)
                    ? entityType
                    : name;
                url.searchParams.set('kind', kind);
                const query = search.value.trim();
                if (query) url.searchParams.set('q', query);
                const channel = inputValue('channel');
                const store = inputValue('store');
                if (channel) url.searchParams.set('channel', channel);
                if (store) url.searchParams.set('store_id', store);

                try {
                    const response = await fetch(url.toString(), {
                        credentials:'same-origin',
                        headers:{Accept:'application/json'},
                        signal:controller.signal,
                    });
                    if (!response.ok) throw new Error('lookup-http-' + response.status);
                    const payload = await response.json();
                    if (sequence !== requestSequence || !Array.isArray(payload?.data)) return;
                    renderOptions(payload.data);
                } catch (error) {
                    if (error?.name === 'AbortError' || sequence !== requestSequence) return;
                    options = [];
                    optionNodes = [];
                    results.replaceChildren();
                    setExpanded(false);
                    setState(i18n.lookupFailed || 'Unable to load options.');
                }
            };

            const schedule = () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(load, 250);
            };

            search.addEventListener('input', () => {
                if (search.dataset.selectedValue !== undefined) clearHidden();
                schedule();
            });
            search.addEventListener('focus', () => {
                if (options.length && search.dataset.selectedValue === undefined) {
                    setExpanded(true);
                    return;
                }
                if (search.dataset.selectedValue === undefined) load();
            });
            search.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (!results.hidden && optionNodes.length) markActive(activeIndex < 0 ? 0 : activeIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (!results.hidden && optionNodes.length) markActive(activeIndex < 0 ? optionNodes.length - 1 : activeIndex - 1);
                } else if (event.key === 'Enter') {
                    if (!results.hidden && activeIndex >= 0 && options[activeIndex]) {
                        event.preventDefault();
                        select(options[activeIndex]);
                    }
                } else if (event.key === 'Escape') {
                    setExpanded(false);
                }
            });
            search.addEventListener('blur', () => {
                window.setTimeout(() => setExpanded(false), 120);
            });
            clearButton?.addEventListener('click', () => {
                controller?.abort();
                search.value = '';
                clearHidden();
                options = [];
                optionNodes = [];
                results.replaceChildren();
                setState('');
                setExpanded(false);
                search.focus();
            });

            const api = {
                clear() {
                    controller?.abort();
                    window.clearTimeout(timer);
                    search.value = '';
                    clearHidden();
                    options = [];
                    optionNodes = [];
                    results.replaceChildren();
                    setState('');
                    setExpanded(false);
                },
                dispose() {
                    controller?.abort();
                    controller = null;
                    window.clearTimeout(timer);
                    timer = null;
                },
            };
            lookupRegistry.set(name, api);
            lookupDisposers.push(api.dispose);
            return api;
        };

        ['entity','order','route'].forEach(setupLookup);

        find('retry')?.addEventListener('click', event => {
            if (['feed-401','feed-session'].includes(root.dataset.liveMapError)) return;
            event.preventDefault();
            refresh();
        });

        find('apply')?.addEventListener('click',() => {
            fitted=false;
            refresh();
        });

        find('clear')?.addEventListener('click',() => {
            ['channel','store','entity-type','status-filter','driver-id','order-id','actor-id','route-key','entity-id','entity-kind','search'].forEach(role => {
                const control = find(role);
                if (control) control.value='';
            });
            lookupRegistry.forEach(lookup => lookup.clear());
            fitted=false;
            refresh();
        });

        find('search')?.addEventListener('input',render);
        ['channel','store'].forEach(role => {
            find(role)?.addEventListener('change',() => {
                lookupRegistry.get('entity')?.clear();
                lookupRegistry.get('order')?.clear();
                lookupRegistry.get('route')?.clear();
            });
        });
        find('entity-type')?.addEventListener('change',() => {
            lookupRegistry.get('entity')?.clear();
            if (inputValue('entity-type') === 'driver') lookupRegistry.get('route')?.clear();
            fitted=false;
            refresh();
        });
        find('recenter')?.addEventListener('click',() => {
            const points = visibleRows()
                .map(row => [Number(row.latitude),Number(row.longitude)])
                .filter(point => point.every(Number.isFinite));
            if (points.length) map.fitBounds(points,{padding:[30,30],maxZoom:15});
        });

        const suspendForPageCache = () => {
            if (disposed || suspended) return;
            suspended = true;
            resumePending = false;
            window.clearTimeout(pollTimer);
            pollTimer = null;
            if (activeController) {
                activeController.foodexPageCachePause = true;
                activeController.abort();
            }
            lookupDisposers.forEach(disposeLookup => disposeLookup());
            if (hasSuccessfulRender) {
                root.dataset.liveMapStale = '1';
                const state = find('state');
                if (state) state.textContent = i18n.restoring || i18n.loading;
            }
        };

        const resumeFromPageCache = () => {
            if (disposed || !suspended || root.isConnected === false) return;
            suspended = false;
            map.invalidateSize?.({animate:false});
            const state = find('state');
            if (hasSuccessfulRender) {
                root.dataset.liveMapStale = '1';
                if (state) state.textContent = i18n.restoring || i18n.loading;
            } else if (state) {
                state.textContent = i18n.loading;
            }
            if (refreshing) {
                resumePending = true;
                return;
            }
            refresh();
        };

        const onPageHide = event => {
            if (event?.persisted) {
                suspendForPageCache();
                return;
            }
            dispose();
        };

        const onPageShow = event => {
            if (event?.persisted) resumeFromPageCache();
        };

        const dispose = () => {
            if (disposed) return;
            disposed = true;
            suspended = false;
            resumePending = false;
            window.clearTimeout(pollTimer);
            pollTimer = null;
            activeController?.abort();
            activeController = null;
            lookupDisposers.forEach(disposeLookup => disposeLookup());
            map.remove();
            delete root.dataset.driverLiveMapReady;
            delete root.dataset.liveMapStale;
            window.removeEventListener('pagehide',onPageHide);
            window.removeEventListener('pageshow',onPageShow);
        };

        window.addEventListener('pagehide',onPageHide);
        window.addEventListener('pageshow',onPageShow);

        root.foodexDriverLiveMap = {
            refresh,
            recenter:() => find('recenter')?.click(),
            rows:() => latestRows.slice(),
            dispose,
        };

        refresh();
    });
})();
