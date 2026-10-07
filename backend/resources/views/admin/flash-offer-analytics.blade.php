@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
    $allocation = $offer->total_allocation_base === null ? null : (float) $offer->total_allocation_base;
    $utilization = $allocation && $allocation > 0 ? min(100, ($liveReservedBase / $allocation) * 100) : null;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('commercial.analytics.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;overflow-x:hidden;background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit}
        .commercial-evidence-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}
        .commercial-evidence-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);min-height:100vh}
        .commercial-evidence-layout>.evidence-shell{grid-column:1;grid-row:1;direction:rtl;min-width:0}
        html[dir=ltr] .commercial-evidence-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .commercial-evidence-layout>.sidebar{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .commercial-evidence-layout>.evidence-shell{grid-column:2;direction:ltr}
        .evidence-shell{width:100%;max-width:none!important;margin:0;padding:var(--foodex-space-6) clamp(var(--foodex-space-4),2vw,var(--foodex-space-8)) var(--foodex-space-8);display:grid;gap:var(--foodex-space-4)}
        .evidence-header{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);flex-wrap:wrap}
        .evidence-header h1{margin:3px 0 6px;font-size:clamp(1.55rem,2.2vw,2rem)}.muted{color:var(--foodex-muted)}
        .commercial-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:8px;box-shadow:var(--foodex-shadow-sm)}
        .commercial-tabs a{min-height:40px;padding:0 14px;border:1px solid transparent;border-radius:var(--foodex-radius-control);text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;color:var(--foodex-muted)}
        .commercial-tabs a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}
        .analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
        .metric,.analytics-card{padding:16px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
        .metric strong{display:block;font-size:1.65rem;margin-top:6px}
        .analytics-table{width:100%;border-collapse:collapse}.analytics-table th,.analytics-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start}
        @media(max-width:1023px){.commercial-evidence-layout{grid-template-columns:1fr!important}.commercial-evidence-layout>.sidebar,.commercial-evidence-layout>.evidence-shell{grid-column:1!important;grid-row:auto!important}.commercial-evidence-layout>.sidebar{min-height:auto}.evidence-shell{padding:14px}}
    </style>
</head>
<body>
<div class="foodex-admin-layout commercial-evidence-layout" data-commercial-analytics>
    <aside class="sidebar">
        @include('admin._sidebar', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
    </aside>
    <main class="foodex-admin-main foodex-admin-page evidence-shell">
        <header class="evidence-header">
            <div>
                <span class="foodex-subtitle">FOODEX · {{ __('commercial.flash.title') }}</span>
                <h1>{{ __('commercial.analytics.title') }} · {{ $offer->name }}</h1>
                <p class="muted">{{ __('commercial.analytics.description') }}</p>
            </div>
            @include('admin._live-notifications', ['user' => $user])
        </header>

        <nav class="commercial-tabs" aria-label="{{ __('commercial.tabs.aria') }}">
            <a href="{{ route('admin.commercial.sales-control', $scope) }}">{{ __('commercial.tabs.sales_control') }}</a>
            <a class="active" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ __('commercial.tabs.flash_offers') }}</a>
            <a href="{{ route('admin.b2c.module', ['module' => 'promotions'] + $scope) }}">{{ __('commercial.tabs.back_store') }}</a>
        </nav>

        <a href="{{ route('admin.commercial.flash-offers', $scope) }}">← {{ __('commercial.analytics.back') }}</a>

        <div class="analytics-grid">
            <section class="metric"><span>{{ __('commercial.analytics.reservations') }}</span><strong>{{ $reservationCount }}</strong></section>
            <section class="metric"><span>{{ __('commercial.analytics.live_reserved_base') }}</span><strong>{{ number_format($liveReservedBase, 3) }}</strong></section>
            <section class="metric"><span>{{ __('commercial.analytics.confirmed_base') }}</span><strong>{{ number_format($confirmedBase, 3) }}</strong></section>
            <section class="metric"><span>{{ __('commercial.analytics.flash_events') }}</span><strong>{{ $eventCount }}</strong></section>
            <section class="metric"><span>{{ __('commercial.analytics.allocation_utilization') }}</span><strong>{{ $utilization === null ? '—' : number_format($utilization, 1).'%' }}</strong></section>
        </div>

        <section class="analytics-card">
            <h2>{{ __('commercial.analytics.reservation_states') }}</h2>
            <div style="overflow:auto">
                <table class="analytics-table">
                    <thead><tr><th>{{ __('commercial.analytics.status') }}</th><th>{{ __('commercial.analytics.count') }}</th><th>{{ __('commercial.analytics.base_quantity') }}</th></tr></thead>
                    <tbody>
                    @forelse($reservationStats as $stat)
                        @php($statusKey = 'commercial.analytics.reservation_statuses.'.$stat->status)
                        <tr><td>{{ __($statusKey) === $statusKey ? __('commercial.analytics.unknown_status') : __($statusKey) }}</td><td>{{ $stat->reservations_count }}</td><td>{{ number_format((float) $stat->base_quantity, 3) }}</td></tr>
                    @empty
                        <tr><td colspan="3">{{ __('commercial.analytics.no_reservations') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="analytics-grid">
            <section class="analytics-card">
                <h2>{{ __('commercial.analytics.events') }}</h2>
                <div style="overflow:auto">
                    <table class="analytics-table">
                        <thead><tr><th>{{ __('commercial.analytics.event') }}</th><th>{{ __('commercial.analytics.count') }}</th></tr></thead>
                        <tbody>
                        @forelse($eventStats as $stat)
                            @php($eventKey = 'commercial.analytics.event_labels.'.$stat->event)
                            <tr><td>{{ __($eventKey) === $eventKey ? __('commercial.analytics.unknown_event') : __($eventKey) }}</td><td>{{ $stat->events_count }}</td></tr>
                        @empty
                            <tr><td colspan="2">{{ __('commercial.analytics.no_events') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="analytics-card">
                <h2>{{ __('commercial.analytics.channels') }}</h2>
                <div style="overflow:auto">
                    <table class="analytics-table">
                        <thead><tr><th>{{ __('commercial.analytics.channel') }}</th><th>{{ __('commercial.analytics.events') }}</th></tr></thead>
                        <tbody>
                        @forelse($channelStats as $stat)
                            @php($channelKey = 'commercial.channels.'.$stat->channel)
                            <tr><td>{{ __($channelKey) === $channelKey ? __('commercial.analytics.unknown_status') : __($channelKey) }}</td><td>{{ $stat->events_count }}</td></tr>
                        @empty
                            <tr><td colspan="2">{{ __('commercial.analytics.no_channel_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
