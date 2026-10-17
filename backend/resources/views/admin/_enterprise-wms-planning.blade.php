@php
    $approvalRows = collect($demandApprovalRows ?? []);
@endphp
<div class="foodex-module-overview" data-wms-demand-plan>
    <div class="foodex-page-header">
        <div><h2>{{ __('enterprise_wms.planning.title') }}</h2><p>{{ __('enterprise_wms.planning.subtitle') }}</p></div>
    </div>

    <div class="wms-table-wrap">
        <table class="wms-table foodex-table" data-pagination-required="server-bounded">
            <thead><tr>
                <th>{{ __('enterprise_wms.common.product') }}</th>
                <th>{{ __('enterprise_wms.common.available') }}</th>
                <th>{{ __('enterprise_wms.planning.velocity') }}</th>
                <th>{{ __('enterprise_wms.planning.lead_time') }}</th>
                <th>{{ __('enterprise_wms.planning.target') }}</th>
                <th>{{ __('enterprise_wms.planning.recommendation') }}</th>
                <th>{{ __('enterprise_wms.planning.confidence') }}</th>
                <th>{{ __('enterprise_wms.common.approval') }}</th>
            </tr></thead>
            <tbody>
            @forelse($demandRows as $row)
                @php
                    $approval = $approvalRows->first(function($candidate) use ($row) {
                        return (int)$candidate->product_id === (int)$row['product_id']
                            && (int)$candidate->warehouse_id === (int)$row['warehouse_id']
                            && (string)$candidate->input_fingerprint === (string)$row['input_fingerprint']
                            && (string)$candidate->status === 'approved';
                    });
                    $stateKey = $row['state']==='reorder' ? 'reorder' : 'healthy';
                    $leadSource = $row['inputs']['lead_time_source'] ?? 'policy_default';
                @endphp
                <tr>
                    <td><strong>{{ $row['product_name'] }}</strong><br><small>{{ $row['sku'] }}</small></td>
                    <td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['available']) }}</td>
                    <td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['velocity_units_per_day']) }}</td>
                    <td>{{ number_format((float)$row['inputs']['lead_time_days'],2) }} {{ __('enterprise_wms.common.days') }}<br><small>{{ __('enterprise_wms.planning.lead_sources.'.$leadSource) }} · {{ (int)$row['inputs']['lead_time_samples'] }} {{ __('enterprise_wms.common.count') }}</small></td>
                    <td>{{ \App\Support\DisplayNumber::quantity((float)$row['target_quantity']) }}</td>
                    <td><span class="foodex-status-badge" data-tone="{{ $row['state']==='reorder' ? 'warning' : 'success' }}">{{ __('enterprise_wms.planning.'.$stateKey) }}</span><br><strong>{{ \App\Support\DisplayNumber::quantity((float)$row['recommended_quantity']) }}</strong></td>
                    <td>{{ __('enterprise_wms.planning.confidence_levels.'.$row['confidence']) }}</td>
                    <td>
                        @if($row['state']==='reorder' && !$approval)
                            <form method="post" action="{{ route('admin.erp.wms.planning.approve') }}" data-wms-mutation>
                                @csrf
                                <input type="hidden" name="store_id" value="{{ $storeId }}">
                                <input type="hidden" name="warehouse_id" value="{{ $row['warehouse_id'] }}">
                                <input type="hidden" name="product_id" value="{{ $row['product_id'] }}">
                                <button class="foodex-action-primary" type="submit">{{ __('enterprise_wms.planning.approve') }}</button>
                            </form>
                        @elseif($approval)
                            <span class="foodex-status-badge" data-state="approved">{{ __('enterprise_wms.planning.approved') }}</span>
                            @if($canProcurementHandoff)
                                <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.procurement.index',['tab'=>'requests','store_id'=>$storeId]) }}">{{ __('enterprise_wms.planning.open_procurement') }}</a>
                            @endif
                        @else
                            <span class="foodex-status-badge" data-tone="success">{{ __('enterprise_wms.planning.healthy') }}</span>
                        @endif
                    </td>
                </tr>
                <tr class="wms-plan-explain"><td colspan="8">
                    <small>{{ __('enterprise_wms.common.quantity') }}: {{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['on_hand']) }} ·
                    {{ __('enterprise_wms.common.reserved') }}: {{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['reserved']) }} ·
                    1–7: {{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['sales_units']['days_1_7']) }} ·
                    8–14: {{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['sales_units']['days_8_14']) }} ·
                    15–30: {{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['sales_units']['days_15_30']) }}</small>
                </td></tr>
            @empty
                <tr><td colspan="8" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="foodex-module-state" data-foodex-module-state="current" role="status">
        <strong>{{ __('enterprise_wms.planning.approval') }}</strong>
        <p>{{ __('enterprise_wms.planning.approval_note') }}</p>
    </div>
</div>
