<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style id="foodex-admin-shell">
        body{margin:0;min-height:100vh}
        .shell{direction:ltr;min-height:100vh;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width)}
        .shell-sidebar{grid-column:2;grid-row:1;direction:rtl;min-height:100vh;padding:var(--foodex-space-5)}
        .main{grid-column:1;grid-row:1;direction:rtl;min-width:0;padding:var(--foodex-space-8)}
        html[dir=ltr] .shell{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .shell-sidebar{grid-column:1;direction:ltr}
        html[dir=ltr] .main{grid-column:2;direction:ltr}
        .header{display:grid;grid-template-columns:minmax(120px,.8fr) minmax(220px,480px) minmax(210px,.9fr);gap:var(--foodex-header-gap);align-items:center;height:var(--foodex-header-height)!important;min-height:var(--foodex-header-height)!important;max-height:var(--foodex-header-height)!important;margin-bottom:8px!important;padding:2px 6px!important;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:10px;box-shadow:var(--foodex-shadow-sm);overflow:visible}.header-copy{min-width:0;max-height:28px;overflow:hidden}.header-search{min-width:0;width:100%;justify-self:center}.user-card{justify-self:end;display:flex;align-items:center;gap:5px;min-width:0;max-width:100%;overflow:hidden;white-space:nowrap}
        .eyebrow{display:none}
        h1{margin:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:var(--foodex-header-title-size)!important;line-height:16px!important;font-weight:var(--foodex-font-weight-bold)}
        .user-card{text-align:end}.user-card strong{display:block;max-width:92px;overflow:hidden;text-overflow:ellipsis;font-size:.6875rem;line-height:12px;font-weight:var(--foodex-font-weight-bold)}.user-card>span{max-width:110px;overflow:hidden;text-overflow:ellipsis;color:var(--foodex-muted);font-size:.5625rem;line-height:10px}
        .roles{display:flex;flex-wrap:nowrap;gap:3px;margin:0;justify-content:flex-end;max-width:96px;overflow:hidden}
        .role{min-height:16px;display:inline-flex;align-items:center;border:1px solid var(--foodex-border);background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-radius:999px;padding:0 4px;font-size:.5rem;line-height:1;font-weight:var(--foodex-font-weight-medium)}
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
            .header{grid-template-columns:minmax(110px,.75fr) minmax(180px,1fr) auto;height:var(--foodex-header-height-tablet)!important;min-height:var(--foodex-header-height-tablet)!important;max-height:var(--foodex-header-height-tablet)!important}
            .user-card>span,.roles{display:none}
        }
        @media(max-width:767px){
            .shell-sidebar{padding:var(--foodex-space-4)}
            .main{padding:var(--foodex-space-4)}
            .header{grid-template-columns:minmax(70px,.72fr) minmax(105px,1fr) auto;height:var(--foodex-header-height-mobile)!important;min-height:var(--foodex-header-height-mobile)!important;max-height:var(--foodex-header-height-mobile)!important;gap:4px;padding:0 4px!important}
            .header-search{grid-row:auto;width:100%}
            .user-card{grid-row:auto;justify-self:end;text-align:end}
            .user-card>strong,.user-card>span,.roles{display:none}
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
            <div class="header-copy">
                <p class="eyebrow">{{ __('admin.management_system') }}</p>
                <h1>{{ $activeChannel ? __($navigation[$activeChannel]['label']) : __('admin.title') }}</h1>
            </div>

            <div class="header-search" data-foodex-dashboard-header-search>
                @include('admin._global-search', ['globalSearchVariant' => 'header'])
            </div>

            <div class="user-card">
                @include('admin._live-notifications')
                <strong>{{ $user->name }}</strong>
                <span>{{ $user->email }}</span>
                <div class="roles">
                    @foreach ($roleCodes as $roleCode)
                        <span class="role foodex-number">{{ $roleCode }}</span>
                    @endforeach
                </div>
            </div>
        </header>

        @if($activeChannel === null)
            @include('admin._executive-overview')
        @else
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
        @endif
    </main>
</div>
</body>
</html>
