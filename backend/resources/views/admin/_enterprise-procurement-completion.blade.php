@php
$dashboardMetricLabels=[
    'open_requests'=>__('enterprise_procurement.open_requests'),
    'pending_approvals'=>__('enterprise_procurement.pending_approvals'),
    'open_orders'=>__('enterprise_procurement.open_orders'),
    'overdue_orders'=>__('enterprise_procurement.overdue_orders'),
    'ordered_not_received'=>__('enterprise_procurement.ordered_not_received'),
    'unmatched_invoices'=>__('enterprise_procurement.unmatched_invoices'),
    'supplier_aging'=>__('enterprise_procurement.supplier_aging'),
    'contracts'=>__('enterprise_procurement.active_contracts'),
    'active_rfqs'=>__('enterprise_procurement.active_rfqs'),
];
$dashboardChartLabels=[
    'spend_trend'=>__('enterprise_procurement.spend_trend'),
    'spend_by_supplier'=>__('enterprise_procurement.spend_by_supplier'),
    'spend_by_category'=>__('enterprise_procurement.spend_by_category'),
    'po_status'=>__('enterprise_procurement.po_status_chart'),
    'po_aging'=>__('enterprise_procurement.po_aging_chart'),
    'supplier_performance'=>__('enterprise_procurement.supplier_performance'),
    'savings'=>__('enterprise_procurement.purchase_savings'),
    'contract_expiry'=>__('enterprise_procurement.contract_expiry_horizon'),
];
$exceptionLabels=[
    'overdue_orders'=>__('enterprise_procurement.overdue_orders'),
    'receipt_variance'=>__('enterprise_procurement.receipt_variance'),
    'match_variance'=>__('enterprise_procurement.match_variance'),
    'blocked_suppliers'=>__('enterprise_procurement.blocked_suppliers'),
    'expiring_contracts'=>__('enterprise_procurement.expiring_contracts'),
    'finance_handoff_failed'=>__('enterprise_procurement.finance_handoff_failed'),
];
@endphp

@if($activeTab==='overview' && $dashboard)
<section class="foodex-module-overview" data-foodex-module-overview data-state="{{ $dashboard['state'] }}">
    <div class="foodex-viz-header">
        <div>
            <h2 class="foodex-viz-title">{{ __('enterprise_procurement.dashboard') }}</h2>
            <p class="foodex-viz-subtitle">{{ __('enterprise_procurement.overview_subtitle') }}</p>
        </div>
    </div>
    @php
        $moduleDashboardFilters=[
            'action'=>route('admin.erp.procurement.index'),
            'from_value'=>$dashboard['selected_from'],
            'to_value'=>$dashboard['selected_to'],
            'hidden'=>['tab'=>'overview','store_id'=>$selectedStoreId],
            'reset_url'=>route('admin.erp.procurement.index',['tab'=>'overview','store_id'=>$selectedStoreId]),
        ];
    @endphp
    @include('admin._module-dashboard-filters',['moduleDashboardFilters'=>$moduleDashboardFilters])

    @if($dashboard['state']!=='current')
        @include('admin._module-dashboard-state',['state'=>$dashboard['state']])
    @else
        <div class="foodex-module-kpis">
            @foreach($dashboard['kpis'] as $dashboardMetric)
                @php
                    $drilldown=route('admin.erp.procurement.index',[
                        'tab'=>$dashboardMetric['tab'],
                        'store_id'=>$selectedStoreId,
                    ]);
                    $metric=[
                        'label'=>$dashboardMetricLabels[$dashboardMetric['key']] ?? $dashboardMetric['key'],
                        'value'=>$dashboardMetric['value'] ?? '—',
                        'last_refresh'=>$dashboardMetric['last_refresh'],
                        'drilldown_url'=>$drilldown,
                        'status'=>isset($dashboardMetric['secondary'])
                            ? ['state'=>($dashboardMetric['secondary']??0)>0?'warning':'current','label'=>__('enterprise_procurement.expiring_contracts').': '.($dashboardMetric['secondary']??0)]
                            : null,
                    ];
                @endphp
                @include('admin._module-dashboard-kpi',['metric'=>$metric])
            @endforeach
        </div>

        <div class="foodex-module-panels" style="grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr))">
            @foreach($dashboard['charts'] as $chartKey=>$chartRows)
                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart="{{ $chartKey }}">
                    <div class="foodex-viz-header">
                        <div>
                            <h2 class="foodex-viz-title">{{ $dashboardChartLabels[$chartKey] ?? $chartKey }}</h2>
                            <p class="foodex-viz-subtitle">{{ __('enterprise_procurement.current_period') }} · {{ $dashboard['selected_from'] }} — {{ $dashboard['selected_to'] }}</p>
                        </div>
                    </div>
                    @if($chartRows===[])
                        <div class="foodex-viz-empty">{{ __('enterprise_procurement.empty') }}</div>
                    @else
                        @php
    $chartMax = max(1, (float) collect($chartRows)->max('value'));
