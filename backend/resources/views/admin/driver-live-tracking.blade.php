<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.driver_live_tracking.title') }} · FOODEX</title>
@include('admin._brand-components')
<link rel="stylesheet" href="{{ asset('assets/leaflet/1.9.4/leaflet.css') }}">
<link rel="stylesheet" href="{{ asset('assets/admin/driver-live-map.css') }}">
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

        @include('admin._driver-live-map', [
            'feedUrl' => $feedUrl,
            'secondaryFeedUrl' => $vanFeedUrl,
            'trackingActor' => 'mixed',
            'trackingStores' => $trackingStores,
            'trackingI18n' => $trackingI18n,
            'liveMapMode' => 'full',
            'showFilters' => true,
            'showList' => true,
            'showSummary' => true,
            'pollMs' => 5000,
        ])
    </main>
</div>

@include('admin._driver-live-map-scripts')
</body>
</html>
