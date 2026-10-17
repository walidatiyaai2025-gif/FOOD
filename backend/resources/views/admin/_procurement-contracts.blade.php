<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.completion.contracts_title') }}</h2>
@if($can['supplier_manage'])
<details class="erp-create"><summary>+ {{ __('enterprise_procurement.completion.new_contract') }}</summary>
<form method="post" action="{{ route('admin.erp.procurement.contracts.store') }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}">
<label>{{ __('enterprise_procurement.supplier') }}<select name="supplier_id" required>@foreach($suppliersForLookup as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->code }}</option>@endforeach</select></label>
<label>{{ __('enterprise_procurement.completion.contract_title') }}<input name="title" maxlength="190" required></label>
<label>{{ __('enterprise_procurement.currency') }}<input name="currency_code" value="KWD" maxlength="3" required></label>
<label>{{ __('enterprise_procurement.completion.valid_from') }}<input type="date" name="valid_from" required></label>
<label>{{ __('enterprise_procurement.completion.valid_to') }}<input type="date" name="valid_to" required></label>
<label>{{ __('enterprise_procurement.completion.ceiling_amount') }}<input type="number" name="ceiling_amount" min="0" step="0.001"></label>
<label>{{ __('enterprise_procurement.completion.ceiling_quantity') }}<input type="number" name="ceiling_quantity" min="0" step="0.001"></label>
<label class="span-all">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000"></textarea></label>
<div><button class="foodex-primary">{{ __('enterprise_procurement.create') }}</button></div>
</form></details>
@endif
</section>
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.status') }}</th><th>{{ __('enterprise_procurement.completion.valid_to') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->contract_number }}</td><td>{{ $row->supplier?->code }}</td><td>{{ $statusLabel($row->status) }}</td><td>{{ $row->valid_to?->toDateString() }}</td><td><a class="erp-link" href="{{ route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'contracts','contract'=>$row->id]) }}">{{ __('enterprise_procurement.view') }}</a></td></tr>@empty<tr><td colspan="5">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table></section>
@if($contractDetail)
@php($contract=$contractDetail['contract'])
<section class="foodex-card erp-detail"><h2>{{ $contract->contract_number }} · {{ $contract->title }}</h2>
<div class="erp-detail-grid"><div><small>{{ __('enterprise_procurement.completion.committed_amount') }}</small><strong>{{ $contractDetail['committed_amount'] }} {{ $contract->currency_code }}</strong></div><div><small>{{ __('enterprise_procurement.completion.committed_quantity') }}</small><strong>{{ $contractDetail['committed_quantity'] }}</strong></div><div><small>{{ __('enterprise_procurement.completion.ceiling_amount') }}</small><strong>{{ $contract->ceiling_amount ?? '—' }}</strong></div><div><small>{{ __('enterprise_procurement.completion.valid_to') }}</small><strong>{{ $contract->valid_to?->toDateString() }}</strong></div></div>
@if($can['supplier_manage'])
<div class="erp-actions">@if($contract->status==='draft')<form method="post" action="{{ route('admin.erp.procurement.contracts.activate',$contract->id) }}">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><button class="foodex-primary">{{ __('enterprise_procurement.completion.activate') }}</button></form>@endif</div>
<form method="post" action="{{ route('admin.erp.procurement.contracts.orders.store',$contract->id) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label>{{ __('enterprise_procurement.completion.attach_order') }}<select name="purchase_order_id" required>@foreach($ordersForLookup as $order)<option value="{{ $order->id }}">{{ $order->order_number }} · {{ $order->supplier_code }}</option>@endforeach</select></label><div><button class="erp-link">{{ __('enterprise_procurement.completion.attach_order') }}</button></div></form>
<form method="post" action="{{ route('admin.erp.procurement.contracts.documents.store',$contract->id) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label>{{ __('enterprise_procurement.completion.document_title') }}<input name="title" maxlength="190" required></label><label>{{ __('enterprise_procurement.completion.document_reference') }}<input name="reference" maxlength="190"></label><label>{{ __('enterprise_procurement.completion.valid_to') }}<input type="date" name="expires_at"></label><div><button class="erp-link">{{ __('enterprise_procurement.completion.register_document') }}</button></div></form>
@if(in_array($contract->status,['draft','active'],true))<form method="post" action="{{ route('admin.erp.procurement.contracts.terminate',$contract->id) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label class="span-2">{{ __('enterprise_procurement.completion.termination_reason') }}<textarea name="reason" maxlength="2000" required></textarea></label><div><button class="erp-link erp-danger">{{ __('enterprise_procurement.completion.terminate') }}</button></div></form>@endif
@endif
<h3>{{ __('enterprise_procurement.documents') }}</h3><div class="erp-lines">@forelse($contractDetail['documents'] as $document)<div class="erp-line"><strong>{{ $document->title }}</strong><span>{{ $document->reference ?? '—' }}</span><span>{{ $document->expires_at ?? '—' }}</span><span></span></div>@empty<p>{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
<h3>{{ __('enterprise_procurement.tabs.orders') }}</h3><div class="erp-lines">@forelse($contractDetail['orders'] as $order)<div class="erp-line"><strong>{{ $order->order_number }}</strong><span>{{ $statusLabel($order->status) }}</span><span>{{ $order->currency_code }}</span><span>{{ $order->needed_by ?? '—' }}</span></div>@empty<p>{{ __('enterprise_procurement.empty') }}</p>@endforelse</div>
</section>
@endif
