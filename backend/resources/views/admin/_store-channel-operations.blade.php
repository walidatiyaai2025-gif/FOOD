@php
    $kpis = $storeChannelDashboard['kpis'] ?? [];
    $charts = $storeChannelDashboard['charts'] ?? [];
    $money = static function (array $amounts): string {
        if ($amounts === []) return '—';
        return collect($amounts)->map(fn ($amount, $currency) => number_format((float)$amount, 3).' '.$currency)->implode(' · ');
    };
    $maxRevenue = collect($charts['store_performance'] ?? [])->groupBy('currency')->map(fn ($rows) => max(1.0,(float)$rows->max('revenue')));
    $maxChannelRevenue = collect($charts['channel_performance'] ?? [])->groupBy('currency')->map(fn ($rows) => max(1.0,(float)$rows->max('revenue')));
    $maxHealth = max(1, (int) collect($charts['health_distribution'] ?? [])->max('value'));
    $maxAvailability = max(1, (int) collect($charts['availability'] ?? [])->max('value'));
@endphp
<style>
.store-ops-dashboard{display:grid;gap:var(--foodex-space-4)}.store-ops-accordion{margin-top:0}.store-ops-accordion-body{align-content:start}.store-ops-accordion .store-head{margin:0}
.store-ops-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.store-ops-filter label{display:grid;gap:6px;font-weight:700}
.store-ops-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.store-ops-kpi{padding:16px;display:grid;gap:6px}.store-ops-kpi small{color:var(--foodex-muted)}.store-ops-kpi strong{font-size:1.35rem}
.store-ops-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.store-ops-chart{padding:16px;display:grid;gap:12px}.store-ops-chart h3{margin:0}
.store-ops-bar-row{display:grid;grid-template-columns:minmax(110px,1fr) 2fr auto;gap:10px;align-items:center}
.store-ops-track{height:10px;border-radius:999px;background:var(--foodex-border);overflow:hidden}.store-ops-track>span{display:block;height:100%;background:var(--foodex-green);border-radius:inherit}
.store-ops-channel-grid,.store-ops-report-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.store-ops-channel,.store-ops-report{padding:14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);display:grid;gap:6px}
.store-ops-report-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.store-ops-report-actions{display:flex;gap:7px;flex-wrap:wrap}
@media(max-width:1024px){.store-ops-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.store-ops-report-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:768px){.store-ops-grid,.store-ops-channel-grid,.store-ops-report-grid{grid-template-columns:1fr}.store-ops-kpis{grid-template-columns:1fr 1fr}.store-ops-bar-row{grid-template-columns:minmax(90px,1fr) 1.4fr auto}}
@media(max-width:430px){.store-ops-kpis{grid-template-columns:1fr}.store-ops-bar-row{grid-template-columns:1fr auto}.store-ops-track{grid-column:1/-1}}
</style>

<section class="store-ops-dashboard foodex-module-overview" aria-labelledby="store-ops-title">
    <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.title') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
        <div class="store-head">
            <div>
                <span class="foodex-subtitle">FOODEX · {{ __('store_channels.title') }}</span>
                
                <p>{{ __('store_channels.subtitle') }}</p>
            </div>
            <form method="get" class="store-ops-filter">
                <label>{{ __('store_channels.period') }}
                    <input type="date" name="from" value="{{ $storeChannelDashboard['from'] }}">
                </label>
                <label><span class="sr-only">{{ __('store_channels.period') }}</span>
                    <input type="date" name="to" value="{{ $storeChannelDashboard['to'] }}">
                </label>
                @if($search)<input type="hidden" name="q" value="{{ $search }}">@endif
                <button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('store_channels.apply') }}</button>
            </form>
        </div>

        <div class="store-ops-kpis" data-pagination-exempt="bounded-dashboard-kpis">
            @foreach([
                ['active', $kpis['active_stores'] ?? 0],
                ['inactive', $kpis['inactive_stores'] ?? 0],
                ['sales_today', $money($kpis['sales_today'] ?? [])],
                ['sales_period', $money($kpis['sales_period'] ?? [])],
                ['orders', $kpis['orders_period'] ?? 0],
                ['availability', $kpis['availability_issues'] ?? 0],
                ['integrations', $kpis['integration_degradation'] ?? 0],
                ['ready', $kpis['ready_stores'] ?? 0],
            ] as [$label,$value])
                <div class="foodex-card store-ops-kpi">
                    <small>{{ __('store_channels.kpis.'.$label) }}</small>
                    <strong>{{ $value }}</strong>
                </div>
            @endforeach
        </div>
    
    </div>
</details>

    <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.channels') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
        
        <div class="store-ops-channel-grid">
            @forelse($storeChannelDashboard['channels'] ?? [] as $channel)
                <div class="store-ops-channel">
                    <strong>{{ __('store_channels.channels.'.$channel['channel']) }}</strong>
                    <span>{{ __('store_channels.labels.active') }}: {{ $channel['active'] }}</span>
                    <small>{{ __('store_channels.labels.total') }}: {{ $channel['total'] }}</small>
                </div>
            @empty
                <div class="foodex-empty-state">{{ __('store_channels.labels.no_data') }}</div>
            @endforelse
        </div>
    
    </div>
