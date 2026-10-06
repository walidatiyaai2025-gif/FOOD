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
    <title>Flash Analytics · FOODEX</title>
    @include('admin._brand')
    <style>
        .analytics-shell{max-width:1180px;margin:0 auto;padding:22px;display:grid;gap:16px}
        .analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
        .metric,.analytics-card{padding:16px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff}
        .metric strong{display:block;font-size:1.65rem;margin-top:6px}.muted{opacity:.72}
        .analytics-table{width:100%;border-collapse:collapse}.analytics-table th,.analytics-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start}
    </style>
</head>
<body>
@include('admin._account-menu', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
<main class="analytics-shell">
    <a href="{{ route('admin.commercial.flash-offers', $scope) }}">← {{ $ar ? 'العودة للعروض السريعة' : 'Back to Flash Offers' }}</a>
    <header>
        <h1>Flash Analytics · #{{ $offer->id }} {{ $offer->name }}</h1>
        <p class="muted">{{ $ar ? 'الأرقام أدناه مجمعة مباشرة من حجوزات وأحداث Flash المركزية.' : 'Metrics below are aggregated directly from canonical Flash reservations and events.' }}</p>
    </header>

    <div class="analytics-grid">
        <section class="metric"><span>{{ $ar ? 'إجمالي الحجوزات' : 'Reservations' }}</span><strong>{{ $reservationCount }}</strong></section>
        <section class="metric"><span>{{ $ar ? 'الوحدات الأساسية المحجوزة/المؤكدة' : 'Live reserved base' }}</span><strong>{{ number_format($liveReservedBase, 3) }}</strong></section>
        <section class="metric"><span>{{ $ar ? 'الوحدات المؤكدة' : 'Confirmed base' }}</span><strong>{{ number_format($confirmedBase, 3) }}</strong></section>
        <section class="metric"><span>{{ $ar ? 'أحداث Flash' : 'Flash events' }}</span><strong>{{ $eventCount }}</strong></section>
        <section class="metric"><span>{{ $ar ? 'استخدام التخصيص' : 'Allocation utilization' }}</span><strong>{{ $utilization === null ? '—' : number_format($utilization, 1).'%' }}</strong></section>
    </div>

    <section class="analytics-card">
        <h2>{{ $ar ? 'حالات الحجز' : 'Reservation States' }}</h2>
        <table class="analytics-table">
            <thead><tr><th>Status</th><th>Count</th><th>Base quantity</th></tr></thead>
            <tbody>
            @forelse($reservationStats as $stat)
                <tr><td>{{ $stat->status }}</td><td>{{ $stat->reservations_count }}</td><td>{{ number_format((float) $stat->base_quantity, 3) }}</td></tr>
            @empty
                <tr><td colspan="3">{{ $ar ? 'لا توجد حجوزات بعد.' : 'No reservations yet.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <div class="analytics-grid">
        <section class="analytics-card">
            <h2>{{ $ar ? 'الأحداث' : 'Events' }}</h2>
            <table class="analytics-table">
                <thead><tr><th>Event</th><th>Count</th></tr></thead>
                <tbody>
                @forelse($eventStats as $stat)
                    <tr><td>{{ $stat->event }}</td><td>{{ $stat->events_count }}</td></tr>
                @empty
                    <tr><td colspan="2">{{ $ar ? 'لا توجد أحداث بعد.' : 'No events yet.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        <section class="analytics-card">
            <h2>{{ $ar ? 'القنوات' : 'Channels' }}</h2>
            <table class="analytics-table">
                <thead><tr><th>Channel</th><th>Events</th></tr></thead>
                <tbody>
                @forelse($channelStats as $stat)
                    <tr><td>{{ $stat->channel }}</td><td>{{ $stat->events_count }}</td></tr>
                @empty
                    <tr><td colspan="2">{{ $ar ? 'لا توجد بيانات قنوات.' : 'No channel data yet.' }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>
</main>
</body>
</html>
