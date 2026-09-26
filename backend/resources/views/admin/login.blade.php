<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin_login.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:linear-gradient(135deg,#f8fbf9 0%,#fff7ef 100%);color:var(--foodex-ink,#172033)}
        .page{min-height:100vh;display:grid;grid-template-columns:minmax(320px,.9fr) minmax(460px,1.1fr)}
        .brand-pane{padding:clamp(32px,6vw,84px);background:linear-gradient(145deg,var(--foodex-green,#158A3A),#0d642a);color:#fff;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
        .brand-pane:after{content:"";position:absolute;width:420px;height:420px;border-radius:50%;background:rgba(255,255,255,.07);inset:auto -180px -150px auto}
        .brand{font-weight:900;font-size:clamp(30px,5vw,56px);letter-spacing:.04em}.brand span{color:var(--foodex-orange,#EE731C)}
        .brand-copy{max-width:560px;position:relative;z-index:1}.brand-copy h1{font-size:clamp(28px,4vw,48px);margin:0 0 14px;line-height:1.15}.brand-copy p{font-size:17px;line-height:1.8;opacity:.9;margin:0}
        .secure{font-size:13px;opacity:.75;position:relative;z-index:1}
        .form-pane{display:grid;place-items:center;padding:32px}
        .card{width:min(100%,520px);background:#fff;border:1px solid var(--foodex-border,#E6EAF0);border-radius:24px;padding:clamp(28px,5vw,48px);box-shadow:0 28px 70px rgba(16,24,40,.10)}
        .top{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:30px}.eyebrow{color:var(--foodex-green,#158A3A);font-weight:800;font-size:13px;margin:0 0 7px}.card h2{font-size:30px;margin:0}.lang{display:flex;gap:6px}.lang a{border:1px solid var(--foodex-border,#E6EAF0);border-radius:999px;padding:7px 10px;text-decoration:none;color:inherit;font-size:12px}.lang a.active{background:var(--foodex-green-soft,#EAF7EF);border-color:var(--foodex-green,#158A3A);color:var(--foodex-green,#158A3A)}
        label{display:block;font-size:13px;font-weight:750;margin:0 0 7px}.field{margin-bottom:18px}.field input{width:100%;border:1px solid var(--foodex-border,#D9DEE7);border-radius:12px;padding:13px 14px;font:inherit;outline:none;background:#fff}.field input:focus{border-color:var(--foodex-green,#158A3A);box-shadow:0 0 0 3px rgba(21,138,58,.12)}
        .error{background:#fff2f1;border:1px solid #ffd5d1;color:#a92920;border-radius:12px;padding:11px 13px;font-size:13px;margin-bottom:16px}
        button{width:100%;border:0;border-radius:12px;padding:14px 16px;background:var(--foodex-green,#158A3A);color:#fff;font:inherit;font-weight:850;cursor:pointer}button:hover{filter:brightness(.96)}
        .channel-switch{margin:22px 0 0;text-align:center;color:var(--foodex-muted,#667085);font-size:13px}.channel-switch a{color:var(--foodex-orange,#EE731C);font-weight:800;text-decoration:none}
        .no-register{margin-top:18px;padding-top:18px;border-top:1px solid var(--foodex-border,#E6EAF0);font-size:12px;color:var(--foodex-muted,#667085);line-height:1.6;text-align:center}
        @media(max-width:820px){.page{grid-template-columns:1fr}.brand-pane{min-height:220px;padding:30px}.brand-copy h1{font-size:30px}.brand-copy p{font-size:14px}.secure{display:none}.form-pane{padding:20px;margin-top:-26px;position:relative;z-index:2}.card{border-radius:20px}}
    </style>
</head>
<body>
<div class="page">
    <section class="brand-pane">
        <div class="brand">FOOD<span>EX</span></div>
        <div class="brand-copy">
            <h1>{{ $channel === 'b2b' ? __('admin_login.b2b_heading') : __('admin_login.b2c_heading') }}</h1>
            <p>{{ $channel === 'b2b' ? __('admin_login.b2b_description') : __('admin_login.b2c_description') }}</p>
        </div>
        <div class="secure">{{ __('admin_login.secure') }}</div>
    </section>

    <main class="form-pane">
        <section class="card">
            <div class="top">
                <div>
                    <p class="eyebrow">{{ __('admin_login.management') }}</p>
                    <h2>{{ __('admin_login.sign_in') }}</h2>
                </div>
                <div class="lang" aria-label="{{ __('admin_login.language') }}">
                    <a class="{{ $locale === 'ar' ? 'active' : '' }}" href="{{ route('admin.'.$channel.'.login', ['locale'=>'ar']) }}">العربية</a>
                    <a class="{{ $locale === 'en' ? 'active' : '' }}" href="{{ route('admin.'.$channel.'.login', ['locale'=>'en']) }}">EN</a>
                </div>
            </div>

            @if($errors->any())
                <div class="error" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('admin.'.$channel.'.login.store') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <div class="field">
                    <label for="email">{{ __('admin_login.email') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                </div>
                <div class="field">
                    <label for="password">{{ __('admin_login.password') }}</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button type="submit">{{ __('admin_login.sign_in_action') }}</button>
            </form>

            @php $otherChannel = $channel === 'b2b' ? 'b2c' : 'b2b'; @endphp
            <p class="channel-switch">
                {{ __('admin_login.other_channel') }}
                <a href="{{ route('admin.'.$otherChannel.'.login', ['locale'=>$locale]) }}">
                    {{ $otherChannel === 'b2b' ? __('admin_login.channel_b2b') : __('admin_login.channel_b2c') }}
                </a>
            </p>
            <div class="no-register">{{ __('admin_login.no_public_registration') }}</div>
        </section>
    </main>
</div>
</body>
</html>