@endphp
                        <div class="foodex-viz-bars" role="img" aria-label="{{ $dashboardChartLabels[$chartKey] ?? $chartKey }}">
                            @foreach($chartRows as $point)
                                @php
                                    $pointLabel=$chartKey==='po_status'
                                        ? __('enterprise_procurement.statuses.'.$point['label'])
                                        : $point['label'];
                                    $barPercent=max(2,min(100,((float)$point['value']/$chartMax)*100));
                                @endphp
                                <div class="foodex-viz-bar-row">
                                    <span class="foodex-viz-bar-label">{{ $pointLabel }}</span>
                                    <div class="foodex-viz-bar-track"><span class="foodex-viz-bar" style="--foodex-viz-value:{{ $barPercent }}%"></span></div>
                                    <strong class="foodex-number">{{ $point['value'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                        <div class="foodex-module-chart-table-wrap" data-pagination-exempt="bounded-dashboard-chart-series">
                            <table class="foodex-module-chart-table">
                                <thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.chart_value') }}</th></tr></thead>
                                <tbody>
                                @foreach($chartRows as $point)
                                    <tr><td>{{ $chartKey==='po_status' ? __('enterprise_procurement.statuses.'.$point['label']) : $point['label'] }}</td><td class="foodex-number">{{ $point['value'] }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        <article class="foodex-card">
            <h2>{{ __('enterprise_procurement.exceptions') }}</h2>
            <div class="erp-lines">
                @foreach($dashboard['exceptions'] as $exception)
                    <a class="erp-line" href="{{ route('admin.erp.procurement.index',['tab'=>$exception['tab'],'store_id'=>$selectedStoreId]) }}">
                        <strong>{{ $exceptionLabels[$exception['key']] ?? $exception['key'] }}</strong>
                        <span class="foodex-number">{{ $exception['count'] }}</span><span>→</span><span></span>
                    </a>
                @endforeach
            </div>
        </article>
    @endif
</section>
@endif

@if($activeTab==='suppliers' && $detail)
<section class="foodex-card erp-detail" data-procurement-supplier-governance>
    <h2>{{ __('enterprise_procurement.performance') }} & {{ __('enterprise_procurement.qualification') }}</h2>
    <div class="erp-detail-grid">
        <div><small>{{ __('enterprise_procurement.qualification') }}</small><strong>{{ __('enterprise_procurement.statuses.'.($detail['supplier']->qualification_status ?: 'pending')) }}</strong></div>
        <div><small>{{ __('enterprise_procurement.order_count') }}</small><strong>{{ $detail['performance']['order_count'] }}</strong></div>
        <div><small>{{ __('enterprise_procurement.posted_receipt_count') }}</small><strong>{{ $detail['performance']['posted_receipt_count'] }}</strong></div>
        <div><small>{{ __('enterprise_procurement.average_lead_days') }}</small><strong>{{ $detail['performance']['average_lead_days'] ?? '—' }}</strong></div>
        <div><small>{{ __('enterprise_procurement.invoice_count') }}</small><strong>{{ $detail['performance']['invoice_count'] }}</strong></div>
        <div><small>{{ __('enterprise_procurement.spend') }}</small><strong>{{ $detail['performance']['spend'] }}</strong></div>
    </div>
    @if($can['supplier_manage'])
    <form method="post" action="{{ route('admin.erp.procurement.suppliers.governance',$detail['supplier']->id) }}" class="erp-form">
        @csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
        <label>{{ __('enterprise_procurement.qualification') }}
            <select name="qualification_status" required>
                @foreach(['pending','qualified','conditional','unqualified'] as $qualification)
                <option value="{{ $qualification }}" @selected($detail['supplier']->qualification_status===$qualification)>{{ __('enterprise_procurement.statuses.'.$qualification) }}</option>
                @endforeach
            </select>
        </label>
        <label class="span-2">{{ __('enterprise_procurement.qualification_note') }}<input name="qualification_note" maxlength="2000" value="{{ $detail['supplier']->qualification_note }}"></label>
        <label>{{ __('enterprise_procurement.blocked') }}
            <select name="blocked" required>
                <option value="0" @selected($detail['supplier']->status!=='on_hold')>{{ __('enterprise_procurement.statuses.active') }}</option>
                <option value="1" @selected($detail['supplier']->status==='on_hold')>{{ __('enterprise_procurement.statuses.on_hold') }}</option>
            </select>
        </label>
        <label class="span-2">{{ __('enterprise_procurement.blocked_reason') }}<input name="blocked_reason" maxlength="2000" value="{{ $detail['supplier']->blocked_reason }}"></label>
        <div><button class="foodex-primary">{{ __('enterprise_procurement.update_governance') }}</button></div>
    </form>
    @endif
    <h3>{{ __('enterprise_procurement.contract') }}</h3>
    <div class="erp-lines">
        @forelse($detail['contracts'] as $contract)
            <a class="erp-line" href="{{ route('admin.erp.procurement.index',['tab'=>'contracts','store_id'=>$selectedStoreId,'contract'=>$contract->id]) }}">
                <strong>{{ $contract->contract_number }}</strong><span>{{ $contract->title }}</span><span>{{ __('enterprise_procurement.statuses.'.$contract->status) }}</span><span>{{ $contract->ends_on }}</span>
            </a>
        @empty
            <p class="erp-lookup-hint">{{ __('enterprise_procurement.empty') }}</p>
        @endforelse
    </div>
</section>
@endif

@if($activeTab==='rfqs')
@if($can['request_manage'] && $rfqRequestOptions!==[])
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_procurement.new_rfq') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.rfqs.store') }}" class="erp-form">
@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.reference') }}<select name="purchase_request_id" required>
<option value="">—</option>
@foreach($rfqRequestOptions as $requestOption)<option value="{{ $requestOption->id }}">{{ $requestOption->request_number }} · {{ __('enterprise_procurement.statuses.'.$requestOption->status) }}</option>@endforeach
</select></label>
<label class="span-2">{{ __('enterprise_procurement.rfq_title') }}<input name="title" required maxlength="190"></label>
<label>{{ __('enterprise_procurement.response_due') }}<input type="datetime-local" name="response_due_at"></label>
<button class="foodex-primary" type="submit">{{ __('enterprise_procurement.create') }}</button>
</form>
</details>
@endif

<section class="foodex-card erp-table-wrap" data-pagination-required><table class="foodex-table erp-table">
<thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.rfq_title') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.response_due') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead>
<tbody>
@forelse($rows as $row)
<tr><td class="erp-ref">{{ $row->rfq_number }}</td><td>{{ $row->title }}<div class="foodex-subtitle">{{ $row->source_request_number }}</div></td><td>{{ __('enterprise_procurement.statuses.'.$row->status) }}</td><td>{{ $row->response_due_at ?: '—' }}</td><td><a class="erp-link" href="{{ route('admin.erp.procurement.index',['tab'=>'rfqs','store_id'=>$selectedStoreId,'rfq'=>$row->id]) }}">{{ __('enterprise_procurement.view') }}</a></td></tr>
@empty<tr><td colspan="5"><div class="foodex-empty-state">{{ __('enterprise_procurement.empty') }}</div></td></tr>@endforelse
</tbody></table><div class="erp-pagination">{{ $rows->withQueryString()->links() }}</div></section>

