@php
    $metric = is_array($metric ?? null) ? $metric : [];
    $drilldownUrl = $metric['drilldown_url'] ?? null;
    $comparison = is_array($metric['comparison'] ?? null) ? $metric['comparison'] : null;
    $status = is_array($metric['status'] ?? null) ? $metric['status'] : null;
    $trend = data_get($comparison, 'trend', 'neutral');
    $trend = in_array($trend, ['up', 'down', 'neutral'], true) ? $trend : 'neutral';
    $statusState = data_get($status, 'state', 'current');
    $statusState = in_array($statusState, ['current', 'success', 'info', 'warning', 'danger', 'error', 'stale', 'disabled'], true)
        ? $statusState
        : 'current';
@endphp

@if($drilldownUrl)
<a
    class="foodex-module-kpi foodex-viz-card"
    data-foodex-module-kpi
    data-interactive="true"
    href="{{ $drilldownUrl }}"
>
@else
<article class="foodex-module-kpi foodex-viz-card" data-foodex-module-kpi data-interactive="false">
@endif
    <span class="foodex-module-kpi-label">{{ $metric['label'] ?? '—' }}</span>
    <strong class="foodex-module-kpi-value foodex-number">{{ $metric['value'] ?? '—' }}</strong>

    <span class="foodex-module-kpi-meta">
        @if($comparison)
            <span class="foodex-module-kpi-comparison" data-trend="{{ $trend }}">
                <strong>{{ data_get($comparison, 'value', '—') }}</strong>
                <span>{{ data_get($comparison, 'label', __('module_dashboard.comparison.previous_period')) }}</span>
            </span>
        @endif

        @if($status && data_get($status, 'label'))
            <span class="foodex-status-badge" data-state="{{ $statusState }}">
                {{ data_get($status, 'label') }}
            </span>
        @endif
    </span>

    @if(data_get($metric, 'last_refresh'))
        <small class="foodex-module-updated">
            {{ __('module_dashboard.updated_at', ['time' => data_get($metric, 'last_refresh')]) }}
        </small>
    @endif
@if($drilldownUrl)
</a>
@else
</article>
@endif
