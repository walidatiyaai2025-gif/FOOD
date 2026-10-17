@php
    $reportLinks = [
        'wms.inventory_summary' => ['label' => __('enterprise_wms.reports.inventory_summary'), 'tab' => 'overview'],
        'wms.low_stock' => ['label' => __('enterprise_wms.reports.low_stock'), 'tab' => 'reorder'],
        'wms.stock_aging' => ['label' => __('enterprise_wms.reports.stock_aging'), 'tab' => 'aging'],
        'wms.lot_expiry' => ['label' => __('enterprise_wms.reports.lot_expiry'), 'tab' => 'lots'],
        'wms.count_variance' => ['label' => __('enterprise_wms.reports.count_variance'), 'tab' => 'counts'],
        'wms.adjustments' => ['label' => __('enterprise_wms.reports.adjustments'), 'tab' => 'adjustments'],
        'wms.transfer_performance' => ['label' => __('enterprise_wms.reports.transfer_performance'), 'tab' => 'transfers'],
        'wms.demand_plan' => ['label' => __('enterprise_wms.reports.demand_plan'), 'tab' => 'planning'],
    ];
    $reportBase = array_filter(['store_id'=>$storeId,'warehouse_id'=>$warehouseId]);
@endphp
<div class="foodex-module-overview" data-wms-reports>
    <div class="foodex-page-header">
        <div><h2>{{ __('enterprise_wms.reports.title') }}</h2><p>{{ __('enterprise_wms.reports.subtitle') }}</p></div>
    </div>

    <div class="wms-report-cards">
        @foreach($reportLinks as $code => $report)
            <a class="foodex-viz-card wms-report-card" data-report-code="{{ $code }}" href="{{ route('admin.erp.wms.index',array_merge($reportBase,['tab'=>$report['tab']])) }}">
                <strong>{{ $report['label'] }}</strong>
                <small>{{ $reportCatalog[$code]['read_model'] ?? $code }}</small>
            </a>
        @endforeach
    </div>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.reports.low_stock') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.reorder.minimum') }}</th><th>{{ __('enterprise_wms.reorder.reorder_quantity') }}</th></tr></thead><tbody>
        @forelse($lowStockReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ $row->warehouse_name }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->minimum_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->reorder_quantity) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.reports.stock_aging') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.aging.age_days') }}</th><th>{{ __('enterprise_wms.common.value') }}</th></tr></thead><tbody>
        @forelse($agingReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ $row->warehouse_name }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->age_days }}</td><td>{{ $row->inventory_value===null ? '—' : \App\Support\DisplayNumber::money((float)$row->inventory_value,null) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.reports.count_variance') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.counts.expected') }}</th><th>{{ __('enterprise_wms.counts.counted') }}</th><th>{{ __('enterprise_wms.overview.dashboard.count_variance') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
        @forelse($countVarianceReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->expected_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->counted_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->variance_quantity) }}</td><td>{{ $row->counted_at ? \Illuminate\Support\Carbon::parse($row->counted_at)->format('Y-m-d H:i') : '—' }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.reports.transfer_performance') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.transfers.source') }}</th><th>{{ __('enterprise_wms.transfers.target') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.reports.dispatch_hours') }}</th><th>{{ __('enterprise_wms.reports.receipt_hours') }}</th></tr></thead><tbody>
        @forelse($transferPerformanceRows as $row)<tr><td>{{ $row->source_name }}</td><td>{{ $row->target_name }}</td><td><span class="foodex-status-badge" data-state="{{ $row->status }}">{{ __('enterprise_wms.transfers.statuses.'.$row->status) }}</span></td><td>{{ $row->dispatch_hours===null ? '—' : number_format((float)$row->dispatch_hours,2).' '.__('enterprise_wms.common.hours') }}</td><td>{{ $row->receipt_hours===null ? '—' : number_format((float)$row->receipt_hours,2).' '.__('enterprise_wms.common.hours') }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="foodex-viz-card">
        <h3>{{ __('enterprise_wms.reports.demand_plan') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.planning.velocity') }}</th><th>{{ __('enterprise_wms.planning.lead_time') }}</th><th>{{ __('enterprise_wms.planning.recommendation') }}</th></tr></thead><tbody>
        @forelse($demandRows as $row)<tr><td>{{ $row['product_name'] }}<br><small>{{ $row['sku'] }}</small></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['available']) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['velocity_units_per_day']) }}</td><td>{{ number_format((float)$row['inputs']['lead_time_days'],2) }} {{ __('enterprise_wms.common.days') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['recommended_quantity']) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <div class="foodex-module-state" data-foodex-module-state="current" role="status">
        <strong>{{ __('enterprise_wms.reports.large_export') }}</strong>
        <p>{{ __('enterprise_wms.reports.subtitle') }}</p>
    </div>
</div>