@if(session('procurement_portal_links'))
<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.portal_links') }}</h2><p>{{ __('enterprise_procurement.copy_securely') }}</p>
<div class="erp-lines">@foreach(session('procurement_portal_links') as $link)<div class="erp-line"><strong>{{ $link['supplier_code'] }}</strong><span>{{ app()->getLocale()==='ar' && $link['supplier_name_ar'] ? $link['supplier_name_ar'] : $link['supplier_name_en'] }}</span><input readonly value="{{ $link['url'] }}" aria-label="{{ __('enterprise_procurement.portal_links') }}"><span></span></div>@endforeach</div>
</section>
@endif

@if($rfqDetail)
<section class="foodex-card erp-detail">
<h2>{{ $rfqDetail['rfq']->rfq_number }} · {{ $rfqDetail['rfq']->title }}</h2>
<div class="erp-detail-grid">
<div><small>{{ __('enterprise_procurement.status') }}</small><strong>{{ __('enterprise_procurement.statuses.'.$rfqDetail['rfq']->status) }}</strong></div>
<div><small>{{ __('enterprise_procurement.response_due') }}</small><strong>{{ $rfqDetail['rfq']->response_due_at ?: '—' }}</strong></div>
<div><small>{{ __('enterprise_procurement.currency') }}</small><strong>{{ $rfqDetail['rfq']->currency_code }}</strong></div>
<div><small>{{ __('enterprise_procurement.quotes') }}</small><strong>{{ $rfqDetail['quotes']->count() }}</strong></div>
</div>
@if($rfqDetail['rfq']->status==='draft' && $can['request_manage'])
<form method="post" action="{{ route('admin.erp.procurement.rfqs.publish',$rfqDetail['rfq']->id) }}" class="erp-form">
@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<div class="span-all"><strong>{{ __('enterprise_procurement.supplier_invites') }}</strong><div class="erp-lines">
@foreach($suppliersForLookup as $supplier)<label class="erp-line"><input type="checkbox" name="supplier_ids[]" value="{{ $supplier->id }}"><strong>{{ $supplier->code }}</strong><span>{{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</span><span></span></label>@endforeach
</div></div>
<div><button class="foodex-primary">{{ __('enterprise_procurement.publish') }}</button></div>
</form>
@endif
@if(in_array($rfqDetail['rfq']->status,['draft','published'],true) && $can['request_manage'])
<form method="post" action="{{ route('admin.erp.procurement.rfqs.cancel',$rfqDetail['rfq']->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>
@endif
<h3>{{ __('enterprise_procurement.quotes') }}</h3>
<div class="erp-table-wrap" data-pagination-exempt="single-rfq-quote-comparison"><table class="foodex-table"><thead><tr><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.quote_total') }}</th><th>{{ __('enterprise_procurement.lead_time') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rfqDetail['quotes'] as $quote)
<tr><td>{{ $quote->supplier_code }} · {{ app()->getLocale()==='ar' && $quote->supplier_name_ar ? $quote->supplier_name_ar : $quote->supplier_name_en }}
@foreach($rfqDetail['quoteDocuments']->get($quote->id,collect()) as $quoteDocument)<div><a class="erp-link" href="{{ route('admin.erp.procurement.rfqs.documents.download',['rfq'=>$rfqDetail['rfq']->id,'document'=>$quoteDocument->id,'store_id'=>$selectedStoreId]) }}">{{ $quoteDocument->original_name }}</a></div>@endforeach
</td><td>{{ $quote->total_amount }} {{ $quote->currency_code }}</td><td>{{ $quote->lead_time_days ?? '—' }}</td><td>{{ __('enterprise_procurement.statuses.'.$quote->status) }}</td><td>@if($quote->status==='submitted' && $can['request_approve'] && $can['order_manage'])<form method="post" action="{{ route('admin.erp.procurement.rfqs.award',['rfq'=>$rfqDetail['rfq']->id,'quote'=>$quote->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.award') }}</button></form>@endif</td></tr>
@empty<tr><td colspan="5">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table></div>
</section>
@endif
@endif

@if($activeTab==='orders' && $detail && $can['order_manage'] && $detail->status==='draft' && $activeContracts!==[])
<section class="foodex-card erp-detail">
<h3>{{ __('enterprise_procurement.assign_contract') }}</h3>
<form method="post" action="{{ route('admin.erp.procurement.orders.contract',$detail->id) }}" class="erp-form">
@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.contract') }}<select name="contract_id" required><option value="">—</option>@foreach($activeContracts as $contract)<option value="{{ $contract->id }}" @selected($detail->procurement_contract_id==$contract->id)>{{ $contract->contract_number }} · {{ $contract->title }} · {{ $contract->ends_on }}</option>@endforeach</select></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.assign_contract') }}</button></div>
</form>
</section>
@endif

