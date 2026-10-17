<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('security_governance.mfa.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:#f5f7fa}.mfa-layout{display:block;min-height:100vh}.mfa-shell{max-width:720px;margin:8vh auto;padding:20px}.mfa-header{margin-bottom:14px}.mfa-header h1{margin:0}.mfa-header p{margin:6px 0 0;color:#64748b}.mfa-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;box-shadow:0 18px 50px rgba(15,23,42,.08)}.mfa-form{display:grid;gap:12px}.mfa-code{font:700 1rem ui-monospace,monospace;direction:ltr;overflow-wrap:anywhere;background:#f8fafc;padding:12px;border-radius:10px}.mfa-input{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-size:1.1rem}.mfa-button{border:0;border-radius:10px;padding:11px 15px;background:#0f172a;color:#fff;font-weight:800}.mfa-muted{color:#64748b;line-height:1.6}.mfa-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;direction:ltr}.mfa-list code{background:#f8fafc;padding:8px;border-radius:8px}@media(max-width:520px){.mfa-shell{margin:0;padding:12px}.mfa-card{padding:16px}.mfa-list{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout mfa-layout">
<main class="foodex-admin-main foodex-admin-page mfa-shell">
<header class="foodex-page-header mfa-header">
<h1>{{ __('security_governance.mfa.title') }}</h1>
</header>
<section class="mfa-card">
@if(session('status'))<div role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($mode === 'enroll')
<p class="mfa-muted">{{ __('security_governance.mfa.enroll_help') }}</p>
<p>{{ __('security_governance.mfa.secret') }}</p><div class="mfa-code">{{ $secret }}</div>
<details><summary>{{ __('security_governance.mfa.provisioning_uri') }}</summary><div class="mfa-code">{{ $provisioningUri }}</div></details>
<form class="mfa-form" method="post" action="{{ route('admin.mfa.enroll.confirm') }}">@csrf
<label>{{ __('security_governance.mfa.code') }}<input class="mfa-input" name="code" autocomplete="one-time-code" inputmode="numeric" required></label>
<button class="mfa-button" type="submit">{{ __('security_governance.mfa.enable') }}</button>
</form>
@elseif($mode === 'challenge')
<p class="mfa-muted">{{ __('security_governance.mfa.challenge_help') }}</p>
<form class="mfa-form" method="post" action="{{ route('admin.mfa.verify') }}">@csrf
<label>{{ __('security_governance.mfa.code_or_recovery') }}<input class="mfa-input" name="code" autocomplete="one-time-code" required autofocus></label>
<button class="mfa-button" type="submit">{{ __('security_governance.mfa.verify') }}</button>
</form>
@else
<p class="mfa-muted">{{ __('security_governance.mfa.enabled_help') }}</p>
@if($recoveryCodes)
<h2>{{ __('security_governance.mfa.recovery_codes') }}</h2>
<p class="mfa-muted">{{ __('security_governance.mfa.recovery_warning') }}</p>
<div class="mfa-list">@foreach($recoveryCodes as $code)<code>{{ $code }}</code>@endforeach</div>
@endif
<a href="{{ route('admin.security.index') }}">{{ __('security_governance.back_security') }}</a>
@endif
</section>
</main>
</div>
</body></html>
