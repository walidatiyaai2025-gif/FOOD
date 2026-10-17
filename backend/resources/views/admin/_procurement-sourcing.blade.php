<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.completion.sourcing_title') }}</h2>
@if($can['request_manage'] && $approvedRequestsForLookup->isNotEmpty() && $suppliersForLookup->isNotEmpty())
<details class="erp-create"><summary>+ {{ __('enterprise_procurement.completion.new_rfq') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.rfqs.store') }}" class="erp-form">@csrf
<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.tabs.requests') }}<select name="purchase_request_id" required>@foreach($approvedRequestsForLookup as $requestRow)<option value="{{ $requestRow->id }}">{{ $requestRow->request_number }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.completion.rfq_title') }}<input name="title" maxlength="190" required></label>
<label>{{ __('enterprise_procurement.completion.closes_at') }}<input type="datetime-local" name="closes_at"></label>
<label class="span-2">{{ __('enterprise_procurement.completion.invited_suppliers') }}<select name="supplier_ids[]" multiple required size="5">@foreach($suppliersForLookup as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }} · {{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</option>@endforeach</select></label>
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></details>
@endif
</section>
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.completion.rfq_title') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.completion.closes_at') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td class="erp-ref">{{ $row->rfq_number }}</td><td>{{ $row->title }}</td><td>{{ $statusLabel($row->status) }}</td><td>{{ $row->closes_at ?? '—' }}</td><td><a class="erp-link" href="{{ route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'sourcing','rfq'=>$row->id]) }}">{{ __('enterprise_procurement.view') }}</a></td></tr>
@empty<tr><td colspan="5">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table></section>
@if($sourcingDetail)
@php($rfq=$sourcingDetail['rfq'])
<section class="foodex-card erp-detail">
<div class="erp-context"><div><h2>{{ $rfq->rfq_number }} · {{ $rfq->title }}</h2><p>{{ $statusLabel($rfq->status) }}</p></div>
<div class="erp-actions">
@if($can['request_manage'] && $rfq->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.rfqs.publish',$rfq->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="foodex-primary">{{ __('enterprise_procurement.completion.publish') }}</button></form>@endif
@if($can['request_manage'] && !in_array($rfq->status,['awarded','cancelled'],true))<form method="post" action="{{ route('admin.erp.procurement.rfqs.cancel',$rfq->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="erp-link erp-danger">{{ __('enterprise_procurement.cancel') }}</button></form>@endif
</div></div>
<h3>{{ __('enterprise_procurement.completion.invited_suppliers') }}</h3>
<div class="erp-lines">@foreach($sourcingDetail['suppliers'] as $supplier)<div class="erp-line"><strong>{{ $supplier->code }}</strong><span>{{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</span><span>{{ $statusLabel($supplier->status) }}</span><span>{{ $supplier->responded_at ?? '—' }}</span></div>@endforeach</div>
@if($can['request_manage'] && in_array($rfq->status,['published','evaluating'],true))
<h3>{{ __('enterprise_procurement.completion.record_quote') }}</h3>
<form method="post" action="{{ route('admin.erp.procurement.rfqs.quotes.store',$rfq->id) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.supplier') }}<select name="supplier_id" required>@foreach($sourcingDetail['suppliers'] as $supplier)<option value="{{ $supplier->supplier_id }}">{{ $supplier->code }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.completion.supplier_reference') }}<input name="supplier_reference" maxlength="96"></label>
<label>{{ __('enterprise_procurement.completion.lead_time_days') }}<input type="number" name="lead_time_days" min="0" max="3650"></label>
<label>{{ __('enterprise_procurement.completion.valid_until') }}<input type="date" name="valid_until"></label>
@foreach($sourcingDetail['lines'] as $index=>$line)
<input type="hidden" name="lines[{{ $index }}][rfq_line_id]" value="{{ $line->id }}">
<label>{{ $line->description }} · {{ $line->quantity }} {{ $line->unit }}<input type="number" name="lines[{{ $index }}][unit_price]" step="0.001" min="0" required></label>
@endforeach
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.completion.record_quote') }}</button></div>
</form>
@endif
<h3>{{ __('enterprise_procurement.completion.quotes') }}</h3>
<div class="erp-lines">@forelse($sourcingDetail['quotes'] as $quote)<div class="erp-line"><strong>{{ $quote->supplier_code }}</strong><span>{{ $quote->total_amount }} {{ $rfq->currency_code }}</span><span>{{ $statusLabel($quote->status) }}</span><span>@if($can['order_manage'] && $quote->status==='submitted')<form method="post" action="{{ route('admin.erp.procurement.rfqs.award',['rfq'=>$rfq->id,'quote'=>$quote->id]) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="foodex-primary">{{ __('enterprise_procurement.completion.award') }}</button></form>@else{{ $quote->lead_time_days ?? '—' }}@endif</span></div>@empty<p>{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
</section>
@endif
