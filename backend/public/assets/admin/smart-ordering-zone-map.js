(() => {
    'use strict';

    if (window.__foodexSmartOrderingZoneMapBound) return;
    window.__foodexSmartOrderingZoneMapBound = true;

    const copy = window.FoodexSmartOrderingZoneMap || {};
    const defaultCenter = [29.3759, 47.9774];
    const maps = new WeakMap();

    const finite = (value) => Number.isFinite(Number(value));
    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const round = (value, digits) => Number(Number(value).toFixed(digits));

    const readZones = (editor) => {
        try {
            const rows = JSON.parse(editor.dataset.existingZones || '[]');
            return Array.isArray(rows) ? rows.filter((row) => finite(row.lat) && finite(row.lng) && finite(row.radius)) : [];
        } catch (_) {
            return [];
        }
    };

    const status = (editor, message) => {
        const node = editor.querySelector('[data-zone-map-status]');
        if (node) node.textContent = message || '';
    };

    const setInput = (input, value) => {
        if (!input) return;
        input.value = String(value);
        input.dispatchEvent(new Event('input', {bubbles:true}));
    };

    const init = (editor) => {
        if (!editor || maps.has(editor) || !window.L) return maps.get(editor);
        const canvas = editor.querySelector('[data-zone-map-canvas]');
        const form = editor.closest('[data-zone-form]');
        const latitude = form?.querySelector('[data-zone-latitude]');
        const longitude = form?.querySelector('[data-zone-longitude]');
        const radius = form?.querySelector('[data-zone-radius]');
        if (!canvas || !form || !latitude || !longitude || !radius) return null;

        const zones = readZones(editor);
        const currentId = editor.dataset.currentZone || '';
        const currentLat = finite(latitude.value) ? Number(latitude.value) : null;
        const currentLng = finite(longitude.value) ? Number(longitude.value) : null;
        const map = L.map(canvas, {zoomControl:true}).setView(
            currentLat !== null && currentLng !== null ? [currentLat, currentLng] : defaultCenter,
            currentLat !== null && currentLng !== null ? 13 : 10
        );
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19,
            attribution:'&copy; OpenStreetMap contributors'
        }).addTo(map);

        const existingBounds = [];
        zones.forEach((zone) => {
            if (String(zone.id || '') === currentId) return;
            const lat = Number(zone.lat);
            const lng = Number(zone.lng);
            const km = clamp(Number(zone.radius), 0.001, 1000);
            L.circle([lat, lng], {
                radius:km * 1000,
                weight:1,
                fillOpacity:0.08,
                interactive:false
            }).bindTooltip(String(zone.name || copy.existing || '')).addTo(map);
            existingBounds.push([lat, lng]);
        });

        let marker = null;
        let circle = null;
        let aborter = null;

        const syncRadius = () => {
            const km = clamp(Number(radius.value || 0), 0.001, 1000);
            if (circle && finite(km)) circle.setRadius(km * 1000);
        };

        const setCenter = (latValue, lngValue, recenter = true) => {
            const lat = clamp(Number(latValue), -90, 90);
            const lng = clamp(Number(lngValue), -180, 180);
            if (!finite(lat) || !finite(lng)) return;
            const point = [round(lat, 7), round(lng, 7)];
            latitude.value = String(point[0]);
            longitude.value = String(point[1]);
            if (!marker) {
                marker = L.marker(point, {draggable:true}).addTo(map);
                marker.on('dragend', () => {
                    const pos = marker.getLatLng();
                    setCenter(pos.lat, pos.lng, false);
                    status(editor, copy.selected || '');
                });
            } else {
                marker.setLatLng(point);
            }
            const km = clamp(Number(radius.value || 5), 0.001, 1000);
            if (!circle) {
                circle = L.circle(point, {radius:km * 1000, weight:2, fillOpacity:0.12, interactive:false}).addTo(map);
            } else {
                circle.setLatLng(point);
                circle.setRadius(km * 1000);
            }
            if (recenter) map.setView(point, Math.max(map.getZoom(), 13));
            status(editor, copy.selected || '');
        };

        if (currentLat !== null && currentLng !== null) {
            setCenter(currentLat, currentLng, false);
        } else if (existingBounds.length) {
            try { map.fitBounds(existingBounds, {padding:[24,24], maxZoom:12}); } catch (_) {}
        }

        map.on('click', (event) => setCenter(event.latlng.lat, event.latlng.lng, false));

        canvas.addEventListener('keydown', (event) => {
            if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','+','=','-','_'].includes(event.key)) return;
            event.preventDefault();
            const hasCenter = finite(latitude.value) && finite(longitude.value);
            const step = event.shiftKey ? 0.01 : 0.001;
            if (!hasCenter) {
                const center = map.getCenter();
                setCenter(center.lat, center.lng, false);
                return;
            }
            if (event.key === '+' || event.key === '=') {
                radius.value = String(round(clamp(Number(radius.value || 5) + 0.25, 0.001, 1000), 3));
                syncRadius();
                return;
            }
            if (event.key === '-' || event.key === '_') {
                radius.value = String(round(clamp(Number(radius.value || 5) - 0.25, 0.001, 1000), 3));
                syncRadius();
                return;
            }
            let lat = Number(latitude.value);
            let lng = Number(longitude.value);
            if (event.key === 'ArrowUp') lat += step;
            if (event.key === 'ArrowDown') lat -= step;
            if (event.key === 'ArrowRight') lng += step;
            if (event.key === 'ArrowLeft') lng -= step;
            setCenter(lat, lng, false);
        });

        radius.addEventListener('input', syncRadius);
        latitude.addEventListener('change', () => {
            if (finite(latitude.value) && finite(longitude.value)) setCenter(latitude.value, longitude.value, false);
        });
        longitude.addEventListener('change', () => {
            if (finite(latitude.value) && finite(longitude.value)) setCenter(latitude.value, longitude.value, false);
        });

        const searchInput = editor.querySelector('[data-zone-location-search]');
        const searchButton = editor.querySelector('[data-zone-search-button]');
        const results = editor.querySelector('[data-zone-search-results]');

        const runSearch = async () => {
            const query = (searchInput?.value || '').trim();
            if (!query || !results) return;
            if (aborter) aborter.abort();
            aborter = new AbortController();
            searchButton?.setAttribute('aria-busy', 'true');
            status(editor, copy.searching || '');
            results.hidden = true;
            results.replaceChildren();
            try {
                const response = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=5&q=' + encodeURIComponent(query), {
                    headers:{'Accept':'application/json'},
                    signal:aborter.signal
                });
                if (!response.ok) throw new Error('search_failed');
                const payload = await response.json();
                if (!Array.isArray(payload) || payload.length === 0) {
                    status(editor, copy.empty || '');
                    return;
                }
                payload.forEach((place) => {
                    if (!finite(place.lat) || !finite(place.lon)) return;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.setAttribute('role', 'option');
                    button.textContent = String(place.display_name || query);
                    button.addEventListener('click', () => {
                        setCenter(Number(place.lat), Number(place.lon), true);
                        results.hidden = true;
                        results.replaceChildren();
                        canvas.focus();
                    });
                    results.appendChild(button);
                });
                results.hidden = results.childElementCount === 0;
                if (results.hidden) status(editor, copy.empty || '');
            } catch (error) {
                if (error?.name !== 'AbortError') status(editor, copy.error || '');
            } finally {
                searchButton?.removeAttribute('aria-busy');
            }
        };

        searchButton?.addEventListener('click', runSearch);
        searchInput?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            runSearch();
        });

        form.addEventListener('submit', (event) => {
            if (!finite(latitude.value) || !finite(longitude.value)) {
                event.preventDefault();
                const advanced = form.querySelector('.so-zone-advanced');
                if (advanced) advanced.open = true;
                status(editor, copy.centerRequired || '');
                searchInput?.focus();
                return;
            }
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = '1';
            form.setAttribute('aria-busy', 'true');
            const button = form.querySelector('[data-zone-submit]');
            if (button) {
                button.disabled = true;
                button.textContent = copy.saving || button.textContent;
            }
        });

        const state = {map, setCenter, invalidate:() => window.setTimeout(() => map.invalidateSize(), 0)};
        maps.set(editor, state);
        return state;
    };

    const initDialog = (dialog) => {
        dialog.querySelectorAll('[data-zone-map-editor]').forEach((editor) => {
            const instance = init(editor);
            instance?.invalidate();
        });
    };

    document.querySelectorAll('dialog.so-dialog').forEach((dialog) => {
        if (dialog.open) initDialog(dialog);
        const observer = new MutationObserver(() => {
            if (dialog.open) initDialog(dialog);
        });
        observer.observe(dialog, {attributes:true, attributeFilter:['open']});
    });
})();
