<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>{{ __('supplier_procurement_portal.title') }} · FOODEX</title>
<style>
:root{font-family:Inter,system-ui,sans-serif;color:#172033;background:#f7f9fc}.portal{max-width:980px;margin:0 auto;padding:24px;display:grid;gap:16px}.card{background:#fff;border:1px solid #e6eaf0;border-radius:16px;padding:18px}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.muted{color:#667085}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.field{display:grid;gap:5px;font-weight:700;font-size:.86rem}.field input,.field textarea,.field select{font:inherit;padding:10px;border:1px solid #d7dce5;border-radius:9px;background:#fff}.lines{overflow:auto}.lines table{width:100%;border-collapse:collapse;min-width:620px}.lines th,.lines td{padding:10px;border-bottom:1px solid #e6eaf0;text-align:start}.button{display:inline-flex;min-height:42px;align-items:center;justify-content:center;border:0;border-radius:10px;padding:0 14px;font-weight:800;cursor:pointer;background:#158a3a;color:#fff;text-decoration:none}.button.secondary{background:#fff;color:#172033;border:1px solid #d7dce5}.actions{display:flex;gap:8px;flex-wrap:wrap}.state{padding:12px 14px;border-radius:12px;background:#eaf7ef}.rfq{display:grid;gap:14px}.quote{padding:10px;border-radius:10px;background:#f7f9fc}.danger{color:#a61b1b}.lang{display:flex;gap:8px}.security{border-inline-start:4px solid #158a3a}.flash{background:#eaf7ef;border:1px solid #b9dfc6}
@media(max-width:600px){.portal{padding:14px}.card{padding:14px}.actions>*{flex:1}.button{width:100%}}
</style>
</head>
<body>
<main class="portal">
<header class="card head">
<div>
<div class="muted">{{ __('supplier_procurement_portal.subtitle') }}</div>
<h1>{{ __('supplier_procurement_portal.title') }}</h1>
<p><strong>{{ __('supplier_procurement_portal.supplier') }}:</strong> {{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }} · {{ $supplier->code }}</p>
</div>
<div class="lang" aria-label="{{ __('supplier_procurement_portal.language') }}">
<a class="button secondary" href="{{ route('supplier.procurement.index',['token'=>$plainToken,'locale'=>'ar']) }}">العربية</a>
<a class="button secondary" href="{{ route('supplier.procurement.index',['token'=>$plainToken,'locale'=>'en']) }}">English</a>
</div>
</header>

@if(session('status'))<div class="card flash" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="card" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="card security">
<p>{{ __('supplier_procurement_portal.security_note') }}</p>
</section>

<section class="card">
<h2>{{ __('supplier_procurement_portal.open_rfqs') }}</h2>
@forelse($rfqs as $rfq)
<article class="rfq">
<div class="head">
<div>
<h3>{{ $rfq->rfq_number }} · {{ $rfq->title }}</h3>
<div class="muted">{{ __('supplier_procurement_portal.closes_at') }}: {{ $rfq->closes_at ?? '—' }} · {{ __('supplier_procurement_portal.currency') }}: {{ $rfq->currency_code }}</div>
</div>
<div>{{ __('supplier_procurement_portal.status') }}: {{ __('supplier_procurement_portal.statuses.'.($rfq->invitation_status ?: 'invited')) }}</div>
</div>

<div class="lines">
<table>
<thead><tr><th>{{ __('supplier_procurement_portal.description') }}</th><th>{{ __('supplier_procurement_portal.quantity') }}</th><th>{{ __('supplier_procurement_portal.unit') }}</th><th>{{ __('supplier_procurement_portal.estimated_cost') }}</th><th>{{ __('supplier_procurement_portal.unit_price') }}</th></tr></thead>
<tbody>
<form id="quote-{{ $rfq->id }}" method="post" action="{{ route('supplier.procurement.quote',['token'=>$plainToken,'rfq'=>$rfq->id]) }}">
@csrf
<input type="hidden" name="locale" value="{{ app()->getLocale() }}">
@foreach(($lines[$rfq->id] ?? collect()) as $index => $line)
<tr>
<td>{{ $line->description }}</td>
<td>{{ $line->quantity }}</td>
<td>{{ $line->unit }}</td>
<td>{{ $line->estimated_unit_cost ?? '—' }} {{ $rfq->currency_code }}</td>
<td>
<input type="hidden" name="lines[{{ $index }}][rfq_line_id]" value="{{ $line->id }}">
<input aria-label="{{ __('supplier_procurement_portal.unit_price') }}" type="number" name="lines[{{ $index }}][unit_price]" step="0.001" min="0" required>
</td>
</tr>
@endforeach
</form>
</tbody>
</table>
</div>

@if($rfq->quote_id)
<div class="quote"><strong>{{ __('supplier_procurement_portal.existing_quote') }}</strong>: {{ $rfq->quote_total }} {{ $rfq->currency_code }} · {{ __('supplier_procurement_portal.statuses.'.($rfq->quote_status ?: 'submitted')) }}</div>
@endif

<div class="grid">
<label class="field">{{ __('supplier_procurement_portal.supplier_reference') }}<input form="quote-{{ $rfq->id }}" name="supplier_reference" maxlength="96"></label>
<label class="field">{{ __('supplier_procurement_portal.lead_time_days') }}<input form="quote-{{ $rfq->id }}" type="number" name="lead_time_days" min="0" max="3650"></label>
<label class="field">{{ __('supplier_procurement_portal.valid_until') }}<input form="quote-{{ $rfq->id }}" type="date" name="valid_until"></label>
<label class="field">{{ __('supplier_procurement_portal.notes') }}<textarea form="quote-{{ $rfq->id }}" name="notes" maxlength="4000"></textarea></label>
</div>
<div class="actions">
<button form="quote-{{ $rfq->id }}" class="button">{{ __('supplier_procurement_portal.submit_quote') }}</button>
<form method="post" action="{{ route('supplier.procurement.decline',['token'=>$plainToken,'rfq'=>$rfq->id]) }}">
@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() }}">
<button class="button secondary danger">{{ __('supplier_procurement_portal.decline') }}</button>
</form>
</div>
</article>
@empty
<p class="muted">{{ __('supplier_procurement_portal.no_rfqs') }}</p>
@endforelse
</section>

@if(in_array('documents',(array)$token->capabilities,true))
<section class="card">
<h2>{{ __('supplier_procurement_portal.document_title') }}</h2>
<form method="post" action="{{ route('supplier.procurement.document',['token'=>$plainToken]) }}" class="grid">
@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() }}">
<label class="field">{{ __('supplier_procurement_portal.category') }}<input name="category" maxlength="64" required></label>
<label class="field">{{ __('supplier_procurement_portal.document_name') }}<input name="title" maxlength="190" required></label>
<label class="field">{{ __('supplier_procurement_portal.document_reference') }}<input name="reference" maxlength="190"></label>
<label class="field">{{ __('supplier_procurement_portal.expires_at') }}<input type="date" name="expires_at"></label>
<div><button class="button">{{ __('supplier_procurement_portal.register_document') }}</button></div>
</form>
</section>
@endif
</main>
</body>
</html>