@if($activeTab==='contracts')
@if($can['order_manage'])
<details class="foodex-card erp-create"><summary>+ {{ __('enterprise_procurement.new_contract') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.contracts.store') }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.supplier') }}<select name="supplier_id" required><option value="">—</option>@foreach($suppliersForLookup as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</option>@endforeach</select></label>
<label class="span-2">{{ __('enterprise_procurement.contract_title') }}<input name="title" maxlength="190" required></label>
<label>{{ __('enterprise_procurement.currency') }}<input name="currency_code" value="KWD" maxlength="3" required></label>
<label>{{ __('enterprise_procurement.starts_on') }}<input type="date" name="starts_on" required></label>
<label>{{ __('enterprise_procurement.ends_on') }}<input type="date" name="ends_on" required></label>
<label>{{ __('enterprise_procurement.value_ceiling') }}<input type="number" step="0.001" min="0.001" name="value_ceiling"></label>
<label>{{ __('enterprise_procurement.quantity_ceiling') }}<input type="number" step="0.001" min="0.001" name="quantity_ceiling"></label>
<label>{{ __('enterprise_procurement.renewal_of') }}<select name="renewal_of_id"><option value="">—</option>@foreach($rows as $contract)<option value="{{ $contract->id }}">{{ $contract->contract_number }} · {{ $contract->supplier_code }}</option>@endforeach</select></label>
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></details>
@endif
<section class="foodex-card erp-table-wrap" data-pagination-required><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.ends_on') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)
@php
    $contractStatus = $row->status === 'active' && (string) $row->ends_on < now()->toDateString() ? 'expired' : $row->status;
@endphp
<tr><td class="erp-ref">{{ $row->contract_number }}</td><td>{{ $row->supplier_code }}</td><td>{{ __('enterprise_procurement.statuses.'.$contractStatus) }}</td><td>{{ $row->ends_on }}</td><td><a class="erp-link" href="{{ route('admin.erp.procurement.index',['tab'=>'contracts','store_id'=>$selectedStoreId,'contract'=>$row->id]) }}">{{ __('enterprise_procurement.view') }}</a></td></tr>
@empty<tr><td colspan="5">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table><div class="erp-pagination">{{ $rows->withQueryString()->links() }}</div></section>
@if($contractDetail)
@php
    $contract = $contractDetail['contract'];
@endphp
<section class="foodex-card erp-detail">
<h2>{{ $contract->contract_number }} · {{ $contract->title }}</h2>
<div class="erp-detail-grid">
@php
    $contractEffectiveStatus = $contract->status === 'active' && (string) $contract->ends_on < now()->toDateString() ? 'expired' : $contract->status;
@endphp
<div><small>{{ __('enterprise_procurement.status') }}</small><strong>{{ __('enterprise_procurement.statuses.'.$contractEffectiveStatus) }}</strong></div>
<div><small>{{ __('enterprise_procurement.starts_on') }}</small><strong>{{ $contract->starts_on }}</strong></div>
<div><small>{{ __('enterprise_procurement.ends_on') }}</small><strong>{{ $contract->ends_on }}</strong></div>
<div><small>{{ __('enterprise_procurement.value_ceiling') }}</small><strong>{{ $contract->value_ceiling ?? '—' }}</strong></div>
<div><small>{{ __('enterprise_procurement.committed_value') }}</small><strong>{{ $contract->committed_value }}</strong></div>
<div><small>{{ __('enterprise_procurement.quantity_ceiling') }}</small><strong>{{ $contract->quantity_ceiling ?? '—' }}</strong></div>
<div><small>{{ __('enterprise_procurement.committed_quantity') }}</small><strong>{{ $contract->committed_quantity }}</strong></div>
</div>
@if($can['order_manage'])
<div class="erp-actions">
@if($contract->status==='draft')
<form method="post" action="{{ route('admin.erp.procurement.contracts.activate',$contract->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link">{{ __('enterprise_procurement.activate') }}</button></form>@endif
@if(in_array($contract->status, ['draft', 'active'], true))
<form method="post" action="{{ route('admin.erp.procurement.contracts.terminate',$contract->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.terminate') }}</button></form>@endif
</div>
<form method="post" action="{{ route('admin.erp.procurement.contracts.documents.store',$contract->id) }}" class="erp-form" enctype="multipart/form-data">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.document_title') }}<input name="title" maxlength="190" required></label>
<label class="span-2">{{ __('enterprise_procurement.document_reference') }}<input name="reference" maxlength="190"></label>
<label class="span-2">{{ __('enterprise_procurement.attachment') }}<input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.doc,.docx"></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.register_document') }}</button></div>
</form>
@endif
<h3>{{ __('enterprise_procurement.documents') }}</h3>
<div class="erp-lines">@forelse($contractDetail['documents'] as $document)<div class="erp-line"><strong>{{ $document->title }}</strong><span>{{ $document->reference ?: '—' }}</span><span>{{ $document->original_name ?: '—' }}</span><span>@if($document->storage_path)<a class="erp-link" href="{{ route('admin.erp.procurement.contracts.documents.download',['contract'=>$contract->id,'document'=>$document->id,'store_id'=>$selectedStoreId]) }}">{{ __('enterprise_procurement.download') }}</a>@endif</span></div>@empty<p>{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
</section>
@endif
@endif

@if($activeTab==='matches')
<section class="foodex-card erp-table-wrap" data-pagination-required><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.three_way_match') }}</th><th>{{ __('enterprise_procurement.quantity_variance') }}</th><th>{{ __('enterprise_procurement.value_variance') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td class="erp-ref">{{ $row->invoice_number }}<div class="foodex-subtitle">{{ $row->supplier_invoice_number }}</div></td><td>{{ $row->supplier_code }}</td><td>{{ __('enterprise_procurement.statuses.'.($row->match_status ?: 'pending')) }}<div class="foodex-subtitle">{{ $row->exception_reason }}</div></td><td>{{ $row->quantity_variance ?? '—' }}</td><td>{{ $row->value_variance ?? '—' }} {{ $row->currency_code }}</td><td>
@if(in_array($row->match_status,['pending','rejected'],true) && $can['invoice_handoff'])
<form method="post" action="{{ route('admin.erp.procurement.matches.review',$row->purchase_invoice_id) }}" class="erp-actions">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input name="note" maxlength="2000" placeholder="{{ __('enterprise_procurement.review_note') }}"><button class="erp-link" name="decision" value="approve">{{ __('enterprise_procurement.approve') }}</button><button class="erp-link erp-danger" name="decision" value="reject">{{ __('enterprise_procurement.reject') }}</button></form>
@endif
</td></tr>
@empty<tr><td colspan="6">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table><div class="erp-pagination">{{ $rows->withQueryString()->links() }}</div></section>
@endif

@if($activeTab==='reports')
<section class="foodex-card">
<h2>{{ __('enterprise_procurement.reports') }}</h2>
<form method="get" action="{{ route('admin.erp.procurement.index') }}" class="erp-filters">
<input type="hidden" name="tab" value="reports"><input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.report') }}<select name="report" required><option value="">—</option>@foreach(['open_orders','spend','supplier_performance','savings','contract_expiry','match_exceptions'] as $reportCode)<option value="{{ $reportCode }}" @selected($reportName===$reportCode)>{{ __('enterprise_procurement.reports_catalog.'.$reportCode) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.from') }}<input type="date" name="from" value="{{ request('from') }}"></label>
<label>{{ __('enterprise_procurement.to') }}<input type="date" name="to" value="{{ request('to') }}"></label>
@php
    $biReportCode = $reportName ? 'procurement.'.$reportName : 'procurement.open_orders';
