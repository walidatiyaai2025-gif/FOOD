<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.title') }} · FOODEX</title>
    <style>
        :root {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #17202a;
            background: #f5f7fa;
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f5f7fa; }
        .shell { min-height: 100vh; display: grid; grid-template-columns: minmax(220px, 280px) 1fr; }
        .sidebar { background: #111827; color: #fff; padding: 28px 22px; }
        .brand { font-size: 1.25rem; font-weight: 800; letter-spacing: .08em; margin-bottom: 28px; }
        .nav-title { margin: 0 0 10px; font-size: .75rem; text-transform: uppercase; opacity: .65; letter-spacing: .08em; }
        .nav-link { display: block; color: #dbeafe; text-decoration: none; border-radius: 12px; padding: 12px 14px; margin-bottom: 8px; }
        .nav-link:hover, .nav-link.active { background: #1f2937; color: #fff; }
        .main { padding: 30px; }
        .header { display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; }
        .eyebrow { color: #64748b; margin: 0 0 6px; font-size: .9rem; }
        h1 { margin: 0; font-size: clamp(1.7rem, 4vw, 2.4rem); }
        .user-card { text-align: end; }
        .user-card strong { display: block; }
        .roles { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; justify-content: flex-end; }
        .role { border: 1px solid #cbd5e1; background: #fff; border-radius: 999px; padding: 5px 9px; font-size: .75rem; }
        .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 24px; box-shadow: 0 12px 30px rgba(15,23,42,.06); }
        .channels { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top: 18px; }
        .channel { border: 1px solid #dbe3ee; border-radius: 16px; padding: 18px; text-decoration: none; color: inherit; background: #fbfdff; }
        .channel.active { border-color: #64748b; background: #f8fafc; }
        .channel h2 { margin: 0 0 8px; font-size: 1.05rem; }
        .channel p { margin: 0; color: #64748b; line-height: 1.6; }
        .boundary { margin-top: 24px; color: #64748b; font-size: .9rem; line-height: 1.6; }
        @media (max-width: 760px) {
            .shell { grid-template-columns: 1fr; }
            .sidebar { padding: 18px; }
            .main { padding: 20px; }
            .header { flex-direction: column; }
            .user-card { text-align: start; }
            .roles { justify-content: flex-start; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">FOODEX</div>
        <p class="nav-title">{{ __('admin.navigation') }}</p>

        <a class="nav-link {{ $activeChannel === null ? 'active' : '' }}" href="{{ route('admin.index') }}">
            {{ __('admin.overview') }}
        </a>

        @foreach ($navigation as $channel => $item)
            <a class="nav-link {{ $activeChannel === $channel ? 'active' : '' }}" href="{{ route($item['route']) }}">
                {{ __($item['label']) }}
            </a>
        @endforeach

        @can('security.view')
            <a class="nav-link" href="{{ route('admin.security.index') }}">{{ __('admin.security_center') }}</a>
        @endcan

        @can('translations.manage')
            <a class="nav-link" href="{{ route('admin.translations.index') }}">{{ __('admin.translation_center') }}</a>
        @endcan

        @can('notifications.manage')
            <a class="nav-link" href="{{ route('admin.notifications.index') }}">{{ __('notifications.title') }}</a>
        @endcan

        @can('platform.manage')
            <a class="nav-link" href="{{ route('admin.app-versions.index') }}">{{ __('admin.app_versions') }}</a>
        @endcan

        @can('system.update')
            <a class="nav-link" href="{{ route('admin.system-update.index') }}">{{ __('admin.system_update') }}</a>
        @endcan
    </aside>

    <main class="main">
        <header class="header">
            <div>
                <p class="eyebrow">{{ __('admin.management_system') }}</p>
                <h1>{{ $activeChannel ? __($navigation[$activeChannel]['label']) : __('admin.title') }}</h1>
            </div>

            <div class="user-card">
                <strong>{{ $user->name }}</strong>
                <span>{{ $user->email }}</span>
                <div class="roles">
                    @foreach ($roleCodes as $roleCode)
                        <span class="role">{{ $roleCode }}</span>
                    @endforeach
                </div>
            </div>
        </header>

        <section class="panel">
            <strong>{{ __('admin.shell_ready') }}</strong>
            <p>{{ __('admin.shell_description') }}</p>

            <div class="channels">
                @foreach ($navigation as $channel => $item)
                    <a class="channel {{ $activeChannel === $channel ? 'active' : '' }}" href="{{ route($item['route']) }}">
                        <h2>{{ __($item['label']) }}</h2>
                        <p>{{ __($item['description']) }}</p>
                    </a>
                @endforeach
            </div>

            <p class="boundary">{{ __('admin.authorization_boundary') }}</p>
        </section>
    </main>
</div>
</body>
</html>
