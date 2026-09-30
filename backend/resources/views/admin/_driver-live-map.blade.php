@php
    $liveMapMode = $liveMapMode ?? 'full';
    $showFilters = $showFilters ?? true;
    $showList = $showList ?? true;
    $showSummary = $showSummary ?? true;
    $pollMs = $pollMs ?? 5000;
@endphp

<div
    class="driver-live-map-shell"
    data-driver-live-map
    data-mode="{{ $liveMapMode }}"
    data-show-list="{{ $showList ? '1' : '0' }}"
    data-feed-url="{{ $feedUrl }}"
    data-poll-ms="{{ $pollMs }}"
>
    <script type="application/json" data-driver-live-map-i18n>@json($trackingI18n)</script>

    @if ($showFilters)
        <section class="foodex-card tracking-filter-card" aria-label="{{ __('admin.driver_live_tracking.title') }}">
            <div class="tracking-filters">
                <label>{{ __('admin.driver_live_tracking.channel') }}
                    <select data-live-map="channel">
                        <option value="">{{ __('admin.driver_live_tracking.all_channels') }}</option>
                        <option value="b2b">B2B</option>
                        <option value="b2c">B2C</option>
                    </select>
                </label>
                <label>{{ __('admin.driver_live_tracking.store_id') }}<input data-live-map="store" type="number" min="1" inputmode="numeric"></label>
                <label>{{ __('admin.driver_live_tracking.status') }}
                    <select data-live-map="status-filter">
                        <option value="">{{ __('admin.driver_live_tracking.all_statuses') }}</option>
                        <option value="online">{{ __('admin.driver_live_tracking.online') }}</option>
                        <option value="stale">{{ __('admin.driver_live_tracking.stale') }}</option>
                        <option value="offline">{{ __('admin.driver_live_tracking.offline') }}</option>
                    </select>
                </label>
                <label>{{ __('admin.driver_live_tracking.driver_id') }}<input data-live-map="driver-id" type="number" min="1" inputmode="numeric"></label>
                <label>{{ __('admin.driver_live_tracking.order_id') }}<input data-live-map="order-id" type="number" min="1" inputmode="numeric"></label>
            </div>
            <div class="tracking-actions">
                <button class="btn btn-primary" type="button" data-live-map="apply">{{ __('admin.driver_live_tracking.apply') }}</button>
                <button class="btn" type="button" data-live-map="clear">{{ __('admin.driver_live_tracking.clear') }}</button>
            </div>
        </section>
    @endif

    @if ($showSummary)
        <div class="tracking-summary" aria-live="polite">
            <div><strong data-live-map="count-online">0</strong><small>{{ __('admin.driver_live_tracking.online') }}</small></div>
            <div><strong data-live-map="count-stale">0</strong><small>{{ __('admin.driver_live_tracking.stale') }}</small></div>
            <div><strong data-live-map="count-offline">0</strong><small>{{ __('admin.driver_live_tracking.offline') }}</small></div>
        </div>
    @endif

    <div class="tracking-error" data-live-map="error" hidden>{{ __('admin.driver_live_tracking.load_failed') }}</div>

    <div class="tracking-grid">
        <section class="foodex-card tracking-map-card">
            <div class="tracking-toolbar">
                <div>
                    <strong>{{ __('admin.driver_live_tracking.title') }}</strong>
                    <div class="tracking-status">
                        <span data-live-map="state">{{ __('admin.driver_live_tracking.loading') }}</span>
                        · {{ __('admin.driver_live_tracking.auto_refresh') }}
                    </div>
                </div>
                <button class="btn" type="button" data-live-map="recenter">{{ __('admin.driver_live_tracking.recenter') }}</button>
            </div>

            <div
                class="driver-live-map-canvas"
                data-live-map="map"
                data-map-provider="openstreetmap"
                data-map-library="leaflet-1.9.4"
            ></div>

            <div class="tracking-status tracking-last-updated">
                {{ __('admin.driver_live_tracking.last_updated') }}:
                <span data-live-map="updated">—</span>
            </div>
        </section>

        @if ($showList)
            <aside class="foodex-card tracking-list-card">
                <label class="tracking-search-label">{{ __('admin.driver_live_tracking.search') }}
                    <input data-live-map="search" type="search" autocomplete="off">
                </label>
                <h2 class="tracking-list-title">{{ __('admin.driver_live_tracking.drivers') }}</h2>
                <div data-live-map="list" class="tracking-list">
                    <div class="tracking-empty">{{ __('admin.driver_live_tracking.loading') }}</div>
                </div>
            </aside>
        @endif
    </div>
</div>