@endphp
<div class="erp-actions"><button class="foodex-filter-action">{{ __('enterprise_procurement.run_report') }}</button><a class="erp-link" href="{{ route('admin.erp.bi',['report'=>$biReportCode]) }}">{{ __('enterprise_procurement.open_enterprise_bi') }}</a></div>
</form>
</section>
@if($reportData)
<section class="foodex-card erp-table-wrap" data-pagination-exempt="server-bounded-report-preview">
<div class="erp-actions" style="padding:12px">
@if($can['report_export'])<a class="erp-link foodex-module-export" href="{{ route('admin.erp.procurement.reports.export',['store_id'=>$selectedStoreId,'report'=>$reportName,'from'=>request('from'),'to'=>request('to')]) }}">{{ __('enterprise_procurement.export_csv') }}</a>@endif
<a class="erp-link" href="{{ route('admin.erp.bi',['report'=>'procurement.'.$reportName]) }}">{{ __('enterprise_procurement.scheduled_reporting') }}</a>
</div>
<table class="foodex-table erp-table"><thead><tr>@foreach($reportData['columns'] as $column)<th>{{ __('enterprise_procurement.report_columns.'.$column) }}</th>@endforeach</tr></thead><tbody>
@forelse($reportData['rows'] as $reportRow)
<tr>
@foreach($reportData['columns'] as $column)
@php
    $reportValue = $reportRow[$column] ?? null;
@endphp
<td>{{ $column==='status' && $reportValue ? __('enterprise_procurement.statuses.'.$reportValue) : ($reportValue ?? '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ max(1,count($reportData['columns'])) }}">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table>
</section>
@endif
@endif
