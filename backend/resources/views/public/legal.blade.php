<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
<style>body{font-family:"Tajawal",sans-serif;max-width:820px;margin:0 auto;padding:28px;line-height:1.6;color:#17202a}h1{line-height:1.2}a{color:#0f5bd8}.card{border:1px solid #d9e0e8;border-radius:14px;padding:18px;margin:18px 0;background:#fff}.muted{color:#5f6b78}</style>
</head>
<body>
<h1>{{ $title }}</h1>
<p class="muted">FOODEX public information for Customer and Driver/Van store review.</p>
{!! $content !!}
<p><a href="{{ url('/support') }}">Support</a> · <a href="{{ url('/privacy') }}">Privacy</a> · <a href="{{ url('/terms') }}">Terms</a> · <a href="{{ url('/account-deletion') }}">Account deletion</a></p>
</body>
</html>
