@php
    $liveMapMode = $liveMapMode ?? 'full';
    $trackingActor = $trackingActor ?? 'driver';
    $showFilters = $showFilters ?? true;
    $showList = $showList ?? true;
    $showSummary = $showSummary ?? true;
    $pollMs = $pollMs ?? 5000;
    $ctaUrl = $ctaUrl ?? null;
    $ctaLabel = $ctaLabel ?? __('admin.driver_live_tracking.view_full');
    $trackingI18n = array_merge($trackingI18n ?? [], [
        'assetsFailed' => __('admin.driver_live_tracking.assets_failed'),
        'mapFailed' => __('admin.driver_live_tracking.map_failed'),
        'sessionExpired' => __('admin.driver_live_tracking.session_expired'),
        'forbidden' => __('admin.driver_live_tracking.forbidden'),
        'serverFailed' => __('admin.driver_live_tracking.server_failed'),
        'networkFailed' => __('admin.driver_live_tracking.network_failed'),
        'invalidFeed' => __('admin.driver_live_tracking.invalid_feed'),
        'timeout' => __('admin.driver_live_tracking.timeout'),
    ]);
    $trackingI18n += [
        'noDrivers' => __('admin.driver_live_tracking.no_drivers'),
        'loading' => __('admin.driver_live_tracking.loading'),
        'failed' => __('admin.driver_live_tracking.load_failed'),
        'ready' => __('admin.driver_live_tracking.ready'),
        'store' => __('admin.driver_live_tracking.store'),
        'status' => __('admin.driver_live_tracking.status'),
        'statuses' => [
            'online' => __('admin.driver_live_tracking.online'),
            'stale' => __('admin.driver_live_tracking.stale'),
            'offline' => __('admin.driver_live_tracking.offline'),
        ],
        'order' => __('admin.driver_live_tracking.order'),
        'accuracy' => __('admin.driver_live_tracking.accuracy'),
        'speed' => __('admin.driver_live_tracking.speed'),
        'lastSeen' => __('admin.driver_live_tracking.last_seen'),
        'entitySingular' => $trackingActor === 'van' ? ($ar ?? false ? 'فان' : 'Van') : __('admin.driver_live_tracking.driver'),
        'entities' => $trackingActor === 'van' ? ($ar ?? false ? 'الفانات' : 'Vans') : __('admin.driver_live_tracking.drivers'),
        'entityId' => $trackingActor === 'van' ? ($ar ?? false ? 'رقم الفان' : 'Van ID') : __('admin.driver_live_tracking.driver_id'),
        'route' => $trackingActor === 'van' ? ($ar ?? false ? 'المسار' : 'Route') : __('admin.driver_live_tracking.order'),
    ];
@endphp

<div
    class="driver-live-map-shell"
    data-driver-live-map
    data-mode="{{ $liveMapMode }}"
    data-show-list="{{ $showList ? '1' : '0' }}"
    data-feed-url="{{ $feedUrl }}"
    data-poll-ms="{{ $pollMs }}"
    data-actor-kind="{{ $trackingActor }}"
    data-assets-failed="{{ __('admin.driver_live_tracking.assets_failed') }}"
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
                @if($trackingActor === 'van')
                    <label>{{ $trackingI18n['entityId'] ?? 'Van ID' }}<input data-live-map="actor-id" type="number" min="1" inputmode="numeric"></label>
                    <label>{{ $trackingI18n['route'] ?? 'Route' }}<input data-live-map="route-key" type="text"></label>
                @else
                    <label>{{ __('admin.driver_live_tracking.driver_id') }}<input data-live-map="driver-id" type="number" min="1" inputmode="numeric"></label>
                    <label>{{ __('admin.driver_live_tracking.order_id') }}<input data-live-map="order-id" type="number" min="1" inputmode="numeric"></label>
                @endif
            </div>
            <div class="tracking-actions">
                <button class="btn btn-primary" type="button" data-live-map="apply">{{ __('admin.driver_live_tracking.apply') }}</button>
                <button class="btn" type="button" data-live-map="clear">{{ __('admin.driver_live_tracking.clear') }}</button>
            </div>
        </section>
    @endif

    @if ($showSummary)
        <div class="tracking-summary" aria-live="polite">
            <div><strong data-live-map="count-online">—</strong><small>{{ __('admin.driver_live_tracking.online') }}</small></div>
            <div><strong data-live-map="count-stale">—</strong><small>{{ __('admin.driver_live_tracking.stale') }}</small></div>
            <div><strong data-live-map="count-offline">—</strong><small>{{ __('admin.driver_live_tracking.offline') }}</small></div>
        </div>
    @endif

    <div class="tracking-error" data-live-map="error" role="alert" hidden>
        <span data-live-map="error-message">{{ __('admin.driver_live_tracking.load_failed') }}</span>
        <a class="btn" href="" data-live-map="retry">{{ __('admin.driver_live_tracking.retry') }}</a>
    </div>
    <noscript><div role="alert">{{ __('admin.driver_live_tracking.assets_failed') }}</div></noscript>

    <div class="tracking-grid">
        <section class="foodex-card tracking-map-card">
            <div class="tracking-toolbar">
                <div>
                    <strong>{{ __('admin.driver_live_tracking.title') }}</strong>
                    <div class="tracking-status">
                        <span data-live-map="state" aria-live="polite">{{ __('admin.driver_live_tracking.loading') }}</span>
                        · {{ __('admin.driver_live_tracking.auto_refresh') }}
                    </div>
                </div>
                <div class="tracking-toolbar-actions">
                    @if ($ctaUrl)
                        <a class="btn" data-live-map-cta href="{{ $ctaUrl }}">{{ $ctaLabel }}</a>
                    @endif
                    <button class="btn" type="button" data-live-map="recenter">{{ __('admin.driver_live_tracking.recenter') }}</button>
                </div>
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
                <h2 class="tracking-list-title">{{ $trackingI18n['entities'] ?? __('admin.driver_live_tracking.drivers') }}</h2>
                <div data-live-map="list" class="tracking-list">
                    <div class="tracking-empty">{{ __('admin.driver_live_tracking.loading') }}</div>
                </div>
            </aside>
        @endif
    </div>
</div>
