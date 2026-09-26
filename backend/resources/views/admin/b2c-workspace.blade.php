<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.b2c_workspace.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;overflow-x:hidden;background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit;text-decoration:none}

        /* Golden Dashboard geometry is intentionally physical: sidebar right in RTL, left in LTR. */
        .dashboard-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
        .dashboard-shell{grid-column:1;grid-row:1;min-width:0;direction:rtl}
        .dashboard-sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-left:1px solid var(--foodex-border);min-height:100vh;position:relative;z-index:12}
        html[dir=ltr] .dashboard-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .dashboard-sidebar{grid-column:1;direction:ltr;border-left:0;border-right:1px solid var(--foodex-border)}
        html[dir=ltr] .dashboard-shell{grid-column:2;direction:ltr}

        .topbar{direction:ltr;min-height:var(--foodex-header-height);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border);display:grid;grid-template-columns:minmax(210px,1fr) minmax(150px,.45fr) minmax(340px,540px);grid-template-areas:"profile actions search";align-items:center;gap:var(--foodex-space-4);padding:0 var(--foodex-space-6);position:sticky;top:0;z-index:10}
        html[dir=ltr] .topbar{grid-template-columns:minmax(340px,540px) minmax(150px,.45fr) minmax(210px,1fr);grid-template-areas:"search actions profile"}
        html[dir=rtl] .topbar>*{direction:rtl}
        .profile{grid-area:profile;display:flex;align-items:center;gap:var(--foodex-space-3);justify-self:start}
        .avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold);border:1px solid var(--foodex-border)}
        .profile strong{font-weight:var(--foodex-font-weight-bold);font-size:var(--foodex-text-sm)}
        .profile small{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs);margin-top:1px}
        .top-actions{grid-area:actions;display:flex;align-items:center;justify-content:flex-end;gap:var(--foodex-space-4);color:var(--foodex-muted);justify-self:end}
        .language,.bell{min-height:var(--foodex-touch-target);display:inline-flex;align-items:center;gap:7px}
        .bell{position:relative;font-size:19px}
        .bell b{position:absolute;width:8px;height:8px;border-radius:50%;background:var(--foodex-orange);inset:7px -1px auto auto}
        .global-search{grid-area:search;position:relative;width:100%}
        .global-search input{width:100%;min-height:var(--foodex-control-height);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding-inline:44px 14px;background:var(--foodex-surface);outline:none;box-shadow:var(--foodex-shadow-sm)}
        .global-search input:focus{border-color:var(--foodex-green);box-shadow:0 0 0 3px rgba(21,138,58,.10)}
        .search-icon{position:absolute;inset-inline-end:14px;top:11px;color:var(--foodex-muted);width:20px;height:20px}
        .search-results{position:absolute;top:50px;inset-inline:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-raised);padding:8px;z-index:30}
        .search-results a{display:flex;justify-content:space-between;gap:10px;padding:9px 10px;border-radius:var(--foodex-radius-sm)}
        .search-results a:hover{background:var(--foodex-green-soft)}
        .search-results small{color:var(--foodex-muted)}

        .content{max-width:1480px;margin:0 auto;padding:var(--foodex-space-5) var(--foodex-space-6) var(--foodex-space-8)}
        .headline{display:flex;justify-content:space-between;align-items:end;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}
        .headline h1{margin:0 0 4px;font-size:clamp(1.55rem,2.2vw,1.9rem);font-weight:var(--foodex-font-weight-bold);line-height:var(--foodex-leading-tight)}
        .headline p{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
        .date-control{display:flex;gap:8px;align-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:6px 10px;box-shadow:var(--foodex-shadow-sm)}
        .date-control input{border:0!important;outline:0!important;background:transparent!important;color:var(--foodex-ink);min-height:32px!important;box-shadow:none!important;padding:0!important;font-family:var(--foodex-font-en)}

        .kpis{direction:ltr;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-3)}
        .kpi{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-4);min-height:140px;box-shadow:var(--foodex-shadow-sm)}
        html[dir=rtl] .kpi{direction:rtl}
        .kpi-head{display:flex;align-items:center;gap:var(--foodex-space-3)}
        .kpi-icon{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;flex:0 0 auto}
        .kpi-icon .foodex-svg-icon{width:23px;height:23px}
        .green{background:var(--foodex-green-soft);color:var(--foodex-green)}
        .orange{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .blue{background:rgba(75,140,245,.12);color:var(--foodex-blue)}
        .kpi-label{font-weight:var(--foodex-font-weight-bold);font-size:var(--foodex-text-sm);line-height:1.2}
        .kpi-label small{display:block;font-family:var(--foodex-font-en);font-weight:var(--foodex-font-weight-regular);font-size:var(--foodex-text-xs);color:var(--foodex-muted);margin-top:3px}
        .kpi-value{font-family:var(--foodex-font-en);font-size:1.75rem;font-weight:var(--foodex-font-weight-bold);line-height:1.1;margin:13px 0 9px;white-space:nowrap}
        .delta{font-size:.72rem;font-weight:var(--foodex-font-weight-medium);color:var(--foodex-green);background:var(--foodex-green-soft);border-radius:999px;padding:4px 8px;display:inline-flex;align-items:center;gap:3px}
        .delta.down{color:var(--foodex-red);background:rgba(239,83,80,.10)}
        .delta.na{color:var(--foodex-muted);background:var(--foodex-background)}

        .middle{direction:ltr;display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:var(--foodex-space-3);margin-top:var(--foodex-space-3)}
        .panel{background:var(--foodex-surface)!important;border:1px solid var(--foodex-border)!important;border-radius:var(--foodex-radius-card)!important;padding:var(--foodex-space-4);box-shadow:var(--foodex-shadow-sm)!important}
        html[dir=rtl] .middle>.panel,html[dir=rtl] .bottom>.panel{direction:rtl}
        .panel-title{display:flex;justify-content:space-between;align-items:flex-start;gap:var(--foodex-space-3);margin-bottom:var(--foodex-space-3)}
        .panel-title h2{font-size:1rem;margin:0;font-weight:var(--foodex-font-weight-bold)}
        .panel-title small{display:block;color:var(--foodex-muted);font-family:var(--foodex-font-en);font-size:.72rem;font-weight:var(--foodex-font-weight-regular);margin-top:3px}
        .legend{display:flex;gap:var(--foodex-space-3);color:var(--foodex-muted);font-size:.72rem;white-space:nowrap}
        .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-inline-end:4px}
        .chart{height:218px;width:100%;overflow:hidden}
        .chart svg{width:100%;height:100%}
        .chart-grid{stroke:var(--foodex-border);stroke-width:1}
        .orders-bar{fill:var(--foodex-orange-bright)}
        .revenue-line{fill:none;stroke:var(--foodex-green);stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
        .revenue-dot{fill:var(--foodex-surface);stroke:var(--foodex-green);stroke-width:3}
        .axis-label{font-family:var(--foodex-font-en);font-size:10px;fill:var(--foodex-muted)}
        .donut-wrap{display:flex;align-items:center;justify-content:center;gap:var(--foodex-space-4);min-height:218px}
        .donut{width:154px;height:154px;border-radius:50%;position:relative;flex:0 0 auto}
        .donut:after{content:"";position:absolute;inset:30px;background:var(--foodex-surface);border-radius:50%}
        .donut-center{position:absolute;inset:0;z-index:2;display:grid;place-content:center;text-align:center;font-family:var(--foodex-font-en);font-weight:var(--foodex-font-weight-bold);font-size:1.4rem}
        .donut-center small{font-family:var(--foodex-font-ui);font-size:.68rem;color:var(--foodex-muted);font-weight:var(--foodex-font-weight-medium)}
        .status-list{display:grid;gap:10px;flex:1;min-width:125px}
        .status-row{display:flex;justify-content:space-between;gap:10px;font-size:.76rem}
        .status-row span:first-child{display:flex;align-items:center;gap:5px}

        .bottom{direction:ltr;display:grid;grid-template-columns:minmax(245px,.9fr) minmax(420px,1.5fr) minmax(230px,.82fr);gap:var(--foodex-space-3);margin-top:var(--foodex-space-3)}
        .section-link{color:var(--foodex-green);font-size:.72rem;font-weight:var(--foodex-font-weight-bold)}
        .stock-list,.recent-list{display:grid}
        .stock,.recent{border-bottom:1px solid var(--foodex-border);padding:8px 0}
        .stock:last-child,.recent:last-child{border-bottom:0}
        .stock{display:grid;grid-template-columns:38px 1fr auto;align-items:center;gap:9px}
        .product-thumb{width:34px;height:34px;border-radius:var(--foodex-radius-sm);background:var(--foodex-orange-soft);color:var(--foodex-orange);display:grid;place-items:center;overflow:hidden}.product-thumb img{width:100%;height:100%;object-fit:cover;display:block}
        .stock strong{display:block;font-size:.75rem}.stock small{color:var(--foodex-muted);font-size:.68rem}
        .stock-count{color:var(--foodex-red);background:rgba(239,83,80,.10);border-radius:var(--foodex-radius-sm);padding:4px 6px;font-size:.67rem;font-weight:var(--foodex-font-weight-bold);white-space:nowrap}
        .recent{display:grid;grid-template-columns:84px minmax(88px,1fr) 44px 88px 92px 74px;gap:6px;align-items:center;font-size:.7rem}
        .recent.header{color:var(--foodex-muted);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-background);border-radius:var(--foodex-radius-sm);padding:7px 8px}
        .recent>strong{font-family:var(--foodex-font-en);font-size:.66rem;overflow-wrap:anywhere}
        .badge{display:inline-flex;justify-content:center;border-radius:999px;padding:4px 7px;font-size:.63rem;font-weight:var(--foodex-font-weight-bold);line-height:1.15}
        .badge.delivered,.badge.completed{background:var(--foodex-green-soft);color:var(--foodex-green)}
        .badge.out_for_delivery,.badge.in_transit,.badge.assigned,.badge.picked_up{background:rgba(75,140,245,.12);color:var(--foodex-blue)}
        .badge.cancelled,.badge.refunded{background:rgba(239,83,80,.10);color:var(--foodex-red)}
        .badge.pending,.badge.processing,.badge.confirmed,.badge.paid,.badge.accepted{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
        .quick{min-height:82px;border-radius:var(--foodex-radius-md);padding:10px 8px;display:grid;place-content:center;text-align:center;font-size:.72rem;font-weight:var(--foodex-font-weight-bold);border:1px solid transparent}
        .quick:nth-child(4n+1){background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .quick:nth-child(4n+2){background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .quick:nth-child(4n+3){background:rgba(75,140,245,.12);color:var(--foodex-blue)}
        .quick:nth-child(4n){background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .quick i{font-style:normal;margin-bottom:5px;display:grid;place-items:center}
        .quick .foodex-svg-icon{width:25px;height:25px}

        .apps-card{margin:var(--foodex-space-3) var(--foodex-space-4);background:var(--foodex-orange-soft);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-3);text-align:center}
        .apps-card>strong{font-weight:var(--foodex-font-weight-bold)}
        .phone{width:58px;height:96px;border-radius:12px;background:var(--foodex-ink);margin:6px auto 9px;padding:6px;transform:rotate(-6deg);box-shadow:var(--foodex-shadow)}
        .phone-screen{height:100%;border-radius:8px;background:linear-gradient(160deg,var(--foodex-green),var(--foodex-green-soft));display:grid;place-items:center;color:#fff;font-family:var(--foodex-font-en);font-size:.62rem}
        .apps-card small{color:var(--foodex-muted);font-size:.7rem}
        .store-links{display:flex;justify-content:center;gap:8px;margin-top:9px}
        .store-links a{min-width:32px;min-height:32px;display:grid;place-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-sm);font-size:.75rem}

        /* PH-06.6: non-dashboard B2C routes inherit the same Premium system as the Golden dashboard. */
        .module-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
        .module-layout aside{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);padding:var(--foodex-space-5);position:sticky;inset-block-start:0;height:100vh}
        .module-layout main{grid-column:1;grid-row:1;direction:rtl;min-width:0;width:100%;max-width:none!important;padding:var(--foodex-space-8)}
        html[dir=ltr] .module-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .module-layout aside{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .module-layout main{grid-column:2;direction:ltr}
        .module-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}
        .module-card{position:relative;overflow:hidden;min-height:116px;padding:var(--foodex-space-5)!important}
        .module-card:before{content:"";position:absolute;inset-inline-start:0;inset-block:0;width:4px;background:var(--foodex-green)}
        .module-card:nth-child(2n):before{background:var(--foodex-orange)}
        .module-card strong{font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-medium);color:var(--foodex-muted)}
        .module-card p{font-family:var(--foodex-font-en);font-size:1.75rem;font-weight:var(--foodex-font-weight-bold);line-height:1.1;margin:var(--foodex-space-3) 0 0}
        .module-panel{margin-top:var(--foodex-space-4);padding:var(--foodex-space-5)!important;box-shadow:var(--foodex-shadow)!important}
        .empty{color:var(--foodex-muted)}
        .module-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}
        .module-toolbar>div:first-child{max-width:520px}.module-toolbar p{margin:var(--foodex-space-1) 0 0}
        .module-table-wrap{overflow:auto;border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-sm)}
        .module-table{min-width:760px}
        .module-table th,.module-table td{vertical-align:middle}
        .state-dot{display:inline-flex;align-items:center;gap:6px;font-weight:var(--foodex-font-weight-medium)}.state-dot:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green)}.state-dot.off:before{background:var(--foodex-muted)}
        .module-links{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center;justify-content:flex-end}.module-links a{min-height:var(--foodex-control-height);display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--foodex-border);background:var(--foodex-surface);border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-3);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}.module-links a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.module-links a.active{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff;box-shadow:0 8px 20px rgba(21,138,58,.14)}
        .module-actions{justify-content:flex-start;margin-bottom:var(--foodex-space-4)}
        .module-actions a{background:var(--foodex-green)!important;color:#fff!important;border-color:var(--foodex-green)!important}
        .module-empty-state{display:grid;place-items:center;min-height:160px;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;padding:var(--foodex-space-6);color:var(--foodex-muted)}

        @media(max-width:1279px){
            .topbar{grid-template-columns:minmax(180px,.8fr) minmax(140px,.4fr) minmax(300px,1.4fr);padding-inline:var(--foodex-space-4)}
            html[dir=ltr] .topbar{grid-template-columns:minmax(300px,1.4fr) minmax(140px,.4fr) minmax(180px,.8fr)}
            .content{padding-inline:var(--foodex-space-4)}
            .kpis{grid-template-columns:1fr 1fr}
            .middle{grid-template-columns:1fr}
            .bottom{grid-template-columns:1fr 1fr}
            .bottom .panel:nth-child(2){grid-column:1/-1;grid-row:1}
            .recent{grid-template-columns:82px minmax(90px,1fr) 44px 88px 92px 72px}
        }
        @media(max-width:860px){
            .dashboard-layout,html[dir=ltr] .dashboard-layout{grid-template-columns:1fr}
            .dashboard-sidebar,html[dir=ltr] .dashboard-sidebar{display:block;grid-column:1;grid-row:1;position:relative;min-height:auto;height:auto;max-height:320px;overflow:auto;border-inline:0;border-bottom:1px solid var(--foodex-border)}
            .dashboard-shell,html[dir=ltr] .dashboard-shell{grid-column:1!important;grid-row:2}
            .topbar,html[dir=ltr] .topbar{grid-template-columns:1fr auto;grid-template-areas:"profile actions" "search search";height:auto;min-height:68px;padding:10px var(--foodex-space-4)}
            .global-search{grid-column:auto;grid-row:auto}
            .profile,.top-actions{grid-row:auto}
            .bottom{grid-template-columns:1fr}
            .bottom .panel:nth-child(2){grid-column:auto;grid-row:auto}
            .module-layout,html[dir=ltr] .module-layout{grid-template-columns:1fr}.module-layout aside,html[dir=ltr] .module-layout aside{display:block;grid-column:1;grid-row:1;position:relative;height:auto;max-height:320px;overflow:auto;border-inline:0;border-bottom:1px solid var(--foodex-border)}.module-layout main,html[dir=ltr] .module-layout main{grid-column:1;grid-row:2;padding:var(--foodex-space-6)}
        }
        @media(max-width:620px){
            .content{padding:var(--foodex-space-4)}
            .kpis{grid-template-columns:1fr}
            .headline{align-items:flex-start;flex-direction:column}
            .date-control{order:2}
            .donut-wrap{flex-direction:column}
            .recent.header{display:none}
            .recent{grid-template-columns:1fr auto;gap:4px}
            .recent>*:nth-child(3),.recent>*:nth-child(4){display:none}
            .quick-grid{grid-template-columns:1fr 1fr}
        }
    </style>
</head>
<body>
@if($module === 'dashboard' && $dashboard)
<div class="dashboard-layout" data-golden-dashboard="ph06" data-dashboard-geometry="physical-ltr">
    <section class="dashboard-shell">
        <header class="topbar">
            <div class="profile">
                <div class="avatar">{{ mb_substr($user->name, 0, 1) }}</div>
                <div><strong>{{ $user->name }}</strong><small>{{ __('admin.b2c_dashboard.system_manager') }}</small></div>
            </div>
            <form class="global-search" method="get" action="{{ route('admin.b2c.dashboard') }}">
                <input type="hidden" name="date" value="{{ $dashboard['selected_date'] }}">
                <input name="q" value="{{ request('q') }}" placeholder="{{ __('admin.b2c_dashboard.search_placeholder') }}" autocomplete="off">
                <span class="search-icon">@include('admin._premium-icon',['name'=>'search'])</span>
                @if(request('q') && count($dashboard['search']))
                <div class="search-results">
                    @foreach($dashboard['search'] as $result)
                    <a href="{{ $result['route'] }}"><span><strong>{{ $result['title'] }}</strong><small> · {{ $result['subtitle'] }}</small></span><small>{{ __('admin.b2c_dashboard.search_types.'.$result['type']) }}</small></a>
                    @endforeach
                </div>
                @endif
            </form>
            <div class="top-actions">
                <span class="language">@include('admin._premium-icon',['name'=>'globe']) {{ app()->getLocale()==='ar' ? 'العربية' : 'English' }}</span>
                <span class="bell">@include('admin._premium-icon',['name'=>'bell']) @if($dashboard['notifications_unread']>0)<b></b>@endif</span>
            </div>
        </header>

        <main class="content">
            <div class="headline">
                <div><h1>{{ __('admin.b2c_dashboard.hello', ['name'=>$user->name]) }} 👋</h1><p>{{ __('admin.b2c_dashboard.subtitle') }}</p></div>
                <form class="date-control" method="get" action="{{ route('admin.b2c.dashboard') }}"><span>⌄</span><input type="date" name="date" value="{{ $dashboard['selected_date'] }}" onchange="this.form.submit()"></form>
            </div>

            @php
                $kpis = [
                    ['key'=>'active_users','icon'=>'active-users','class'=>'green','label'=>'active_users'],
                    ['key'=>'orders','icon'=>'orders','class'=>'orange','label'=>'orders'],
                    ['key'=>'revenue','icon'=>'revenue','class'=>'green','label'=>'revenue'],
                    ['key'=>'products_sold','icon'=>'products','class'=>'orange','label'=>'products_sold'],
                ];
            @endphp
            <section class="kpis">
                @foreach($kpis as $item)
                    @php $metric=$dashboard['kpis'][$item['key']]; $delta=$metric['delta']; @endphp
                    <article class="kpi">
                        <div class="kpi-head"><span class="kpi-icon {{ $item['class'] }}">@include('admin._premium-icon',['name'=>$item['icon']])</span><span class="kpi-label">{{ __('admin.b2c_dashboard.kpis.'.$item['label']) }}<small lang="en">{{ __('admin.b2c_dashboard.kpis.'.$item['label'].'_en') }}</small></span></div>
                        <div class="kpi-value foodex-number">
                            @if($item['key']==='revenue') {{ $dashboard['currency'] }} {{ number_format($metric['value'],3) }}
                            @else {{ number_format($metric['value'], $item['key']==='products_sold' ? 0 : 0) }} @endif
                        </div>
                        @if($item['key']==='active_users')
                            <span class="delta na">{{ __('admin.b2c_dashboard.active_window', ['minutes'=>$metric['window_minutes']]) }}</span>
                        @elseif($delta===null)
                            <span class="delta na">{{ __('admin.b2c_dashboard.new_activity') }}</span>
                        @else
                            <span class="delta {{ $delta<0?'down':'' }}">{{ $delta>=0?'↑':'↓' }} {{ abs($delta) }}% · {{ __('admin.b2c_dashboard.vs_previous') }}</span>
                        @endif
                    </article>
                @endforeach
            </section>

            <section class="middle">
                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.orders_revenue') }}</h2><small lang="en">Orders & Revenue</small></div><div class="legend"><span><i class="dot" style="background:var(--foodex-orange)"></i>{{ __('admin.b2c_dashboard.orders') }}</span><span><i class="dot" style="background:var(--foodex-green)"></i>{{ __('admin.b2c_dashboard.revenue') }}</span></div></div>
                    @php
                        $maxOrders=max(1,max(array_column($dashboard['series'],'orders')));
                        $maxRevenue=max(1,max(array_column($dashboard['series'],'revenue')));
                        $points=[];
                        foreach($dashboard['series'] as $i=>$row){$x=50+$i*88;$y=192-($row['revenue']/$maxRevenue*130);$points[]="$x,$y";}
                    @endphp
                    <div class="chart"><svg viewBox="0 0 650 220" preserveAspectRatio="none">
                        @for($y=40;$y<=190;$y+=38)<line class="chart-grid" x1="34" y1="{{ $y }}" x2="630" y2="{{ $y }}"/>@endfor
                        @foreach($dashboard['series'] as $i=>$row)
                            @php $x=36+$i*88; $height=max(4,($row['orders']/$maxOrders*110)); @endphp
                            <rect class="orders-bar" x="{{ $x }}" y="{{ 192-$height }}" width="29" height="{{ $height }}" rx="5"/>
                            <text class="axis-label" x="{{ $x+14 }}" y="211" text-anchor="middle">{{ $row['label'] }}</text>
                        @endforeach
                        <polyline class="revenue-line" points="{{ implode(' ',$points) }}"/>
                        @foreach($points as $point) @php [$cx,$cy]=explode(',',$point); @endphp <circle class="revenue-dot" cx="{{ $cx }}" cy="{{ $cy }}" r="4"/> @endforeach
                    </svg></div>
                </article>

                @php
                    $dist=$dashboard['distribution'];$total=array_sum($dist);$p1=$total?($dist['processing']/$total*100):0;$p2=$total?($dist['out_for_delivery']/$total*100):0;$p3=$total?($dist['delivered']/$total*100):0;
                    $gradient="conic-gradient(var(--foodex-blue) 0 {$p1}%, var(--foodex-orange) {$p1}% ".($p1+$p2)."%, var(--foodex-green) ".($p1+$p2)."% ".($p1+$p2+$p3)."%, var(--foodex-red) ".($p1+$p2+$p3)."% 100%)";
                @endphp
                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.distribution') }}</h2><small lang="en">Orders Distribution</small></div></div>
                    <div class="donut-wrap">
                        <div class="donut" style="background:{{ $gradient }}"><div class="donut-center">{{ $total }}<small>{{ __('admin.b2c_dashboard.total_orders') }}</small></div></div>
                        <div class="status-list">
                            @foreach(['processing'=>'var(--foodex-blue)','out_for_delivery'=>'var(--foodex-orange)','delivered'=>'var(--foodex-green)','cancelled'=>'var(--foodex-red)'] as $status=>$color)
                                <div class="status-row"><span><i class="dot" style="background:{{ $color }}"></i>{{ __('admin.b2c_dashboard.status.'.$status) }}</span><strong>{{ $total?round($dist[$status]/$total*100):0 }}%</strong></div>
                            @endforeach
                        </div>
                    </div>
                </article>
            </section>

            <section class="bottom">
                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.low_stock') }}</h2><small lang="en">Low Stock Products</small></div><a class="section-link" href="{{ route('admin.b2c.module',['module'=>'inventory']) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="stock-list">
                        @forelse($dashboard['low_stock'] as $product)
                            <div class="stock"><span class="product-thumb">@if($product['image'])<img src="{{ asset(ltrim($product['image'],'/')) }}" alt="">@else ▧ @endif</span><span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></span><span class="stock-count">{{ number_format($product['available'],0) }} {{ __('admin.b2c_dashboard.remaining') }}</span></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_low_stock') }}</div> @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.recent_orders') }}</h2><small lang="en">Recent Orders</small></div><a class="section-link" href="{{ route('admin.b2c.module',['module'=>'orders']) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="recent-list">
                        <div class="recent header"><span>#</span><span>{{ __('admin.b2c_dashboard.customer') }}</span><span>{{ __('admin.b2c_dashboard.items') }}</span><span>{{ __('admin.b2c_dashboard.amount') }}</span><span>{{ __('admin.b2c_dashboard.status_label') }}</span><span>{{ __('admin.b2c_dashboard.time') }}</span></div>
                        @forelse($dashboard['recent_orders'] as $order)
                            <div class="recent"><strong>{{ $order['number'] }}</strong><span>{{ $order['customer'] }}</span><span>{{ $order['items'] }}</span><span>{{ $order['currency'] }} {{ number_format($order['amount'],3) }}</span><span><i class="badge {{ $order['status'] }}">{{ __('admin.b2c_dashboard.status.'.$order['status']) }}</i></span><small>{{ \Carbon\Carbon::parse($order['created_at'])->locale(app()->getLocale())->diffForHumans() }}</small></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_orders') }}</div> @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.quick_actions') }}</h2><small lang="en">Quick Actions</small></div></div>
                    <div class="quick-grid">
                        @foreach($dashboard['quick_actions'] as $action)
                            @php $icons=['add_product'=>'products','manage_orders'=>'orders','send_notification'=>'bell','view_reports'=>'reports']; @endphp
                            <a class="quick" href="{{ route($action['route'],$action['params']) }}"><i>@include('admin._premium-icon',['name'=>$icons[$action['key']]??'more'])</i>{{ __('admin.b2c_dashboard.actions.'.$action['key']) }}</a>
                        @endforeach
                    </div>
                </article>
            </section>
        </main>
    </section>

    <aside class="dashboard-sidebar">
        @include('admin._premium-sidebar',['channel'=>'b2c'])
        @if(count($dashboard['mobile_apps']))
        <div class="apps-card">
            <strong>{{ __('admin.b2c_dashboard.foodex_apps') }}</strong>
            <div class="phone"><div class="phone-screen">FOODEX</div></div>
            <small>{{ __('admin.b2c_dashboard.mobile_cta') }}</small>
            <div class="store-links">
                @foreach($dashboard['mobile_apps'] as $app)
                    @if($app['app']==='customer')
                        @if($app['app_store_url'])<a href="{{ $app['app_store_url'] }}" rel="noopener"></a>@endif
                        @if($app['google_play_url'])<a href="{{ $app['google_play_url'] }}" rel="noopener">▶</a>@endif
                    @endif
                @endforeach
            </div>
        </div>
        @endif
    </aside>
