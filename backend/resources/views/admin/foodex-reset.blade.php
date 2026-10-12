<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('foodex_reset.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.reset-wrap{max-width:980px;margin:0 auto}.reset-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.6fr);gap:16px}
.reset-card{background:#fff;border:1px solid var(--foodex-border);border-radius:16px;padding:18px;box-shadow:var(--foodex-shadow-sm)}
.reset-warning{border-inline-start:5px solid #b42318;background:#fff5f3}.reset-list{display:grid;gap:8px;margin:14px 0}
.reset-row{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid var(--foodex-border)}
.reset-form{display:grid;gap:12px}.reset-form label{font-weight:900}.reset-form input{min-height:44px;border:1px solid var(--foodex-border);border-radius:10px;padding:0 12px;font:inherit;width:100%;box-sizing:border-box}
.reset-button{min-height:46px;border:0;border-radius:10px;background:#b42318;color:#fff;font:inherit;font-weight:900;padding:0 16px;cursor:pointer}
.reset-button[disabled]{opacity:.5;cursor:not-allowed}.reset-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr;unicode-bidi:embed}
.reset-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900}
.reset-badge.blocked{background:#fff0ed;color:#b42318}
@media(max-width:760px){.reset-grid{grid-template-columns:1fr}.reset-card{padding:14px}.reset-row{align-items:flex-start}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-reset="true">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext,'user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page">
<div class="reset-wrap">
@include('admin._admin-center-switcher',['active'=>'settings'])
<header class="foodex-page-header">
<div><span class="foodex-subtitle">FOODEX</span><h1>{{ __('foodex_reset.title') }}</h1><p>{{ __('foodex_reset.description') }}</p></div>
@include('admin._live-notifications',['user'=>$user])
</header>

@if(session('status'))<div class="foodex-alert foodex-alert--success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-alert foodex-alert--error">{{ $errors->first() }}</div>@endif

<div class="reset-grid">
<section class="reset-card">
<h2>{{ __('foodex_reset.impact_title') }}</h2>
<p>{{ __('foodex_reset.impact_description') }}</p>
<div class="reset-list">
@foreach($preview['tables'] as $table => $count)
<div class="reset-row"><span class="reset-code">{{ $table }}</span><strong>{{ number_format($count) }}</strong></div>
@endforeach
</div>
<div class="reset-row"><strong>{{ __('foodex_reset.total_rows') }}</strong><strong>{{ number_format($preview['total_rows']) }}</strong></div>
<p><span class="reset-badge {{ $preview['seed_ready'] ? '' : 'blocked' }}">{{ $preview['seed_ready'] ? __('foodex_reset.seed_ready') : __('foodex_reset.seed_blocked') }}</span></p>
<p>{{ __('foodex_reset.preserved') }}</p>
</section>

<section class="reset-card reset-warning">
<h2>{{ __('foodex_reset.execute_title') }}</h2>
<p>{{ __('foodex_reset.execute_description') }}</p>
<form method="post" action="{{ route('admin.foodex-reset.store') }}" class="reset-form">
@csrf
<label for="confirmation">{{ __('foodex_reset.confirmation_label') }}</label>
<p>{{ __('foodex_reset.type_exactly') }} <strong class="reset-code">{{ $preview['confirmation'] }}</strong></p>
<input id="confirmation" name="confirmation" autocomplete="off" required value="{{ old('confirmation') }}">

@if($preview['requires_backup'])
<label for="backup_reference">{{ __('foodex_reset.backup_reference') }}</label>
<input id="backup_reference" name="backup_reference" autocomplete="off" required value="{{ old('backup_reference') }}">
<label for="production_confirmation">{{ __('foodex_reset.production_confirmation_label') }}</label>
<p>{{ __('foodex_reset.type_exactly') }} <strong class="reset-code">{{ $preview['production_confirmation'] }}</strong></p>
<input id="production_confirmation" name="production_confirmation" autocomplete="off" required value="{{ old('production_confirmation') }}">
@endif

<button class="reset-button" type="submit" {{ $preview['seed_ready'] ? '' : 'disabled' }}>{{ __('foodex_reset.execute') }}</button>
</form>
</section>
</div>
</div>
</main>
</div>
</body>
</html>
