@php
    $dashboardKpis = $dashboard['kpis'] ?? [];
    $lastRefresh = isset($dashboard['as_of']) ? \Illuminate\Support\Carbon::parse($dashboard['as_of'])->format('Y-m-d H:i') : null;
    $baseQuery = array_filter(['store_id' => $storeId, 'warehouse_id' => $warehouseId]);
    $metricCards = [
        [
            'label' => __('enterprise_wms.overview.dashboard.inventory_value'),
            'value' => \App\Support\DisplayNumber::money((float)($dashboardKpis['inventory_value'] ?? 0), null),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'valuation'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.available'),
            'value' => \App\Support\DisplayNumber::quantity((float)($dashboardKpis['available'] ?? 0)),
            'comparison' => ['value' => \App\Support\DisplayNumber::quantity((float)($dashboardKpis['reserved'] ?? 0)), 'label' => __('enterprise_wms.overview.dashboard.reserved'), 'trend' => 'neutral'],
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'reservations'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.low_out'),
            'value' => number_format((int)($dashboardKpis['low_stock'] ?? 0)).' / '.number_format((int)($dashboardKpis['out_of_stock'] ?? 0)),
            'status' => ['label' => __('enterprise_wms.overview.dashboard.low').' / '.__('enterprise_wms.overview.dashboard.out'), 'state' => ((int)($dashboardKpis['out_of_stock'] ?? 0)) > 0 ? 'danger' : 'warning'],
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'reorder'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.slow_dead'),
            'value' => \App\Support\DisplayNumber::quantity((float)($dashboardKpis['slow_stock'] ?? 0)).' / '.\App\Support\DisplayNumber::quantity((float)($dashboardKpis['dead_stock'] ?? 0)),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'aging'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.expiring'),
            'value' => number_format((int)($dashboardKpis['expiring_lots'] ?? 0)),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'lots'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.transfers_counts'),
            'value' => number_format((int)($dashboardKpis['open_transfers'] ?? 0)).' / '.number_format((int)($dashboardKpis['active_counts'] ?? 0)),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'transfers'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.count_variance'),
            'value' => \App\Support\DisplayNumber::quantity((float)($dashboardKpis['count_variance'] ?? 0)),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'counts'])),
        ],
        [
            'label' => __('enterprise_wms.overview.dashboard.exceptions'),
            'value' => number_format((int)($dashboardKpis['adjustment_exceptions'] ?? 0)),
            'drilldown_url' => route('admin.erp.wms.index', array_merge($baseQuery, ['tab' => 'adjustments'])),
        ],
    ];
    foreach ($metricCards as &$metricCard) {
        $metricCard['last_refresh'] = $lastRefresh;
    }
    unset($metricCard);

    $stockStatus = $dashboard['charts']['stock_status'] ?? [];
    $stockTotal = max(1, array_sum(array_map('intval', $stockStatus)));
    $stockHealthyEnd = round(((int)($stockStatus['healthy'] ?? 0) / $stockTotal) * 100, 2);
    $stockLowEnd = round($stockHealthyEnd + (((int)($stockStatus['low'] ?? 0) / $stockTotal) * 100), 2);

    $agingChart = $dashboard['charts']['aging'] ?? [];
    $agingTotal = max(0.001, array_sum(array_map('floatval', $agingChart)));
    $agingActiveEnd = round(((float)($agingChart['active'] ?? 0) / $agingTotal) * 100, 2);
    $agingSlowEnd = round($agingActiveEnd + (((float)($agingChart['slow'] ?? 0) / $agingTotal) * 100), 2);

    $valueRows = collect($dashboard['charts']['value_by_warehouse'] ?? []);
    $valueMax = max(1, (float)$valueRows->max('value'));
    $movementRowsChart = collect($dashboard['charts']['movement_by_type'] ?? []);
    $movementMax = max(1, (float)$movementRowsChart->max('quantity'));
    $expiryChart = $dashboard['charts']['expiry_horizon'] ?? [];
    $expiryMax = max(1, ...array_map('intval', array_values($expiryChart ?: [0])));
@endphp

