<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_procurement.portal.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.supplier-rfq{max-width:980px;margin:0 auto;padding:24px;display:grid;gap:16px}
.supplier-rfq__header{display:grid;gap:6px}
.supplier-rfq__meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.supplier-rfq__meta>div{padding:12px;border:1px solid var(--foodex-border);border-radius:12px}
.supplier-rfq__line{display:grid;grid-template-columns:minmax(180px,2fr) minmax(110px,1fr) minmax(140px,1fr) minmax(140px,1fr);gap:10px;align-items:end;padding:12px;border-bottom:1px solid var(--foodex-border)}
.supplier-rfq__line label{display:grid;gap:5px;font-weight:700}
.supplier-rfq__form{display:grid;gap:14px}
.supplier-rfq__form-footer{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
.supplier-rfq__form-footer label{display:grid;gap:5px;font-weight:700}
@media(max-width:767px){.supplier-rfq{padding:14px}.supplier-rfq__line{grid-template-columns:1fr}.supplier-rfq__line input,.supplier-rfq__form-footer input,.supplier-rfq__form-footer textarea{font-size:16px}}
</style>
</head>
<body>
<main class="supplier-rfq">
<header class="foodex-card supplier-rfq__header">
<div class="foodex-subtitle">{{ __('enterprise_procurement.portal.supplier_collaboration') }}</div>
<h1>{{ $rfq->title }}</h1>
<p>{{ __('enterprise_procurement.portal.instructions') }}</p>
<div class="supplier-rfq__meta">
<div><small>{{ __('enterprise_procurement.reference') }}</small><strong>{{ $rfq->rfq_number }}</strong></div>
<div><small>{{ __('enterprise_procurement.supplier') }}</small><strong>{{ app()->getLocale()==='ar' && $supplier->name_ar ? $supplier->name_ar : $supplier->name_en }}</strong></div>
<div><small>{{ __('enterprise_procurement.currency') }}</small><strong>{{ $rfq->currency_code }}</strong></div>
<div><small>{{ __('enterprise_procurement.status') }}</small><strong>{{ $existingQuote ? __('enterprise_procurement.statuses.'.$existingQuote->status) : __('enterprise_procurement.statuses.invited') }}</strong></div>
<div><small>{{ __('enterprise_procurement.portal.response_due') }}</small><strong>{{ $rfq->response_due_at ?: $invitation->expires_at }}</strong></div>
</div>
</header>

@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="foodex-card">
<form method="post" action="{{ route('supplier.rfq.submit',['token'=>$token]) }}" class="supplier-rfq__form" data-procurement-supplier-quote enctype="multipart/form-data">
@csrf
<div>
@foreach($lines as $index=>$line)
@php($saved=$existingLines->get($line->id))
<div class="supplier-rfq__line">
<div><strong>{{ $line->description }}</strong><div class="foodex-subtitle">{{ $line->item_code ?: '—' }}</div></div>
<div><small>{{ __('enterprise_procurement.quantity') }}</small><strong>{{ $line->quantity }} {{ $line->unit }}</strong></div>
<input type="hidden" name="lines[{{ $index }}][rfq_line_id]" value="{{ $line->id }}">
<label>{{ __('enterprise_procurement.portal.unit_price') }}<input type="number" name="lines[{{ $index }}][unit_price]" min="0" step="0.001" required value="{{ old('lines.'.$index.'.unit_price',$saved?->unit_price) }}"></label>
<label>{{ __('enterprise_procurement.portal.lead_time_days') }}<input type="number" name="lines[{{ $index }}][lead_time_days]" min="0" max="3650" value="{{ old('lines.'.$index.'.lead_time_days',$saved?->lead_time_days) }}"></label>
</div>
@endforeach
</div>
<div class="supplier-rfq__form-footer">
<label>{{ __('enterprise_procurement.portal.valid_until') }}<input type="date" name="valid_until" value="{{ old('valid_until',$existingQuote?->valid_until) }}"></label>
<label>{{ __('enterprise_procurement.portal.lead_time_days') }}<input type="number" name="lead_time_days" min="0" max="3650" value="{{ old('lead_time_days',$existingQuote?->lead_time_days) }}"></label>
<label style="grid-column:1/-1">{{ __('enterprise_procurement.notes') }}<textarea name="notes" maxlength="4000">{{ old('notes',$existingQuote?->notes) }}</textarea></label>
<label style="grid-column:1/-1">{{ __('enterprise_procurement.attachment') }}<input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.doc,.docx"></label>
</div>
@if($quoteDocuments->isNotEmpty())<div class="foodex-state"><strong>{{ __('enterprise_procurement.documents') }}</strong><ul>@foreach($quoteDocuments as $document)<li>{{ $document->original_name }}</li>@endforeach</ul></div>@endif
<button class="foodex-primary" type="submit">{{ __('enterprise_procurement.portal.submit_quote') }}</button>
</form>
</section>
</main>
<script>
document.querySelectorAll('[data-procurement-supplier-quote]').forEach((form)=>{
    form.addEventListener('submit',()=>{
        const button=form.querySelector('button[type="submit"]');
        if(button){button.disabled=true;button.setAttribute('aria-busy','true');}
    },{once:true});
});
</script>
</body>
</html>
