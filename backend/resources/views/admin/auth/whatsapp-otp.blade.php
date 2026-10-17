<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin_login.otp_title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:var(--foodex-font-ui);background:linear-gradient(135deg,var(--foodex-background),var(--foodex-orange-soft));color:var(--foodex-ink);display:grid;place-items:center;padding:20px}
        .card{width:min(100%,500px);background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:24px;padding:clamp(26px,5vw,44px);box-shadow:0 28px 70px rgba(16,24,40,.10)}
        .brand{font-weight:800;font-size:28px;letter-spacing:.04em;margin-bottom:24px}.brand span{color:var(--foodex-orange)}
        h1{font-size:28px;margin:0 0 10px}.help{margin:0 0 24px;color:var(--foodex-muted);line-height:1.7}.field{margin-bottom:16px}label{display:block;font-size:13px;font-weight:800;margin-bottom:7px}
        input{width:100%;border:1px solid var(--foodex-border);border-radius:12px;padding:14px;font:700 1.25rem ui-monospace,monospace;letter-spacing:.18em;text-align:center;direction:ltr}
        input:focus{outline:none;border-color:var(--foodex-green);box-shadow:0 0 0 3px rgba(21,138,58,.12)}
        button{width:100%;border:0;border-radius:12px;padding:14px 16px;background:var(--foodex-green);color:var(--foodex-surface);font:inherit;font-weight:800;cursor:pointer}
        .secondary{margin-top:10px;background:var(--foodex-surface);color:var(--foodex-ink);border:1px solid var(--foodex-border)}.error,.status{border-radius:12px;padding:11px 13px;font-size:13px;margin-bottom:16px}.error{background:var(--foodex-danger-soft);border:1px solid var(--foodex-danger-text);color:var(--foodex-danger-text)}.status{background:var(--foodex-green-soft);border:1px solid rgba(21,138,58,.25)}
        .back{display:block;text-align:center;margin-top:18px;color:var(--foodex-orange);font-weight:800;text-decoration:none}.expired{padding:12px;border-radius:12px;background:var(--foodex-orange-soft);border:1px solid var(--foodex-orange);margin-bottom:16px}
    </style>
</head>
<body>
<section class="card">
    <div class="brand">FOOD<span>EX</span></div>
    <h1>{{ __('admin_login.otp_heading') }}</h1>
    <p class="help">{{ __('admin_login.otp_help') }}</p>

    @if(session('status'))<div class="status" role="status">{{ session('status') }}</div>@endif
    @if($expired)<div class="expired">{{ __('admin_login.otp_expired') }}</div>@endif
    @if($errors->any())
        <div class="error" role="alert">
            <ul>
                @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('admin.'.$channel.'.login.whatsapp.verify', ['challenge'=>$challenge]) }}">
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}">
        <div class="field">
            <label for="code">{{ __('admin_login.otp_code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus @if($errors->has('code')) aria-invalid="true" @endif>
        </div>
        <button type="submit" @if($expired) disabled @endif>{{ __('admin_login.otp_verify') }}</button>
    </form>

    <form method="post" action="{{ route('admin.'.$channel.'.login.whatsapp.resend', ['challenge'=>$challenge]) }}">
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}">
        <button class="secondary" type="submit" @if($expired) disabled @endif>{{ __('admin_login.otp_resend') }}</button>
    </form>

    <a class="back" href="{{ route('admin.'.$channel.'.login', ['locale'=>$locale]) }}">{{ __('admin_login.otp_back') }}</a>
</section>
</body>
</html>
