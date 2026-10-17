<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.completion.reports_title') }}</h2>
<form method="get" class="erp-form" action="{{ route('admin.erp.procurement.index') }}"><input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="tab" value="reports">
<label>{{ __('enterprise_procurement.completion.reports_title') }}<select name="report">@foreach(AppEnterpriseProcurementProcurementAnalyticsService::REPORT_CODES as $reportCode)<option value="{{ $reportCode }}" @selected($activeReport===$reportCode)>{{ __('enterprise_procurement.completion.report_names.'.$reportCode) }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.from') }}<input type="date" name="from" value="{{ request('from',$reportData['summary']['from'] ?? '') }}"></label><label>{{ __('enterprise_procurement.to') }}<input type="date" name="to" value="{{ request('to',$reportData['summary']['to'] ?? '') }}"></label><div><button class="foodex-filter-action">{{ __('enterprise_procurement.apply') }}</button></div></form>
@if($activeReport)
<form method="post" action="{{ route('admin.erp.procurement.reports.export',$activeReport) }}" class="erp-actions">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><input type="hidden" name="from" value="{{ $reportData['summary']['from'] ?? '' }}"><input type="hidden" name="to" value="{{ $reportData['summary']['to'] ?? '' }}"><input type="hidden" name="idempotency_key" value="procurement-{{ $activeReport }}-{{ $selectedStoreId }}-{{ $reportData['summary']['from'] ?? '' }}-{{ $reportData['summary']['to'] ?? '' }}"><button class="foodex-primary">{{ __('enterprise_procurement.completion.queue_export') }}</button></form>
@endif
</section>
@if($reportData)
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.total') }}</th></tr></thead><tbody>
@forelse($reportData['rows'] as $row)
@php($values=(array)$row)
<tr><td>{{ $values['order_number'] ?? $values['contract_number'] ?? $values['invoice_number'] ?? $values['supplier_code'] ?? ($values['supplier_id'] ?? '—') }}</td><td>{{ $values['supplier_code'] ?? ($values['supplier_id'] ?? '—') }}</td><td>{{ $values['status'] ?? $values['match_status'] ?? '—' }}</td><td>{{ $values['spend'] ?? $values['savings'] ?? $values['ordered_value'] ?? $values['total_amount'] ?? $values['value_variance'] ?? '—' }} {{ $values['currency_code'] ?? $values['currency'] ?? '' }}</td></tr>
@empty<tr><td colspan="4">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table></section>
@endif
