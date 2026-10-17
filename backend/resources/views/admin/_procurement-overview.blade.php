@php
$spendTrendRows=collect($dashboard['charts']['spendTrend'] ?? [])->map(fn($row)=>[
    'label'=>(string)$row->date,
    'value'=>(float)$row->spend,
    'secondary'=>(string)$row->currency_code,
])->all();
$spendSupplierRows=collect($dashboard['charts']['spendBySupplier'] ?? [])->map(fn($row)=>[
    'label'=>(string)$row->supplier_code,
    'value'=>(float)$row->spend,
    'secondary'=>(string)$row->currency_code,
])->all();
$poStatusRows=collect($dashboard['charts']['poStatus'] ?? [])->map(fn($row)=>[
    'label'=>__('enterprise_procurement.statuses.'.($row->status ?: 'draft')),
    'value'=>(int)$row->orders,
])->all();
$supplierPerformanceRows=collect($dashboard['charts']['supplierPerformance'] ?? [])->map(fn($row)=>[
    'label'=>(string)$row['supplier_code'],
    'value'=>(float)$row['on_time_rate'],
    'secondary'=>$row['average_lead_days']===null?'—':$row['average_lead_days'].' '.__('enterprise_procurement.completion.lead_time_days'),
])->all();
$savingsRows=collect($dashboard['charts']['savings'] ?? [])->map(fn($row)=>[
    'label'=>(string)$row->supplier_code,
    'value'=>(float)$row->savings,
    'secondary'=>(string)$row->currency_code,
])->all();
$contractExpiryRows=collect($dashboard['charts']['contractExpiry'] ?? [])->map(fn($row)=>[
    'label'=>(string)$row['bucket'],
    'value'=>(int)$row['contracts'],
])->all();
@endphp
<section class="foodex-module-overview" data-procurement-overview="true">
@include('admin._module-dashboard-filters',['moduleDashboardFilters'=>$moduleDashboardFilters])
<div>
<h2>{{ __('enterprise_procurement.dashboard.title') }}</h2>
<p class="foodex-subtitle">{{ __('enterprise_procurement.dashboard.subtitle') }}</p>
</div>
<div class="foodex-module-kpis">
@foreach($dashboard['metrics'] as $metric)
@include('admin._module-dashboard-kpi',['metric'=>$metric])
@endforeach
</div>
<div class="foodex-module-panels">
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.spend_trend'),
    'chartRows'=>$spendTrendRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'reports','report'=>'spend']),
])
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.spend_supplier'),
    'chartRows'=>$spendSupplierRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'reports','report'=>'spend']),
])
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.po_status'),
    'chartRows'=>$poStatusRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'orders']),
])
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.supplier_performance'),
    'chartRows'=>$supplierPerformanceRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'reports','report'=>'supplier_performance']),
])
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.savings'),
    'chartRows'=>$savingsRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'reports','report'=>'savings']),
])
@include('admin._procurement-chart',[
    'chartTitle'=>__('enterprise_procurement.dashboard.charts.contract_expiry'),
    'chartRows'=>$contractExpiryRows,
    'drilldownUrl'=>route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'reports','report'=>'contract_expiry']),
])
</div>
<section class="foodex-card erp-detail">
<h2>{{ __('enterprise_procurement.dashboard.exceptions_title') }}</h2>
<div class="erp-lines">
@forelse($dashboard['exceptions'] as $exception)
<a class="erp-line erp-link" href="{{ $exception['url'] }}"><strong>{{ $exception['label'] }}</strong><span>{{ $exception['reference'] }}</span><span>→</span><span></span></a>
@empty
<div class="foodex-state">{{ __('enterprise_procurement.empty') }}</div>
@endforelse
</div>
</section>
</section>
