<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('document_branding.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{background:var(--foodex-background);color:var(--foodex-ink);margin:0;font-family:inherit}
.document-wrap{max-width:800px;margin:30px auto;padding:16px}
.document-card{background:var(--foodex-surface,#fff);border:1px solid var(--foodex-border);border-radius:18px;padding:24px}
.document-card form{display:grid;gap:18px}
.document-field{display:grid;gap:7px;font-weight:700}
.document-field input{min-height:44px;padding:10px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface,#fff);color:inherit;font:inherit}
.document-field input[type=file]{height:auto}
.document-logo{max-width:160px;max-height:90px;object-fit:contain}
.document-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.document-actions button{background:var(--foodex-action-green);color:#fff;border:0;border-radius:10px;padding:12px 24px;font-weight:800;cursor:pointer}
.document-actions a{color:var(--foodex-green-dark)}
.document-feedback{padding:10px 14px;border:1px solid var(--foodex-border);border-radius:10px;margin-bottom:14px}
</style>
</head>
<body>
<main class="document-wrap">
<h1>{{ __('document_branding.heading') }}</h1>
<p>{{ __('document_branding.intro') }}</p>
@if(session('status'))<div class="document-feedback" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
<div class="document-feedback" role="alert">
@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
</div>
@endif
<section class="document-card">
<form action="{{ route('admin.documents.branding.update') }}" method="post" enctype="multipart/form-data">
@csrf
@method('PUT')
<label class="document-field">
<span>{{ __('document_branding.name') }}</span>
<input name="name" type="text" value="{{ old('name', $identity['name']) }}" maxlength="120" required>
</label>
<label class="document-field">
<span>{{ __('document_branding.address') }}</span>
<input name="address" type="text" value="{{ old('address', $identity['address']) }}" maxlength="300" required>
</label>
<label class="document-field">
<span>{{ __('document_branding.phone_primary') }}</span>
<input name="phone_primary" type="tel" value="{{ old('phone_primary', $identity['phone_primary']) }}" maxlength="40" required dir="ltr">
</label>
<label class="document-field">
<span>{{ __('document_branding.phone_secondary') }}</span>
<input name="phone_secondary" type="tel" value="{{ old('phone_secondary', $identity['phone_secondary']) }}" maxlength="40" dir="ltr">
</label>
@if(!empty($identity['logo_data_uri']))
<div><img class="document-logo" src="{{ $identity['logo_data_uri'] }}" alt="{{ __('document_branding.current_logo') }}"></div>
@endif
<label class="document-field">
<span>{{ __('document_branding.logo') }}</span>
<input type="file" name="logo" accept="image/png,image/jpeg">
</label>
<div class="document-actions">
<button type="submit">{{ __('document_branding.save') }}</button>
<a href="{{ route('admin.settings.index') }}">{{ __('document_branding.back') }}</a>
</div>
</form>
</section>
</main>
</body>
</html>
