<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{--foodex-action:#137A35;--foodex-action-hover:#165D2D;--foodex-text:#17202a;--foodex-muted:#5f6b78;--foodex-border:#d9e0e8;--foodex-soft:#f7faf8}
*{box-sizing:border-box}
body{font-family:"Tajawal",sans-serif;max-width:900px;margin:0 auto;padding:28px;line-height:1.7;color:var(--foodex-text);background:#fff;text-align:start}
header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap}
h1{line-height:1.25;margin:.2rem 0}h2{line-height:1.3}
a{color:var(--foodex-action);font-weight:600;text-underline-offset:3px}
a:hover{color:var(--foodex-action-hover)}
a:focus-visible{outline:3px solid var(--foodex-action-hover);outline-offset:3px;border-radius:4px}
.card{border:1px solid var(--foodex-border);border-radius:14px;padding:18px;margin:18px 0;background:#fff}
.muted{color:var(--foodex-muted)}
.language-switch,.public-nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.language-switch a[aria-current="true"]{font-weight:700;text-decoration-thickness:2px}
.support-action{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;border-radius:10px;background:var(--foodex-action);color:#fff;text-decoration:none}
.support-action:hover{background:var(--foodex-action-hover);color:#fff}
.support-fallback{background:var(--foodex-soft)}
footer{margin-top:24px;padding-top:18px;border-top:1px solid var(--foodex-border)}
@media(max-width:600px){body{padding:18px 16px}.card{padding:16px}.support-action{width:100%}}
</style>
</head>
<body>
<header>
    <div>
        <h1>{{ $title }}</h1>
        <p class="muted">{{ __('public_information.public_context') }}</p>
    </div>
    <nav class="language-switch" aria-label="{{ __('public_information.language') }}">
        <a href="{{ route(request()->route()->getName(), ['lang' => 'en']) }}" lang="en" @if($locale === 'en') aria-current="true" @endif>{{ __('public_information.english') }}</a>
        <span aria-hidden="true">·</span>
        <a href="{{ route(request()->route()->getName(), ['lang' => 'ar']) }}" lang="ar" @if($locale === 'ar') aria-current="true" @endif>{{ __('public_information.arabic') }}</a>
    </nav>
</header>

<main>
    <section class="card" aria-labelledby="public-information-heading">
        <h2 id="public-information-heading" class="visually-hidden" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0">{{ $title }}</h2>
        @foreach($paragraphs as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </section>

    @if(is_array($supportContact))
        @if(($supportContact['type'] ?? '') === 'whatsapp')
            <section class="card" aria-labelledby="public-support-contact-heading">
                <h2 id="public-support-contact-heading">{{ __('public_information.support.contact_heading') }}</h2>
                <p>{{ __('public_information.support.whatsapp_label', ['number' => $supportContact['label']]) }}</p>
                <a class="support-action" href="{{ $supportContact['href'] }}" rel="noopener noreferrer">{{ __('public_information.support.whatsapp_action') }}</a>
                <p class="muted">{{ __('public_information.support.reviewer_note') }}</p>
            </section>
        @else
            <section class="card support-fallback" aria-labelledby="public-support-fallback-heading">
                <h2 id="public-support-fallback-heading">{{ __('public_information.support.fallback_heading') }}</h2>
                <p><strong>{{ $supportContact['label'] }}</strong></p>
                <p>{{ __('public_information.support.fallback_body') }}</p>
                <p class="muted">{{ __('public_information.support.reviewer_note') }}</p>
            </section>
        @endif
    @endif
</main>

<footer>
    <nav class="public-nav" aria-label="{{ __('public_information.public_context') }}">
        <a href="{{ route('public.support', ['lang' => $locale]) }}">{{ __('public_information.navigation.support') }}</a>
        <span aria-hidden="true">·</span>
        <a href="{{ route('public.privacy', ['lang' => $locale]) }}">{{ __('public_information.navigation.privacy') }}</a>
        <span aria-hidden="true">·</span>
        <a href="{{ route('public.terms', ['lang' => $locale]) }}">{{ __('public_information.navigation.terms') }}</a>
        <span aria-hidden="true">·</span>
        <a href="{{ route('public.account-deletion', ['lang' => $locale]) }}">{{ __('public_information.navigation.account_deletion') }}</a>
    </nav>
</footer>
</body>
</html>