<div class="foodex-module-overview" data-foodex-module-overview data-state="current">
    <div class="foodex-module-kpis wms-dashboard-kpis">
        @foreach($metricCards as $metric)
            @include('admin._module-dashboard-kpi', ['metric' => $metric])
        @endforeach
    </div>

    <div class="wms-dashboard-grid">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header">
                <div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.stock_status') }}</h3></div>
                <a href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'reorder'])) }}">{{ __('enterprise_wms.common.open') }}</a>
            </div>
            <div class="wms-donut-row">
                <div class="foodex-viz-donut" role="img" aria-label="{{ __('enterprise_wms.overview.dashboard.stock_status') }}" style="--foodex-viz-p1:{{ $stockHealthyEnd }}%;--foodex-viz-p2:{{ $stockLowEnd }}%;--foodex-viz-p3:100%"></div>
                <div class="foodex-viz-legend">
                    <span class="foodex-viz-legend-item"><i class="foodex-viz-swatch"></i>{{ __('enterprise_wms.overview.dashboard.healthy') }} · {{ (int)($stockStatus['healthy'] ?? 0) }}</span>
                    <span class="foodex-viz-legend-item"><i class="foodex-viz-swatch" data-series="warning"></i>{{ __('enterprise_wms.overview.dashboard.low') }} · {{ (int)($stockStatus['low'] ?? 0) }}</span>
                    <span class="foodex-viz-legend-item"><i class="foodex-viz-swatch" data-series="danger"></i>{{ __('enterprise_wms.overview.dashboard.out') }} · {{ (int)($stockStatus['out'] ?? 0) }}</span>
                </div>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.value_by_warehouse') }}</h3></div></div>
            @if($valueRows->isEmpty())
                @include('admin._module-dashboard-state', ['state' => 'empty'])
            @else
                <div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_wms.overview.dashboard.value_by_warehouse') }}">
                    @foreach($valueRows as $row)
                        <span class="foodex-viz-bar" style="height:{{ max(3,round(((float)$row->value/$valueMax)*100,1)) }}%" title="{{ $row->name }} · {{ \App\Support\DisplayNumber::money((float)$row->value,null) }}"></span>
                    @endforeach
                </div>
                <div class="foodex-module-chart-summary">
                    @foreach($valueRows as $row)<span>{{ $row->name }}: <strong>{{ \App\Support\DisplayNumber::money((float)$row->value,null) }}</strong></span>@endforeach
                </div>
            @endif
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.movement_types') }}</h3></div><a href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'movements'])) }}">{{ __('enterprise_wms.common.open') }}</a></div>
            @if($movementRowsChart->isEmpty())
                @include('admin._module-dashboard-state', ['state' => 'empty'])
            @else
                <div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_wms.overview.dashboard.movement_types') }}">
                    @foreach($movementRowsChart as $row)
                        <span class="foodex-viz-bar" style="height:{{ max(3,round(((float)$row->quantity/$movementMax)*100,1)) }}%" title="{{ $movementLabel((string)$row->type) }} · {{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}"></span> {{-- localization-gate: allow raw movement type only as input to the locale-aware $movementLabel helper. --}}
                    @endforeach
                </div>
                <div class="foodex-module-chart-summary">
                    @foreach($movementRowsChart as $row)<span>{{ $movementLabel((string)$row->type) }}: <strong>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</strong></span>@endforeach {{-- localization-gate: allow raw movement type only as input to the locale-aware $movementLabel helper. --}}
                </div>
            @endif
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.aging') }}</h3></div><a href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'aging'])) }}">{{ __('enterprise_wms.common.open') }}</a></div>
            <div class="wms-donut-row">
                <div class="foodex-viz-donut" role="img" aria-label="{{ __('enterprise_wms.overview.dashboard.aging') }}" style="--foodex-viz-p1:{{ $agingActiveEnd }}%;--foodex-viz-p2:{{ $agingSlowEnd }}%;--foodex-viz-p3:100%"></div>
                <div class="foodex-viz-legend">
                    <span class="foodex-viz-legend-item">{{ __('enterprise_wms.overview.dashboard.active') }} · {{ \App\Support\DisplayNumber::quantity((float)($agingChart['active'] ?? 0)) }}</span>
                    <span class="foodex-viz-legend-item">{{ __('enterprise_wms.overview.dashboard.slow') }} · {{ \App\Support\DisplayNumber::quantity((float)($agingChart['slow'] ?? 0)) }}</span>
                    <span class="foodex-viz-legend-item">{{ __('enterprise_wms.overview.dashboard.dead') }} · {{ \App\Support\DisplayNumber::quantity((float)($agingChart['dead'] ?? 0)) }}</span>
                </div>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.expiry_horizon') }}</h3></div><a href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'lots'])) }}">{{ __('enterprise_wms.common.open') }}</a></div>
            <div class="foodex-viz-bars" role="img" aria-label="{{ __('enterprise_wms.overview.dashboard.expiry_horizon') }}">
                @foreach($expiryChart as $bucket => $count)
                    <span class="foodex-viz-bar" style="height:{{ max(3,round(((int)$count/$expiryMax)*100,1)) }}%" title="{{ __('enterprise_wms.overview.dashboard.expiry_buckets.'.$bucket) }} · {{ $count }}"></span>
                @endforeach
            </div>
            <div class="foodex-module-chart-summary">@foreach($expiryChart as $bucket => $count)<span>{{ __('enterprise_wms.overview.dashboard.expiry_buckets.'.$bucket) }}: <strong>{{ $count }}</strong></span>@endforeach</div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart>
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.transfer_status') }}</h3></div><a href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'transfers'])) }}">{{ __('enterprise_wms.common.open') }}</a></div>
            <div class="foodex-module-chart-summary wms-chart-list">
                @forelse(($dashboard['charts']['transfer_status'] ?? []) as $status => $count)
                    <span><span class="foodex-status-badge" data-state="{{ $status }}">{{ __('enterprise_wms.transfers.statuses.'.$status) }}</span><strong>{{ $count }}</strong></span>
                @empty
                    <span>{{ __('enterprise_wms.common.none') }}</span>
                @endforelse
            </div>
        </article>
    </div>

    <section class="foodex-viz-card">
        <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('enterprise_wms.overview.dashboard.exception_panel') }}</h3></div></div>
        <div class="wms-exception-list">
            @forelse(($dashboard['exceptions'] ?? []) as $exception)
                <a href="{{ $exception['url'] }}">
                    <span class="foodex-status-badge" data-tone="{{ $exception['type']==='low_stock' ? 'warning' : ($exception['type']==='expiry' ? 'warning' : 'info') }}">{{ $exception['label'] }}</span> {{-- localization-gate: allow exception type only as a semantic tone selector; visible label/detail are localized or business data. --}}
                    <span>{{ $exception['type']==='transfer' ? __('enterprise_wms.transfers.statuses.'.$exception['detail']) : $exception['detail'] }}</span>
                    @if($exception['value'] !== null)<strong>{{ \App\Support\DisplayNumber::quantity((float)$exception['value']) }}</strong>@endif
                </a>
            @empty
                <div class="foodex-module-state" data-foodex-module-state="current" role="status"><strong>{{ __('module_dashboard.states.current.title') }}</strong><p>{{ __('module_dashboard.states.current.message') }}</p></div>
            @endforelse
        </div>
    </section>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.overview.title') }}</h3><p class="wms-sub">{{ __('enterprise_wms.overview.subtitle') }}</p>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.common.reserved') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.common.unit_cost') }}</th><th>{{ __('enterprise_wms.common.actions') }}</th></tr></thead><tbody>
        @forelse($inventoryRows as $row)<tr><td><strong>{{ $row->product_name }}</strong><br><small>{{ $row->product_sku }}</small></td><td>{{ $row->warehouse_name }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->reserved_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td><td>{{ $row->unit_cost===null ? '—' : \App\Support\DisplayNumber::money((float)$row->unit_cost, null) }}</td><td><a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.wms.index',array_merge($baseQuery,['tab'=>'overview','inventory_id'=>$row->inventory_id])) }}">{{ __('enterprise_wms.common.view') }}</a></td></tr>@empty<tr><td colspan="7" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
