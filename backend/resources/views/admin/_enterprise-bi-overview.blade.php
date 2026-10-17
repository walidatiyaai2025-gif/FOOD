@php
    $snapshot = $overview['snapshot'] ?? null;
    $cards = is_array($snapshot) ? collect($snapshot['cards'] ?? [])->where('visible', true)->values() : collect();
    $analytics = $overview['analytics'] ?? [];
    $forecast = $overview['forecast'] ?? ['available'=>false];
    $savedView = $overview['saved_view'] ?? null;
@endphp

<section class="foodex-card bi-panel foodex-module-overview" data-enterprise-bi-overview>
    <div class="bi-head">
        <div>
            <span class="foodex-subtitle">{{ __('enterprise_bi_ui.overview.eyebrow') }}</span>
            <h2>{{ __('enterprise_bi_ui.overview.title') }}</h2>
            <p class="bi-muted">{{ __('enterprise_bi_ui.overview.help') }}</p>
        </div>
        <div class="bi-actions"><span class="bi-pill">{{ __('enterprise_bi_ui.overview.role_aware') }}</span></div>
    </div>

    <form method="get" action="{{ route('admin.erp.bi') }}" class="foodex-module-toolbar bi-overview-filters" data-foodex-module-filters>
        @if(request('report'))<input type="hidden" name="report" value="{{ request('report') }}">@endif
        <label class="foodex-module-scope">
            <span>{{ __('enterprise_bi_ui.overview.store_scope') }}</span>
            <select name="dashboard_store">
                @foreach(($overview['store_options'] ?? []) as $scope)
                    <option value="{{ $scope['key'] }}" @selected(($overview['selected_store']['key']??null)===$scope['key'])>{{ $scope['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="foodex-module-scope">
            <span>{{ __('enterprise_bi_ui.overview.entity_scope') }}</span>
            <select name="dashboard_entity">
                @foreach(($overview['entity_options'] ?? []) as $scope)
                    <option value="{{ $scope['key'] }}" @selected(($overview['selected_entity']['key']??null)===$scope['key'])>{{ $scope['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="foodex-module-date-range"><span>{{ __('enterprise_bi_ui.from') }}</span><input type="date" name="dashboard_from" value="{{ $overview['from'] }}"></label>
        <label class="foodex-module-date-range"><span>{{ __('enterprise_bi_ui.to') }}</span><input type="date" name="dashboard_to" value="{{ $overview['to'] }}"></label>
        <label><span>{{ __('enterprise_bi_ui.overview.currency') }}</span><input name="dashboard_currency" maxlength="3" value="{{ $overview['currency'] }}"></label>
        <button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('enterprise_bi_ui.overview.refresh') }}</button>
    </form>

    @if($overview['snapshot_error'])
        @include('admin._module-dashboard-state',['state'=>'error','title'=>__('enterprise_bi_ui.overview.error_title'),'message'=>__('enterprise_bi_ui.overview.error_help')])
    @elseif($cards->isEmpty())
        @include('admin._module-dashboard-state',['state'=>'empty','title'=>__('enterprise_bi_ui.overview.empty_title'),'message'=>__('enterprise_bi_ui.overview.empty_help')])
    @else
        <div class="foodex-module-kpis" style="margin-top:14px">
            @foreach($cards as $card)
                @php
                    $current = is_array($card['current']??null) ? $card['current'] : [];
                    $firstMeasure = array_key_first($current);
                    $currentValue = $firstMeasure === null ? '—' : ($current[$firstMeasure] ?? '—');
                    $trend = is_array($card['trend']??null) && $firstMeasure !== null ? ($card['trend'][$firstMeasure]??null) : null;
                    $comparison = is_array($trend) && $trend['percent']!==null
                        ? ['value'=>($trend['percent']>0?'+':'').$trend['percent'].'%','label'=>__('enterprise_bi_ui.overview.previous_period')]
                        : null;
                    $fresh = $card['freshness'] ?? [];
                    $status = ($fresh['stale']??false) ? 'warning' : (($card['available']??false)?'success':'neutral');
                @endphp
                @include('admin._module-dashboard-kpi',[
                    'metric'=>[
                        'label'=>__('enterprise_bi_ui.kpis.'.str_replace('.','_',$card['code'])),
                        'value'=>$card['available'] ? $currentValue : '—',
                        'comparison'=>$comparison===null?null:[...$comparison,'trend'=>(($trend['percent']??0)>0?'up':((($trend['percent']??0)<0)?'down':'neutral'))],
                        'status'=>['state'=>$status,'label'=>($fresh['stale']??false)?__('enterprise_bi_ui.overview.stale'):__('enterprise_bi_ui.overview.current')],
                        'last_refresh'=>$fresh['refreshed_at']??null,
                        'drilldown_url'=>route('admin.erp.bi',['report'=>$card['report']]),
                    ],
                ])
            @endforeach
        </div>
    @endif
</section>

<section class="bi-analytics-grid">
    @foreach(['orders','profitability','ar','ap','stock'] as $chartKey)
        @php
            $chart = $analytics[$chartKey] ?? null;
            $chartRows = is_array($chart) ? collect($chart['rows'] ?? [])->take(12) : collect();
            $measureKeys = $chartRows->isEmpty() ? [] : array_values(array_filter(array_keys($chartRows->first()), fn($k)=>$k!=='drill_down'));
            $dimensionKey = $measureKeys[0] ?? null;
            $valueKey = collect($measureKeys)->first(fn($key)=>$chartRows->contains(fn($row)=>isset($row[$key]) && is_numeric($row[$key])));
            $maxValue = $valueKey ? max(1,(float)$chartRows->max(fn($row)=>(float)($row[$valueKey]??0))) : 1;
        @endphp
        <article class="foodex-card bi-panel foodex-viz-card foodex-module-chart" data-foodex-module-chart data-bi-chart="{{ $chartKey }}">
            <div class="foodex-viz-header">
                <div><h3 class="foodex-viz-title">{{ __('enterprise_bi_ui.charts.'.$chartKey.'.title') }}</h3><p class="foodex-viz-subtitle">{{ __('enterprise_bi_ui.charts.'.$chartKey.'.help') }}</p></div>
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.bi',['report'=>data_get($chart,'report')]) }}">{{ __('enterprise_bi_ui.builder_title') }}</a>
            </div>
            @if($chartRows->isEmpty() || !$valueKey)
                @include('admin._module-dashboard-state',['state'=>'empty','message'=>__('enterprise_bi_ui.charts.no_data')])
            @else
                <div class="foodex-viz-bars bi-viz-bars" role="img" aria-label="{{ __('enterprise_bi_ui.charts.'.$chartKey.'.aria') }}">
                    @foreach($chartRows as $row)
                        @php
                            $labelValue = $dimensionKey ? ($row[$dimensionKey]??'—') : '—';
                            $numeric = (float)($row[$valueKey]??0);
                            $width = min(100,max(2,($numeric/$maxValue)*100));
                        @endphp
                        <div class="bi-viz-row">
                            <span class="bi-viz-label">{{ $labelValue }}</span>
                            <span class="bi-viz-track"><span class="bi-viz-fill" style="width:{{ $width }}%"></span></span>
                            <strong>{{ $row[$valueKey] }}</strong>
                        </div>
                    @endforeach
                </div>
                <div class="foodex-module-chart-summary">{{ __('enterprise_bi_ui.charts.source_note') }}</div>
                <div class="bi-table-wrap foodex-module-chart-table">
                    <table class="bi-table" data-pagination-required="bounded-dashboard" data-bounded-rows="12">
                        <thead><tr>@foreach(array_keys($chartRows->first()) as $key)@if($key!=='drill_down')<th>{{ __('enterprise_bi_ui.fields.'.$key) }}</th>@endif @endforeach</tr></thead>
                        <tbody>@foreach($chartRows as $row)<tr>@foreach($row as $key=>$value)@if($key!=='drill_down')<td>{{ $value }}</td>@endif @endforeach</tr>@endforeach</tbody>
                    </table>
                </div>
            @endif
        </article>
    @endforeach
</section>

<section class="foodex-card bi-panel">
    <div class="bi-head">
        <div><h2>{{ __('enterprise_bi_ui.forecast.title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.forecast.help') }}</p></div>
        @if($forecast['available']??false)<span class="bi-pill">{{ __('enterprise_bi_ui.forecast.method') }}</span>@endif
    </div>
    @if($forecast['available']??false)
        <div class="foodex-module-kpis" style="margin-top:12px">
            @include('admin._module-dashboard-kpi',['metric'=>['label'=>__('enterprise_bi_ui.forecast.next_7_days'),'value'=>$forecast['next_7_days_orders'],'status'=>['state'=>'info','label'=>__('enterprise_bi_ui.forecast.forecast')]]])
            @include('admin._module-dashboard-kpi',['metric'=>['label'=>__('enterprise_bi_ui.forecast.daily_average'),'value'=>$forecast['recent_daily_average'],'comparison'=>$forecast['trend_percent']===null?null:['value'=>($forecast['trend_percent']>0?'+':'').$forecast['trend_percent'].'%','label'=>__('enterprise_bi_ui.overview.previous_period'),'trend'=>$forecast['trend_percent']>0?'up':($forecast['trend_percent']<0?'down':'neutral')],'status'=>['state'=>'info','label'=>__('enterprise_bi_ui.forecast.forecast')]]])
            @include('admin._module-dashboard-kpi',['metric'=>['label'=>__('enterprise_bi_ui.forecast.observed_days'),'value'=>$forecast['observed_days'],'status'=>['state'=>'current','label'=>__('enterprise_bi_ui.overview.current')]]])
        </div>
        <p class="bi-muted">{{ __('enterprise_bi_ui.forecast.disclaimer',['date'=>$forecast['as_of']]) }}</p>
    @else
        @include('admin._module-dashboard-state',['state'=>'empty','title'=>__('enterprise_bi_ui.forecast.insufficient_title'),'message'=>__('enterprise_bi_ui.forecast.insufficient_help',['days'=>$forecast['observed_days']??0,'required'=>$forecast['required_days']??14])])
    @endif
</section>

@if(is_array($savedView))
<section class="foodex-card bi-panel">
    <div class="bi-head"><div><h2>{{ __('enterprise_bi_ui.saved_views.title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.saved_views.help') }}</p></div></div>
    <div class="foodex-enterprise-viewbar" data-enterprise-saved-views="enterprise.bi" style="margin-top:12px">
        <details class="foodex-enterprise-viewbar__save">
            <summary class="foodex-action-primary">{{ __('enterprise_grid.save_current') }}</summary>
            <form method="post" action="{{ route('admin.enterprise-saved-views.store') }}" class="foodex-enterprise-viewbar__form">
                @csrf
                <input type="hidden" name="surface_key" value="{{ $savedView['surface_key'] }}">
                <input type="hidden" name="scope_type" value="{{ $savedView['scope_type'] }}">
                <input type="hidden" name="scope_id" value="{{ $savedView['scope_id'] }}">
                <input type="hidden" name="state_token" value="{{ $savedView['state_token'] }}">
                <label><span>{{ __('enterprise_grid.view_name') }}</span><input name="name" maxlength="120" required></label>
                <label><span>{{ __('enterprise_grid.visibility') }}</span><select name="visibility"><option value="private">{{ __('enterprise_grid.private') }}</option><option value="shared">{{ __('enterprise_grid.shared') }}</option></select></label>
                <button class="foodex-action-primary" type="submit">{{ __('enterprise_grid.save_current') }}</button>
            </form>
        </details>
        <div class="foodex-enterprise-viewbar__items">
            @forelse($savedView['views'] as $view)
                <div class="foodex-enterprise-viewbar__item">
                    <a href="{{ route('admin.erp.bi',$view['query']) }}">{{ $view['name'] }}</a>
                    <span class="badge">{{ $view['visibility']==='shared'?__('enterprise_grid.shared'):__('enterprise_grid.private') }}</span>
                    @if($view['owned'])<form method="post" action="{{ route('admin.enterprise-saved-views.destroy',['savedView'=>$view['id']]) }}">@csrf @method('delete')<button class="foodex-enterprise-viewbar__delete">{{ __('enterprise_grid.delete') }}</button></form>@endif
                </div>
            @empty
                <div class="bi-empty">{{ __('enterprise_grid.none_saved') }}</div>
            @endforelse
        </div>
    </div>
</section>
@endif
