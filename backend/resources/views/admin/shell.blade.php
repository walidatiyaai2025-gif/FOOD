<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style id="foodex-admin-shell">
        body{margin:0;min-height:100vh}
        .shell{min-height:100vh;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width)}
        .shell-sidebar{grid-column:2;grid-row:1;min-height:100vh;padding:var(--foodex-space-5)}
        .main{grid-column:1;grid-row:1;min-width:0;padding:var(--foodex-space-8)}
        html[dir=ltr] .shell{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .shell-sidebar{grid-column:1}
        html[dir=ltr] .main{grid-column:2}
        .header{display:flex;gap:var(--foodex-space-4);align-items:flex-start;justify-content:space-between;margin-bottom:var(--foodex-space-6);padding:var(--foodex-space-5);background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
        .eyebrow{color:var(--foodex-muted);margin:0 0 var(--foodex-space-1);font-size:var(--foodex-text-sm);font-weight:var(--foodex-font-weight-medium)}
        h1{margin:0;font-size:clamp(1.55rem,2.2vw,var(--foodex-text-2xl));line-height:var(--foodex-leading-tight);font-weight:var(--foodex-font-weight-bold)}
        .user-card{text-align:end;min-width:180px}.user-card strong{display:block;font-weight:var(--foodex-font-weight-bold)}.user-card>span{color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
        .roles{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);margin-top:var(--foodex-space-2);justify-content:flex-end}
        .role{border:1px solid var(--foodex-border);background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-radius:999px;padding:5px 9px;font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-medium)}
        .panel{padding:var(--foodex-space-6)}
        .panel>strong{font-size:var(--foodex-text-lg)}
        .panel>p{color:var(--foodex-muted)}
        .channels{display:grid;gap:var(--foodex-space-4);grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-top:var(--foodex-space-5)}
        .channel{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-5);text-decoration:none;color:inherit;background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm);transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}
        .channel:hover{border-color:#CBE8D4;box-shadow:var(--foodex-shadow);transform:translateY(-1px)}
        .channel.active{border-color:var(--foodex-green);background:var(--foodex-green-soft)}
        .channel h2{margin:0 0 var(--foodex-space-2);font-size:var(--foodex-text-lg);font-weight:var(--foodex-font-weight-bold)}
        .channel p{margin:0;color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}
        .boundary{margin-top:var(--foodex-space-6);color:var(--foodex-muted);font-size:var(--foodex-text-sm);line-height:var(--foodex-leading-normal)}
        @media(max-width:1023px){
            .shell,.shell[dir]{grid-template-columns:minmax(0,1fr)}
            .shell-sidebar,.main,html[dir=ltr] .shell-sidebar,html[dir=ltr] .main{grid-column:1}
            .shell-sidebar{grid-row:1;min-height:auto;border-bottom:1px solid var(--foodex-border)}
            .main{grid-row:2;padding:var(--foodex-space-6)}
        }
        @media(max-width:767px){
            .shell-sidebar{padding:var(--foodex-space-4)}
            .main{padding:var(--foodex-space-4)}
            .header{flex-direction:column;padding:var(--foodex-space-4)}
            .user-card{text-align:start}
            .roles{justify-content:flex-start}
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar shell-sidebar">
        @include('admin._sidebar')
    </aside>

    <main class="main">
        <header class="header foodex-page-header">
            <div>
                <p class="eyebrow">{{ __('admin.management_system') }}</p>
                <h1>{{ $activeChannel ? __($navigation[$activeChannel]['label']) : __('admin.title') }}</h1>
            </div>

            <div class="user-card">
                <strong>{{ $user->name }}</strong>
                <span>{{ $user->email }}</span>
                <div class="roles">
                    @foreach ($roleCodes as $roleCode)
                        <span class="role foodex-number">{{ $roleCode }}</span>
                    @endforeach
                </div>
            </div>
        </header>

        <section class="panel foodex-card">
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
