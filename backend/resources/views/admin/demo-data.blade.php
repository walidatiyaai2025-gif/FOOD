<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.security.demo_data.title') }} · FOODEX</title>
<style>
:root{color:#17202a;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}.page{max-width:1100px;margin:auto;padding:28px}.top,.head,.actions{display:flex;gap:14px;justify-content:space-between;align-items:flex-start}.top{margin-bottom:22px}.muted{color:#64748b;line-height:1.55}.back,.card,.panel{background:#fff;border:1px solid #e2e8f0;border-radius:16px}.back{padding:10px 14px;text-decoration:none;color:#0f172a}.panel{padding:22px;margin-bottom:22px;box-shadow:0 14px 35px rgba(15,23,42,.05)}.card{padding:16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.count{font-size:1.55rem;font-weight:800;margin-top:6px}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:750;cursor:pointer}.success{background:#166534;color:#fff}.danger{background:#be123c;color:#fff}.badge{display:inline-block;border-radius:999px;padding:5px 9px;font-size:.78rem;background:#e2e8f0}.warning{background:#fff7ed;border:1px solid #fdba74;padding:12px 14px;border-radius:12px;margin:14px 0}.notice,.errors{padding:12px 14px;border-radius:12px;margin-bottom:14px}.notice{background:#f0fdf4}.errors{background:#fff1f2}input{font:inherit;border:1px solid #cbd5e1;border-radius:10px;padding:10px 11px;width:100%}.actions{align-items:stretch;flex-wrap:wrap}.action-card{flex:1;min-width:280px}.action-card form{display:grid;gap:12px}@media(max-width:760px){.page{padding:16px}.top,.head,.actions{flex-direction:column}}
</style>
@include('admin._brand-components')
</head>
<body><main class="page foodex-admin-page" data-foodex-utility="demo-data">
<header class="top foodex-page-header">
<div><div class="muted">FOODEX · {{ __('admin.security.demo_data.eyebrow') }}</div><h1>{{ __('admin.security.demo_data.title') }}</h1><p class="muted">{{ __('admin.security.demo_data.description') }}</p></div>
<a class="back" href="{{ route('admin.index') }}">{{ __('admin.security.back') }}</a>
</header>
@if(session('status'))<div class="notice foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="panel">
<div class="head"><div><h2>{{ __('admin.security.demo_data.summary') }}</h2><div class="muted">{{ __('admin.security.demo_data.summary_description') }}</div></div><span class="badge">{{ strtoupper($environment) }}</span></div>
<div class="grid">
@foreach($demoSummary as $key=>$count)
<div class="card"><strong>{{ __('admin.security.demo_data.counts.'.$key) }}</strong><div class="count foodex-number">{{ $count }}</div></div>
@endforeach
</div>
<div class="warning">{{ __('admin.security.demo_data.production_warning') }}</div>
</section>

<section class="actions">
<div class="panel action-card">
<h2>{{ __('admin.security.demo_data.seed') }}</h2>
<p class="muted">{{ __('admin.security.demo_data.seed_description') }}</p>
<form method="post" action="{{ route('admin.security.demo-data.seed') }}" onsubmit="return confirm('{{ __('admin.security.demo_data.seed_confirm') }}')">
@csrf
<button class="btn success" type="submit">{{ __('admin.security.demo_data.seed') }}</button>
</form>
</div>
<div class="panel action-card">
<h2>{{ __('admin.security.demo_data.clear') }}</h2>
<p class="muted">{{ __('admin.security.demo_data.safety') }}</p>
<form method="post" action="{{ route('admin.security.demo-data.clear') }}" onsubmit="return confirm('{{ __('admin.security.demo_data.confirm') }}')">
@csrf @method('delete')
<label><strong>{{ __('admin.security.demo_data.confirmation_label') }}</strong></label>
<input type="text" name="confirmation" required autocomplete="off" placeholder="{{ app()->getLocale()==='ar'?'حذف بيانات العرض':'DELETE DEMO DATA' }}">
<button class="btn danger" type="submit">{{ __('admin.security.demo_data.clear') }}</button>
</form>
</div>
</section>
</main></body></html>
