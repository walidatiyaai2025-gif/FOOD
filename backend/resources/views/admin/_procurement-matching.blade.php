<section class="foodex-card erp-detail"><h2>{{ __('enterprise_procurement.completion.matching_title') }}</h2></section>
<section class="foodex-card erp-table-wrap"><table class="foodex-table erp-table"><thead><tr><th>{{ __('enterprise_procurement.reference') }}</th><th>{{ __('enterprise_procurement.supplier') }}</th><th>{{ __('enterprise_procurement.completion.match_status') }}</th><th>{{ __('enterprise_procurement.completion.value_variance') }}</th><th>{{ __('enterprise_procurement.actions') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->invoice_number }}</td><td>{{ $row->supplier_code }}</td><td>{{ $statusLabel($row->match_status) }}</td><td>{{ $row->value_variance ?? '—' }} {{ $row->currency_code }}</td><td><a class="erp-link" href="{{ route('admin.erp.procurement.index',['store_id'=>$selectedStoreId,'tab'=>'matching','invoice'=>$row->purchase_invoice_id]) }}">{{ __('enterprise_procurement.view') }}</a></td></tr>@empty<tr><td colspan="5">{{ __('enterprise_procurement.empty') }}</td></tr>@endforelse
</tbody></table></section>
@if($matchDetail)
@php($review=$matchDetail['review'];$invoice=$matchDetail['invoice'])
<section class="foodex-card erp-detail"><h2>{{ $invoice->invoice_number }} · {{ $statusLabel($review->status) }}</h2>
<div class="erp-detail-grid">
<div><small>{{ __('enterprise_procurement.completion.quantity_variance') }}</small><strong>{{ $review->quantity_variance }}</strong></div>
<div><small>{{ __('enterprise_procurement.completion.receipt_variance') }}</small><strong>{{ $review->receipt_variance }}</strong></div>
<div><small>{{ __('enterprise_procurement.completion.price_variance') }}</small><strong>{{ $review->price_variance }}</strong></div>
<div><small>{{ __('enterprise_procurement.completion.value_variance') }}</small><strong>{{ $review->value_variance }} {{ $invoice->currency_code }}</strong></div>
</div>
<form method="post" action="{{ route('admin.erp.procurement.matching.refresh',$invoice->id) }}" class="erp-actions">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label>{{ __('enterprise_procurement.completion.tolerance_percent') }}<input type="number" name="tolerance_percent" value="{{ $review->tolerance_percent }}" min="0" max="25" step="0.001"></label><button class="erp-link">{{ __('enterprise_procurement.completion.refresh_match') }}</button></form>
<div class="erp-lines">@foreach($matchDetail['lines'] as $line)<div class="erp-line"><strong>{{ $line['description'] }}</strong><span>{{ $line['ordered_quantity'] }} / {{ $line['received_quantity'] }} / {{ $line['invoice_quantity'] }}</span><span>{{ $line['po_unit_price'] }} / {{ $line['invoice_unit_price'] }}</span><span>{{ implode(', ', $line['exceptions']) ?: $statusLabel('matched') }}</span></div>@endforeach</div>
@if($can['invoice_handoff'] && $review->status==='exception')
<form method="post" action="{{ route('admin.erp.procurement.matching.decision',$invoice->id) }}" class="erp-form">@csrf<input type="hidden" name="store_id" value="{{ $selectedStoreId }}"><label class="span-2">{{ __('enterprise_procurement.completion.decision_note') }}<textarea name="note" maxlength="2000"></textarea></label><div class="erp-actions"><button class="foodex-primary" name="decision" value="approve">{{ __('enterprise_procurement.approve') }}</button><button class="erp-link erp-danger" name="decision" value="reject">{{ __('enterprise_procurement.reject') }}</button></div></form>
@endif
</section>
@endif