</div>
@else
<div class="module-layout" data-b2c-premium="v1">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-page">
        <div class="foodex-page-header">
            <div>
                <div class="empty"><a href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a> / {{ __('admin.b2c_workspace.modules.'.$module) }}</div>
                <h1>{{ __('admin.b2c_workspace.modules.'.$module) }}</h1>
                <p>{{ __('admin.b2c_workspace.assigned_scope') }}: {{ implode(', ', $storeIds) }}</p>
            </div>
        </div>
        <div class="module-cards">@foreach($counts as $key=>$value)<div class="module-card foodex-card"><strong>{{ __('admin.b2c_workspace.modules.'.$key) }}</strong><p>{{ $value }}</p></div>@endforeach</div>
        @if($moduleData)
        @php
            $labels = app()->getLocale()==='ar'
                ? [
                    'sku'=>'SKU','name'=>'الاسم','category'=>'التصنيف','store'=>'المتجر','price'=>'السعر','status'=>'الحالة',
                    'warehouse'=>'المخزن','quantity'=>'الكمية','reserved'=>'المحجوز','available'=>'المتاح',
                    'number'=>'رقم الطلب','customer'=>'العميل','amount'=>'الإجمالي','created'=>'تاريخ الإنشاء',
                    'phone'=>'الهاتف','email'=>'البريد','orders'=>'الطلبات','spent'=>'إجمالي الإنفاق','last_order'=>'آخر طلب','type'=>'النوع','value'=>'القيمة','period'=>'الفترة','driver_type'=>'نوع السائق','order'=>'الطلب','assignment_status'=>'حالة التوصيل','availability'=>'التوفر','products'=>'المنتجات','banners'=>'البانرات','title'=>'العنوان','image'=>'الصورة','target'=>'الرابط','sort_order'=>'الترتيب','average'=>'متوسط الطلب','setting'=>'الإعداد','actions'=>'إجراءات',
                ]
                : [
                    'sku'=>'SKU','name'=>'Name','category'=>'Category','store'=>'Store','price'=>'Price','status'=>'Status',
                    'warehouse'=>'Warehouse','quantity'=>'Quantity','reserved'=>'Reserved','available'=>'Available',
                    'number'=>'Order','customer'=>'Customer','amount'=>'Amount','created'=>'Created',
                    'phone'=>'Phone','email'=>'Email','orders'=>'Orders','spent'=>'Total spent','last_order'=>'Last order','type'=>'Type','value'=>'Value','period'=>'Period','driver_type'=>'Driver type','order'=>'Order','assignment_status'=>'Delivery status','availability'=>'Availability','products'=>'Products','banners'=>'Banners','title'=>'Title','image'=>'Image','target'=>'Target','sort_order'=>'Sort order','average'=>'Average order','setting'=>'Setting','actions'=>'Actions',
                ];
        @endphp
        <section class="module-panel foodex-card">
            <div class="module-toolbar">
                <div>
                    <strong>{{ __('admin.b2c_workspace.authoritative') }}</strong>
                    <p class="empty">{{ app()->getLocale()==='ar' ? 'بيانات مباشرة ضمن المتاجر المصرح بها لهذا المستخدم.' : 'Live server data restricted to this user\'s assigned stores.' }}</p>
                </div>
                <nav class="module-links" aria-label="B2C core modules">
                    @foreach(['products','inventory','orders','customers','promotions','drivers','storefront','content','reports','settings'] as $core)
                        <a class="{{ $module===$core?'active':'' }}" href="{{ route('admin.b2c.module',['module'=>$core]) }}">{{ __('admin.b2c_workspace.modules.'.$core) }}</a>
                    @endforeach
                </nav>
            </div>
            @if(!empty($moduleData['actions']))
                <div class="module-links module-actions">
                    @foreach($moduleData['actions'] as $action)
                        <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif
            @if(count($moduleData['rows']))
                <div class="module-table-wrap">
                    <table class="module-table foodex-table">
                        <thead><tr>@foreach($moduleData['columns'] as $column)<th>{{ $labels[$column] ?? $column }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach($moduleData['rows'] as $row)
                            <tr>
                            @foreach($moduleData['columns'] as $column)
                                <td>
                                    @if($column==='actions')
                                        <div class="module-links">
                                            @foreach($row[$column] as $action)<a href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach
                                        </div>
                                    @elseif(in_array($column,['status','availability'],true) && is_bool($row[$column]))
                                        <span class="state-dot {{ $row[$column]?'':'off' }}">{{ $row[$column] ? (app()->getLocale()==='ar'?'نشط':'Active') : (app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
                                    @elseif($column==='status')
                                        <span class="badge {{ $row[$column] }}">{{ $row[$column] }}</span>
                                    @else
                                        {{ $row[$column] }}
                                    @endif
                                </td>
                            @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="module-empty-state" role="status">{{ app()->getLocale()==='ar' ? 'لا توجد بيانات في هذا القسم للمتاجر المصرح بها.' : 'No records are available in this section for the assigned stores.' }}</div>
            @endif
        </section>
        @else
        <section class="module-panel foodex-card"><strong>{{ __('admin.b2c_workspace.authoritative') }}</strong><p class="empty">{{ __('admin.b2c_workspace.empty_hint') }}</p></section>
        @endif
    </main>
</div>
@endif
</body>
</html>
