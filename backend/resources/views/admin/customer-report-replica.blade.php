<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $reportTitle }} · {{ $customer->name }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.replica-viewer{min-height:100vh;display:grid;grid-template-rows:auto 1fr}
.replica-toolbar{display:flex;align-items:end;gap:10px;flex-wrap:wrap;padding:12px 16px;background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border);position:sticky;top:0;z-index:30}
.replica-toolbar form{display:flex;align-items:end;gap:8px;flex-wrap:wrap}
.replica-toolbar label{display:grid;gap:4px;font-weight:700}.replica-toolbar input{min-height:40px}
.replica-title{margin-inline-end:auto}.replica-title h1{margin:0;font-size:1.05rem}.replica-title small{color:var(--foodex-muted)}
.replica-frame{width:100%;height:calc(100vh - 78px);border:0;background:var(--foodex-surface)}
@media(max-width:720px){.replica-toolbar{align-items:stretch}.replica-title{width:100%;margin-inline-end:0}.replica-toolbar form{width:100%}.replica-toolbar label{flex:1;min-width:130px}.replica-frame{height:calc(100vh - 160px)}}
</style>
</head>
<body>
@php($pdfUrl=route('admin.customer-360.reports.pdf',[
    'platformCustomer'=>$customer->id,
    'report'=>$report,
    'from'=>$from,
    'to'=>$to,
    'inline'=>'1',
]))
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="replica-viewer">
<header class="replica-toolbar foodex-page-toolbar">
<div class="replica-title"><h1>{{ $reportTitle }}</h1><small>{{ $customer->name }} · {{ __('customer_360.reports.rows') }}: {{ $rowCount }}</small></div>
<form method="get" action="{{ route('admin.customer-360.reports.show',['platformCustomer'=>$customer->id,'report'=>$report]) }}">
<label><small>{{ __('customer_360.reports.from') }}</small><input type="date" name="from" value="{{ $from }}" required></label>
<label><small>{{ __('customer_360.reports.to') }}</small><input type="date" name="to" value="{{ $to }}" required></label>
<button class="foodex-action-primary" type="submit">{{ __('customer_360.reports.view') }}</button>
</form>
<a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.reports.pdf',['platformCustomer'=>$customer->id,'report'=>$report,'from'=>$from,'to'=>$to]) }}">{{ __('customer_360.reports.download_pdf') }}</a>
<button class="foodex-action-secondary button secondary" type="button" data-report-print>{{ __('customer_360.reports.print') }}</button>
<a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.show',['platformCustomer'=>$customer->id]) }}#reports">{{ __('customer_360.reports.back') }}</a>
</header>
<iframe class="replica-frame" src="{{ $pdfUrl }}" title="{{ $reportTitle }}" data-report-frame></iframe>
</div>
</main>
</div>
<script>
document.querySelector('[data-report-print]')?.addEventListener('click', () => {
    const frame = document.querySelector('[data-report-frame]');
    frame?.contentWindow?.focus();
    frame?.contentWindow?.print();
});
</script>
</body>
</html>