</details>

    <div class="store-ops-grid">
        <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations" data-pagination-exempt="bounded-store-performance">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.performance') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
            
            @forelse(array_slice($charts['store_performance'] ?? [],0,8) as $row)
                <div class="store-ops-bar-row">
                    <span>{{ $row['store'] }}</span>
                    <span class="store-ops-track" aria-label="{{ $row['store'] }} {{ $row['revenue'] }} {{ $row['currency'] }}"><span style="width:{{ min(100,($row['revenue']/($maxRevenue[$row['currency']] ?? 1))*100) }}%"></span></span>
                    <strong>{{ number_format($row['revenue'],3) }} {{ $row['currency'] }}</strong>
                </div>
            @empty <div class="foodex-empty-state">{{ __('store_channels.labels.no_data') }}</div> @endforelse
            <table class="foodex-table"><thead><tr><th>{{ __('store_channels.labels.store') }}</th><th>{{ __('store_channels.labels.orders') }}</th><th>{{ __('store_channels.labels.revenue') }}</th></tr></thead><tbody>
            @foreach(array_slice($charts['store_performance'] ?? [],0,8) as $row)<tr><td>{{ $row['store'] }}</td><td>{{ $row['orders'] }}</td><td>{{ number_format($row['revenue'],3) }} {{ $row['currency'] ?? '' }}</td></tr>@endforeach
            </tbody></table>
        
    </div>
</details>

        <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations" data-pagination-exempt="bounded-channel-performance">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.channels') }} · {{ __('store_channels.sections.performance') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
            
            @forelse($charts['channel_performance'] ?? [] as $row)
                <div class="store-ops-bar-row">
                    <span>{{ __('store_channels.order_channels.'.$row['channel']) }}</span>
                    <span class="store-ops-track" aria-label="{{ __('store_channels.order_channels.'.$row['channel']) }} {{ $row['revenue'] }} {{ $row['currency'] }}"><span style="width:{{ min(100,($row['revenue']/($maxChannelRevenue[$row['currency']] ?? 1))*100) }}%"></span></span>
                    <strong>{{ number_format($row['revenue'],3) }} {{ $row['currency'] ?? '' }}</strong>
                </div>
            @empty <div class="foodex-empty-state">{{ __('store_channels.labels.no_data') }}</div> @endforelse
        
    </div>
</details>

        <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.health') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
            
            @forelse($charts['health_distribution'] ?? [] as $row)
                <div class="store-ops-bar-row">
                    <span>{{ __('store_channels.states.'.$row['label']) }}</span>
                    <span class="store-ops-track"><span style="width:{{ min(100,($row['value']/$maxHealth)*100) }}%"></span></span>
                    <strong>{{ $row['value'] }}</strong>
                </div>
            @empty <div class="foodex-empty-state">{{ __('store_channels.labels.no_data') }}</div> @endforelse
        
    </div>
</details>

        <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.availability') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
            
            @forelse(array_slice($charts['availability'] ?? [],0,8) as $row)
                <div class="store-ops-bar-row">
                    <span>{{ $row['label'] }}</span>
                    <span class="store-ops-track"><span style="width:{{ min(100,($row['value']/$maxAvailability)*100) }}%"></span></span>
                    <strong>{{ $row['value'] }}</strong>
                </div>
            @empty <div class="foodex-empty-state">{{ __('store_channels.labels.no_data') }}</div> @endforelse
        
    </div>
</details>
    </div>

    <details class="foodex-card store-card store-accordion store-ops-accordion" data-page-accordion="operations">
    <summary aria-label="{{ app()->getLocale()==='ar'?'فتح أو إغلاق القسم':'Expand or collapse section' }}">
        <div class="store-accordion-summary-main">
            <div class="store-accordion-summary-text"><h3>{{ __('store_channels.sections.reports') }}</h3></div>
        </div>
        <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
    </summary>
    <div class="store-accordion-body store-ops-accordion-body">
        
        <div class="store-ops-report-grid">
            @foreach($storeChannelDashboard['reports'] ?? [] as $report)
                <div class="store-ops-report">
                    <strong>{{ __($report['label']) }}</strong>
                    <div class="store-ops-report-actions">
                        <a class="foodex-action-secondary button secondary" href="{{ route('admin.retail-stores.index',['export'=>$report['code'],'from'=>$storeChannelDashboard['from'],'to'=>$storeChannelDashboard['to']]) }}">{{ __('store_channels.labels.export') }}</a>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="store-inline-actions" style="margin-top:12px">
            @if(\Illuminate\Support\Facades\Route::has('admin.reports.index'))
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.reports.index') }}">{{ __('store_channels.labels.open_reports') }}</a>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('admin.erp.bi'))
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.bi') }}">{{ __('store_channels.labels.scheduled') }}</a>
            @endif
        </div>
    
    </div>
</details>
</section>
