<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.b2c_workspace.title') }} · FOODEX</title>
    @include('admin._brand-components')
    @if($module === 'dashboard' && $canViewDriverTracking)
    <link rel="stylesheet" href="{{ asset('assets/leaflet/1.9.4/leaflet.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/admin/driver-live-map.css') }}">
    @endif
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

        .topbar{direction:ltr;min-height:var(--foodex-header-height);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border);display:grid;grid-template-columns:minmax(150px,.45fr) minmax(210px,1fr) minmax(340px,540px);grid-template-areas:"actions profile search";align-items:center;gap:var(--foodex-space-4);padding:0 var(--foodex-space-6);position:sticky;top:0;z-index:10}
        html[dir=ltr] .topbar{grid-template-columns:minmax(340px,540px) minmax(210px,1fr) minmax(150px,.45fr);grid-template-areas:"search profile actions"}
        html[dir=rtl] .topbar>*{direction:rtl}
        .profile{grid-area:profile;display:flex;align-items:center;gap:var(--foodex-space-3);justify-self:start}
        .avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold);border:1px solid var(--foodex-border)}
        .profile strong{font-weight:var(--foodex-font-weight-bold);font-size:var(--foodex-text-sm)}
        .profile small{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs);margin-top:1px}
        .top-actions{grid-area:actions;display:flex;align-items:center;justify-content:flex-start;gap:var(--foodex-space-4);color:var(--foodex-muted);justify-self:start}html[dir=ltr] .top-actions{justify-content:flex-end;justify-self:end}
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

        .content{width:100%;max-width:none;margin:0;padding:var(--foodex-space-5) clamp(var(--foodex-space-4),1.8vw,var(--foodex-space-8)) var(--foodex-space-8)}
        .headline{display:flex;justify-content:space-between;align-items:end;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}
        .headline h1{margin:0 0 4px;font-size:clamp(1.55rem,2.2vw,1.9rem);font-weight:var(--foodex-font-weight-bold);line-height:var(--foodex-leading-tight)}
        .headline p{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
        .date-control{display:flex;gap:8px;align-items:end;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:6px 10px;box-shadow:var(--foodex-shadow-sm);flex-wrap:wrap}
        .date-control label{display:grid;gap:2px;color:var(--foodex-muted);font-size:.68rem;font-weight:var(--foodex-font-weight-bold)}
        .date-control input{border:0!important;outline:0!important;background:transparent!important;color:var(--foodex-ink);min-height:32px!important;box-shadow:none!important;padding:0!important;font-family:var(--foodex-font-en)}
        .date-control .foodex-filter-action{min-height:36px}

        .kpis{direction:ltr;display:grid;grid-template-columns:repeat(var(--foodex-card-columns,4),minmax(0,1fr));gap:var(--foodex-space-3)}
        .kpi{min-width:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-4);min-height:140px;box-shadow:var(--foodex-shadow-sm)}
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

        .middle{direction:ltr;display:grid;grid-template-columns:repeat(var(--dashboard-primary-columns,2),minmax(0,1fr));gap:var(--foodex-space-3);margin-top:var(--foodex-space-3)}
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
        
        
        .quick:nth-child(4n+1){background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .quick:nth-child(4n+2){background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .quick:nth-child(4n+3){background:rgba(75,140,245,.12);color:var(--foodex-blue)}
        .quick:nth-child(4n){background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        
        

        .apps-card{margin:var(--foodex-space-3) var(--foodex-space-4);background:var(--foodex-orange-soft);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-3);text-align:center}
        .apps-card>strong{font-weight:var(--foodex-font-weight-bold)}
        .phone{width:58px;height:96px;border-radius:12px;background:var(--foodex-ink);margin:6px auto 9px;padding:6px;transform:rotate(-6deg);box-shadow:var(--foodex-shadow)}
        .phone-screen{height:100%;border-radius:8px;background:linear-gradient(160deg,var(--foodex-green),var(--foodex-green-soft));display:grid;place-items:center;color:#fff;font-family:var(--foodex-font-en);font-size:.62rem}
        .apps-card small{color:var(--foodex-muted);font-size:.7rem}
        .store-links{display:flex;justify-content:center;gap:8px;margin-top:9px}
        .store-links a{min-width:32px;min-height:32px;display:grid;place-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-sm);font-size:.75rem}

        /* PH-06.6: non-dashboard Retail routes inherit the same Premium system as the Golden dashboard. */
        .module-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
        .module-layout aside{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);padding:var(--foodex-space-5);position:sticky;inset-block-start:0;height:100vh}
        .module-layout main{grid-column:1;grid-row:1;direction:rtl;min-width:0;width:100%;max-width:none!important;padding:var(--foodex-space-8)}
        html[dir=ltr] .module-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .module-layout aside{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .module-layout main{grid-column:2;direction:ltr}
        .module-cards{display:grid;grid-template-columns:repeat(var(--foodex-card-columns,4),minmax(0,1fr));gap:12px;margin-bottom:var(--foodex-space-5)}
        .module-card{position:relative;overflow:hidden;min-height:112px;padding:14px!important;display:grid;grid-template-columns:minmax(0,1fr) 40px;gap:10px;align-items:center;background:linear-gradient(145deg,#fff,#fbfcfd)!important;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
        .module-card:hover{transform:translateY(-2px);box-shadow:var(--foodex-shadow)!important;border-color:#cfe5d6!important}
        .module-card:before{content:"";position:absolute;inset-inline-start:0;inset-block:0;width:4px;background:var(--foodex-green)}
        .module-card:nth-child(2n):before{background:var(--foodex-orange)}
        .module-card-copy{min-width:0}.module-card strong{display:block;font-size:11px;font-weight:var(--foodex-font-weight-bold);line-height:1.3;min-height:29px;color:var(--foodex-muted)}
        .module-card p{font-family:var(--foodex-font-en);font-size:clamp(1.25rem,1.55vw,1.7rem);font-weight:var(--foodex-font-weight-bold);line-height:1.1;margin:8px 0 0;white-space:nowrap}
        .module-card-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .module-card:nth-child(2n) .module-card-icon{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .module-card-icon .foodex-svg-icon{width:21px;height:21px}
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
        .module-inline-form{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center;margin-bottom:var(--foodex-space-4);padding:var(--foodex-space-3);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}
        .module-inline-form select,.module-inline-form input{min-width:150px;flex:1 1 160px}
        .module-inline-form button{flex:0 0 auto}
        .module-empty-state{display:grid;place-items:center;min-height:160px;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;padding:var(--foodex-space-6);color:var(--foodex-muted)}
        .flash{margin-bottom:var(--foodex-space-4);padding:10px 12px;border-radius:var(--foodex-radius-control);border:1px solid var(--foodex-border);font-weight:var(--foodex-font-weight-medium)}
        .flash.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .flash.err{background:#fff1f0;color:var(--foodex-red)}

        @media(min-width:1600px){
            .content{padding-inline:40px}
            .topbar{padding-inline:40px;grid-template-columns:minmax(170px,.45fr) minmax(240px,1fr) minmax(420px,620px)}
            html[dir=ltr] .topbar{grid-template-columns:minmax(420px,620px) minmax(240px,1fr) minmax(170px,.45fr)}
            .middle{grid-template-columns:minmax(0,2.25fr) minmax(340px,.9fr)}
            .bottom{grid-template-columns:minmax(290px,.9fr) minmax(560px,1.7fr) minmax(280px,.85fr)}
            .chart{height:250px}
        }
        @media(min-width:1900px){
            .content{padding-inline:48px}
            .chart{height:280px}
            .kpi{min-height:150px}
        }
        @media(min-width:1280px) and (max-width:1439px){
            .kpi-value{font-size:clamp(1.3rem,1.75vw,1.5rem)}
        }
        @media(max-width:1279px){
            .topbar{grid-template-columns:minmax(140px,.4fr) minmax(180px,.8fr) minmax(300px,1.4fr);padding-inline:var(--foodex-space-4)}
            html[dir=ltr] .topbar{grid-template-columns:minmax(300px,1.4fr) minmax(180px,.8fr) minmax(140px,.4fr)}
            .content{padding-inline:var(--foodex-space-4)}
            .kpis{grid-template-columns:1fr 1fr}
            .middle{grid-template-columns:1fr}
            .bottom{grid-template-columns:1fr 1fr}
            .bottom .panel:nth-child(2){grid-column:1/-1;grid-row:1}
            .recent{grid-template-columns:82px minmax(90px,1fr) 44px 88px 92px 72px}
        }
        @media(max-width:1279px){.module-cards{grid-template-columns:repeat(4,minmax(0,1fr))}}
        @media(max-width:900px){.module-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:1023px){
            .dashboard-layout,html[dir=ltr] .dashboard-layout{grid-template-columns:1fr}
            .dashboard-sidebar,html[dir=ltr] .dashboard-sidebar{display:block;grid-column:1;grid-row:1;position:relative;min-height:auto;height:auto;max-height:320px;overflow:auto;border-inline:0;border-bottom:1px solid var(--foodex-border)}
            .dashboard-shell,html[dir=ltr] .dashboard-shell{grid-column:1!important;grid-row:2}
            .topbar,html[dir=ltr] .topbar{grid-template-columns:auto 1fr;grid-template-areas:"actions profile" "search search";height:auto;min-height:68px;padding:10px var(--foodex-space-4)}html[dir=ltr] .topbar{grid-template-columns:1fr auto;grid-template-areas:"profile actions" "search search"}
            .global-search{grid-column:auto;grid-row:auto}
            .profile,.top-actions{grid-row:auto}
            .bottom{grid-template-columns:1fr}
            .bottom .panel:nth-child(2){grid-column:auto;grid-row:auto}
            .module-layout,html[dir=ltr] .module-layout{grid-template-columns:1fr}.module-layout aside,html[dir=ltr] .module-layout aside{display:block;grid-column:1;grid-row:1;position:relative;height:auto;max-height:320px;overflow:auto;border-inline:0;border-bottom:1px solid var(--foodex-border)}.module-layout main,html[dir=ltr] .module-layout main{grid-column:1;grid-row:2;padding:var(--foodex-space-6)}
        }
        @media(max-width:620px){
            .module-cards{grid-template-columns:1fr}
            .content{padding:var(--foodex-space-4)}
            .kpis{grid-template-columns:1fr}
            .headline{align-items:flex-start;flex-direction:column}
            .date-control{order:2}
            .donut-wrap{flex-direction:column}
            .recent.header{display:none}
            .recent{grid-template-columns:1fr auto;gap:4px}
            .recent>*:nth-child(3),.recent>*:nth-child(4){display:none}
            
        }
    </style>
</head>
<body>
@if($module === 'dashboard' && $dashboard)
<div class="dashboard-layout" data-golden-dashboard="ph06" data-dashboard-geometry="physical-ltr">
    <section class="dashboard-shell">
        <header class="topbar">
            <a class="profile" href="{{ route('admin.profile.index') }}" style="text-decoration:none;color:inherit">
                <div class="avatar">{{ mb_substr($user->name, 0, 1) }}</div>
                <div><strong>{{ $user->name }}</strong><small>{{ __('admin.b2c_dashboard.system_manager') }}</small></div>
            </a>
            <form class="global-search" method="get" action="{{ route('admin.b2c.dashboard') }}">
                <input type="hidden" name="from" value="{{ $dashboard['selected_from'] }}">
                <input type="hidden" name="to" value="{{ $dashboard['selected_to'] }}">
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <input name="q" value="{{ request('q') }}" placeholder="{{ __('admin.b2c_dashboard.search_placeholder') }}" autocomplete="off">
                <span class="search-icon">@include('admin._premium-icon',['name'=>'search'])</span>
                @if(request('q') && count($dashboard['search']))
                <div class="search-results">
                    @foreach($dashboard['search'] as $result)
                    @php
                        $scopedSearchUrl = $result['route']
                            .(str_contains($result['route'], '?') ? '&' : '?')
                            .'store_id='.$storeId
                            .($supportAccess ? '&support_access=1' : '');
                    @endphp
                    <a href="{{ $scopedSearchUrl }}"><span><strong>{{ $result['title'] }}</strong><small> · {{ $result['subtitle'] }}</small></span><small>{{ __('admin.b2c_dashboard.search_types.'.$result['type']) }}</small></a>
                    @endforeach
                </div>
                @endif
            </form>
            <div class="top-actions">
                @include('admin._live-notifications')
            </div>
        </header>

        <main class="content">
            @if(session('status'))<div class="flash ok" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif
            <div class="headline">
                <div>
                    <h1>{{ __('admin.b2c_dashboard.hello', ['name'=>$user->name]) }} 👋</h1>
                    <p>{{ __('admin.b2c_dashboard.subtitle') }}</p>
                    @if(count($availableStores) > 1)
                    <form method="get" action="{{ route('admin.b2c.dashboard') }}" class="date-control" style="margin-top:10px">
                        <select name="store_id" onchange="this.form.submit()">
                            @foreach($availableStores as $availableStore)
                                <option value="{{ $availableStore->id }}" @selected((int)$availableStore->id===$storeId)>{{ $availableStore->name }} — {{ $availableStore->code }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="from" value="{{ $dashboard['selected_from'] }}">
                        <input type="hidden" name="to" value="{{ $dashboard['selected_to'] }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    </form>
                    @endif
                </div>
                <form class="date-control" method="get" action="{{ route('admin.b2c.dashboard') }}">
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>{{ app()->getLocale()==='ar'?'من':'From' }}<input type="date" name="from" value="{{ $dashboard['selected_from'] }}" required></label>
                    <label>{{ app()->getLocale()==='ar'?'إلى':'To' }}<input type="date" name="to" value="{{ $dashboard['selected_to'] }}" required></label>
                    <button class="foodex-filter-action" type="submit">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button>
                </form>
            </div>

            @php
                $kpis = [
                    ['key'=>'active_users','icon'=>'active-users','class'=>'green','label'=>'active_users'],
                    ['key'=>'orders','icon'=>'orders','class'=>'orange','label'=>'orders'],
                    ['key'=>'revenue','icon'=>'revenue','class'=>'green','label'=>'revenue'],
                    ['key'=>'products_sold','icon'=>'products','class'=>'orange','label'=>'products_sold'],
                ];
            @endphp
            <section class="kpis" style="--foodex-card-columns:{{ min(8,max(1,count($kpis))) }}">
                @foreach($kpis as $item)
                    @php $metric=$dashboard['kpis'][$item['key']]; $delta=$metric['delta']; @endphp
                    <article class="kpi">
                        <div class="kpi-head"><span class="kpi-icon {{ $item['class'] }}">@include('admin._premium-icon',['name'=>$item['icon']])</span><span class="kpi-label">{{ __('admin.b2c_dashboard.kpis.'.$item['label']) }}@if(app()->getLocale()==='en')<small lang="en">{{ __('admin.b2c_dashboard.kpis.'.$item['label'].'_en') }}</small>@endif</span></div>
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

            <section class="middle" data-dashboard-primary-row style="--dashboard-primary-columns:{{ $canViewDriverTracking ? 3 : 2 }}">
                <article class="panel" data-dashboard-primary-card="sales">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.orders_revenue') }}</h2>@if(app()->getLocale()==='en')<small lang="en">Orders & Revenue</small>@endif</div><div class="legend"><span><i class="dot" style="background:var(--foodex-orange)"></i>{{ __('admin.b2c_dashboard.orders') }}</span><span><i class="dot" style="background:var(--foodex-green)"></i>{{ __('admin.b2c_dashboard.revenue') }}</span></div></div>
                    @php
                        $maxOrders=max(1,max(array_column($dashboard['series'],'orders')));
                        $maxRevenue=max(1,max(array_column($dashboard['series'],'revenue')));
                        $seriesCount=max(1,count($dashboard['series']));
                        $denominator=max(1,$seriesCount-1);
                        $labelEvery=max(1,(int)ceil($seriesCount/7));
                        $step=560/$denominator;
                        $barWidth=max(8,min(29,$step*.42));
                        $points=[];
                        foreach($dashboard['series'] as $i=>$row){$x=50+$i*$step;$y=192-($row['revenue']/$maxRevenue*130);$points[]="$x,$y";}
                    @endphp
                    <div class="chart"><svg viewBox="0 0 650 220" preserveAspectRatio="none" role="img" aria-label="{{ app()->getLocale()==='ar'?'الطلبات والإيرادات خلال الفترة المحددة':'Orders and revenue over the selected period' }}">
                        @for($y=40;$y<=190;$y+=38)<line class="chart-grid" x1="34" y1="{{ $y }}" x2="630" y2="{{ $y }}"/>@endfor
                        @foreach($dashboard['series'] as $i=>$row)
                            @php $centerX=50+$i*$step; $x=$centerX-($barWidth/2); $height=max(4,($row['orders']/$maxOrders*110)); @endphp
                            <rect class="orders-bar" x="{{ $x }}" y="{{ 192-$height }}" width="{{ $barWidth }}" height="{{ $height }}" rx="5"/>
                            @if($i % $labelEvery === 0 || $i === $seriesCount-1)<text class="axis-label" x="{{ $centerX }}" y="211" text-anchor="middle">{{ $row['label'] }}</text>@endif
                        @endforeach
                        <polyline class="revenue-line" points="{{ implode(' ',$points) }}"/>
                        @foreach($points as $point) @php [$cx,$cy]=explode(',',$point); @endphp <circle class="revenue-dot" cx="{{ $cx }}" cy="{{ $cy }}" r="4"/> @endforeach
                    </svg></div>
                </article>

                @php
                    $dist=$dashboard['distribution'];$total=array_sum($dist);$p1=$total?($dist['processing']/$total*100):0;$p2=$total?($dist['out_for_delivery']/$total*100):0;$p3=$total?($dist['delivered']/$total*100):0;
                    $gradient="conic-gradient(var(--foodex-blue) 0 {$p1}%, var(--foodex-orange) {$p1}% ".($p1+$p2)."%, var(--foodex-green) ".($p1+$p2)."% ".($p1+$p2+$p3)."%, var(--foodex-red) ".($p1+$p2+$p3)."% 100%)";
                @endphp
                @if($canViewDriverTracking)
                <article class="panel dashboard-live-map-card" data-dashboard-primary-card="driver-map" data-dashboard-live-driver-map>
                    @include('admin._driver-live-map', [
                        'feedUrl' => $driverTrackingFeedUrl,
                        'liveMapMode' => 'compact',
                        'showFilters' => false,
                        'showList' => false,
                        'showSummary' => true,
                        'pollMs' => 5000,
                        'ctaUrl' => $driverTrackingPageUrl,
                    ])
                </article>
                @endif

                <article class="panel" data-dashboard-primary-card="order-distribution">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.distribution') }}</h2>@if(app()->getLocale()==='en')<small lang="en">Orders Distribution</small>@endif</div></div>
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
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.low_stock') }}</h2>@if(app()->getLocale()==='en')<small lang="en">Low Stock Products</small>@endif</div><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="stock-list">
                        @forelse($dashboard['low_stock'] as $product)
                            <div class="stock"><span class="product-thumb">@if($product['image'])<img src="{{ asset(ltrim($product['image'],'/')) }}" alt="">@else ▧ @endif</span><span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></span><span class="stock-count">{{ number_format($product['available'],0) }} {{ __('admin.b2c_dashboard.remaining') }}</span></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_low_stock') }}</div> @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.recent_orders') }}</h2>@if(app()->getLocale()==='en')<small lang="en">Recent Orders</small>@endif</div><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'orders','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="recent-list">
                        <div class="recent header"><span>#</span><span>{{ __('admin.b2c_dashboard.customer') }}</span><span>{{ __('admin.b2c_dashboard.items') }}</span><span>{{ __('admin.b2c_dashboard.amount') }}</span><span>{{ __('admin.b2c_dashboard.status_label') }}</span><span>{{ __('admin.b2c_dashboard.time') }}</span></div>
                        @forelse($dashboard['recent_orders'] as $order)
                            <div class="recent"><strong>{{ $order['number'] }}</strong><span>{{ $order['customer'] }}</span><span>{{ $order['items'] }}</span><span>{{ $order['currency'] }} {{ number_format($order['amount'],3) }}</span><span><i class="badge {{ $order['status'] }}">{{ __('admin.b2c_dashboard.status.'.$order['status']) }}</i></span><small>{{ \Carbon\Carbon::parse($order['created_at'])->locale(app()->getLocale())->diffForHumans() }}</small></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_orders') }}</div> @endforelse
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
                @php
                    $selectedStore = collect($availableStores)->firstWhere('id', $storeId);
                    $selectedStoreName = $selectedStore ? $selectedStore->name : null;
                @endphp
                <p>{{ __('admin.b2c_workspace.assigned_scope') }}: {{ $storeId }}@if($selectedStoreName) — {{ $selectedStoreName }}@endif</p>
                @if(count($availableStores) > 1)
                <form method="get" action="{{ route($module==='dashboard' ? 'admin.b2c.dashboard' : 'admin.b2c.module', $module==='dashboard' ? [] : ['module'=>$module]) }}" class="module-inline-form" style="margin-top:12px">
                    <label>{{ app()->getLocale()==='ar'?'المتجر الحالي':'Current store' }}
                        <select name="store_id" onchange="this.form.submit()">
                            @foreach($availableStores as $availableStore)
                                <option value="{{ $availableStore->id }}" @selected((int)$availableStore->id===$storeId)>{{ $availableStore->name }} — {{ $availableStore->code }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <noscript><button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تغيير':'Switch' }}</button></noscript>
                </form>
                @endif
            </div>
            @include('admin._live-notifications')
        </div>
        @if(session('status'))<div class="flash ok" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash err" role="alert">{{ $errors->first() }}</div>@endif
        @php
            $moduleMetricIcons = [
                'products'=>'products',
                'orders'=>'orders',
                'incoming_orders'=>'delivery',
                'finance'=>'revenue',
                'customers'=>'customers',
                'inventory'=>'inventory',
            ];
        @endphp
        <div class="module-cards" style="--foodex-card-columns:{{ min(8,max(1,count($counts))) }}">
            @foreach($counts as $key=>$value)
                <div class="module-card foodex-card">
                    <div class="module-card-copy">
                        <strong>{{ __('admin.b2c_workspace.modules.'.$key) }}</strong>
                        <p>{{ number_format($value) }}</p>
                    </div>
                    <span class="module-card-icon">@include('admin._premium-icon',['name'=>$moduleMetricIcons[$key] ?? 'reports'])</span>
                </div>
            @endforeach
        </div>
        @if($moduleData)
        @php
            $labels = app()->getLocale()==='ar'
                ? [
                    'sku'=>'رمز المنتج','name'=>'الاسم','category'=>'التصنيف','store'=>'المتجر','cost'=>'تكلفة الشراء','price'=>'سعر البيع','status'=>'الحالة',
                    'warehouse'=>'المخزن','quantity'=>'الكمية','reserved'=>'المحجوز','available'=>'المتاح','received'=>'تم الاستلام','tracking'=>'تتبع الحالة',
                    'number'=>'رقم الطلب','customer'=>'العميل','amount'=>'الإجمالي','created'=>'تاريخ الإنشاء',
                    'phone'=>'الهاتف','email'=>'البريد','orders'=>'الطلبات','spent'=>'إجمالي الإنفاق','last_order'=>'آخر طلب','type'=>'النوع','value'=>'القيمة','period'=>'الفترة','driver_type'=>'نوع السائق','driver'=>'السائق','order'=>'الطلب','invoice'=>'الفاتورة','customer'=>'العميل','paid'=>'المدفوع','balance'=>'الرصيد','assignment_status'=>'حالة التوصيل','availability'=>'التوفر','products'=>'المنتجات','banners'=>'البانرات','title'=>'العنوان','image'=>'الصورة','target'=>'المنتج / التصنيف','sort_order'=>'الترتيب','average'=>'متوسط الطلب','setting'=>'الإعداد','actions'=>'إجراءات',
                ]
                : [
                    'sku'=>'SKU','name'=>'Name','category'=>'Category','store'=>'Store','cost'=>'Purchase cost','price'=>'Selling price','status'=>'Status',
                    'warehouse'=>'Warehouse','quantity'=>'Quantity','reserved'=>'Reserved','available'=>'Available','received'=>'Received','tracking'=>'Status tracking',
                    'number'=>'Order','customer'=>'Customer','amount'=>'Amount','created'=>'Created',
                    'phone'=>'Phone','email'=>'Email','orders'=>'Orders','spent'=>'Total spent','last_order'=>'Last order','type'=>'Type','value'=>'Value','period'=>'Period','driver_type'=>'Driver type','driver'=>'Driver','order'=>'Order','invoice'=>'Invoice','customer'=>'Customer','paid'=>'Paid','balance'=>'Balance','assignment_status'=>'Delivery status','availability'=>'Availability','products'=>'Products','banners'=>'Banners','title'=>'Title','image'=>'Image','target'=>'Product / Category','sort_order'=>'Sort order','average'=>'Average order','setting'=>'Setting','actions'=>'Actions',
                ];
        @endphp
        <section class="module-panel foodex-card">
            <div class="module-toolbar">
                <div>
                    <strong>{{ __('admin.b2c_workspace.authoritative') }}</strong>
                    <p class="empty">{{ app()->getLocale()==='ar' ? 'بيانات مباشرة ضمن المتاجر المصرح بها لهذا المستخدم.' : 'Live server data restricted to this user\'s assigned stores.' }}</p>
                </div>
            </div>
            @if(in_array($module, ['products', 'promotions'], true) && !empty($moduleData['commercial']))
                @include('admin._commercial-dashboard-contract')
            @endif

            @if(!empty($moduleData['actions']))
                <div class="module-links module-actions">
                    @foreach($moduleData['actions'] as $action)
                        <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif

            @if($module==='storefront')
                @include('admin._storefront-builder')
            @endif

            @if($module==='settings' && (collect($storeIds)->contains(fn($candidateStoreId) => $user->hasPermission('settings.manage',(int)$candidateStoreId)) || $user->hasPermission('settings.manage')))
                <form method="post" action="{{ route('admin.b2c.settings.save') }}" class="module-inline-form">
                    @csrf @method('PUT')
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <input name="key" required maxlength="255" placeholder="storefront.setting.key">
                    <input name="value" maxlength="5000" placeholder="{{ app()->getLocale()==='ar'?'القيمة':'Value' }}">
                    <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ إعداد المتجر':'Save store setting' }}</button>
                </form>
            @endif

            @if($module==='inventory' && (collect($storeIds)->contains(fn($candidateStoreId) => $user->hasPermission('inventory.manage',(int)$candidateStoreId)) || $user->hasPermission('inventory.manage')))
                <div style="display:grid;gap:10px;margin-bottom:14px">
                    <form method="post" action="{{ route('admin.b2c.warehouses.store') }}" class="module-inline-form">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <input name="code" required maxlength="80" placeholder="{{ app()->getLocale()==='ar'?'كود المخزن - مثال RET-01':'Warehouse code - e.g. RET-01' }}">
                        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم المخزن':'Warehouse name' }}">
                        <label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة مخزن':'Add warehouse' }}</button>
                    </form>
                    @if(!empty($moduleData['warehouses']) && !empty($moduleData['products']))
                    <form method="post" action="{{ route('admin.b2c.inventory.ensure') }}" class="module-inline-form">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <select name="warehouse_id" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر المخزن':'Select warehouse' }}</option>
                            @foreach($moduleData['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}">{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>@endforeach
                        </select>
                        <select name="product_id" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر المنتج':'Select product' }}</option>
                            @foreach($moduleData['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach
                        </select>
                        <input name="quantity" type="number" step="0.001" min="0" required placeholder="{{ app()->getLocale()==='ar'?'الكمية الحالية':'Current quantity' }}">
                        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إنشاء / تحديث الرصيد':'Create / update balance' }}</button>
                    </form>
                    @endif
                    @if(!empty($moduleData['inventory_options']))
                    <form method="post" action="{{ route('admin.b2c.inventory.adjust',['inventory'=>$moduleData['inventory_options'][0]['id']]) }}" class="module-inline-form" id="inventory-adjust-form" onsubmit="this.action=this.action.replace(/\/\d+\/adjust$/, '/'+this.inventory_id.value+'/adjust')">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <select name="inventory_id" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر الصنف والمخزن':'Select inventory item' }}</option>
                            @foreach($moduleData['inventory_options'] as $inventory)<option value="{{ $inventory['id'] }}">{{ $inventory['label'] }}</option>@endforeach
                        </select>
                        <input name="quantity_delta" type="number" step="0.001" required placeholder="{{ app()->getLocale()==='ar'?'التغيير + أو -':'Adjustment + or -' }}">
                        <input name="reason" maxlength="255" required placeholder="{{ app()->getLocale()==='ar'?'سبب التعديل':'Adjustment reason' }}">
                        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعديل المخزون':'Adjust stock' }}</button>
                    </form>
                    @if(!empty($moduleData['warehouses']))
                    <form method="post" action="{{ route('admin.b2c.inventory.transfer') }}" class="module-inline-form">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                        <select name="inventory_id" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر الرصيد المراد نقله':'Select stock to transfer' }}</option>
                            @foreach($moduleData['inventory_options'] as $inventory)<option value="{{ $inventory['id'] }}">{{ $inventory['label'] }}</option>@endforeach
                        </select>
                        <select name="target_warehouse_id" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر المخزن الهدف':'Select target warehouse' }}</option>
                            @foreach($moduleData['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}">{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>@endforeach
                        </select>
                        <input name="quantity" type="number" min="0.001" step="0.001" required placeholder="{{ app()->getLocale()==='ar'?'الكمية المراد نقلها':'Quantity to transfer' }}">
                        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'نقل إلى مخزن آخر':'Transfer warehouse' }}</button>
                    </form>
                    @endif
                    @endif
                </div>
            @endif

            @if($module==='customers' && $storeId > 0 && ($user->hasPermission('customers.create',$storeId) || $user->hasPermission('customers.create')))
                <form method="post" action="{{ route('admin.business.customers.store') }}" enctype="multipart/form-data" class="module-inline-form">
                    @csrf
                    <input type="hidden" name="type" value="b2c">
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم العميل':'Customer name' }}">
                    <input name="phone" maxlength="100" placeholder="{{ app()->getLocale()==='ar'?'رقم الهاتف':'Phone number' }}">
                    <input name="email" type="email" maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'البريد الإلكتروني':'Email address' }}">
                    <input type="file" name="customer_image" accept="image/jpeg,image/png,image/webp" aria-label="{{ app()->getLocale()==='ar'?'صورة العميل':'Customer image' }}">
                    <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة العميل':'Add customer' }}</button>
                </form>
            @endif

            @if($module==='drivers' && !empty($moduleData['drivers']) && !empty($moduleData['orders']) && (collect($storeIds)->contains(fn($storeId) => $user->hasPermission('drivers.b2c.manage',(int)$storeId)) || $user->hasPermission('drivers.b2c.manage')))
                <form method="post" action="{{ route('admin.b2c.drivers.assign') }}" class="module-inline-form">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <select name="driver_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر السائق':'Select driver' }}</option>@foreach($moduleData['drivers'] as $driver)<option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>@endforeach</select>
                    <select name="order_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر الطلب':'Select order' }}</option>@foreach($moduleData['orders'] as $order)<option value="{{ $order['id'] }}">{{ $order['number'] }}</option>@endforeach</select>
                    <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين السائق':'Assign driver' }}</button>
                </form>
            @endif
            @if($module==='drivers' && !empty($moduleData['drivers']) && (collect($storeIds)->contains(fn($candidateStoreId) => $user->hasPermission('drivers.b2c.manage',(int)$candidateStoreId)) || $user->hasPermission('drivers.b2c.manage')))
                <div class="module-inline-form" style="display:block">
                    <strong>{{ app()->getLocale()==='ar'?'إعادة تعيين كلمة مرور السائق':'Reset driver password' }}</strong>
                    <p class="empty" style="margin:5px 0 10px">{{ app()->getLocale()==='ar'?'حدد كلمة مرور جديدة للسائق. سيتم إلغاء جلساته الحالية فوراً.':'Set a new driver password. Existing sessions will be revoked immediately.' }}</p>
                    <div style="display:grid;gap:8px">
                        @foreach($moduleData['drivers'] as $driver)
                        <details style="border:1px solid var(--foodex-border);border-radius:12px;padding:10px 12px;background:#fff">
                            <summary style="cursor:pointer;font-weight:700">{{ $driver['name'] }} · {{ app()->getLocale()==='ar'?'اسم المستخدم':'Username' }}: {{ $driver['username'] ?? '—' }} · {{ $driver['email'] }}</summary>
                            <form method="post" action="{{ route('admin.b2c.drivers.password',['driver'=>$driver['id']]) }}" class="module-inline-form" style="margin:10px 0 0">
                                @csrf @method('PATCH')
                                <input type="hidden" name="store_id" value="{{ $storeId }}">
                                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                                <input name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="{{ app()->getLocale()==='ar'?'كلمة المرور الجديدة':'New password' }}">
                                <input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" placeholder="{{ app()->getLocale()==='ar'?'تأكيد كلمة المرور':'Confirm password' }}">
                                <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إعادة تعيين كلمة المرور':'Reset password' }}</button>
                            </form>
                        </details>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($module==='drivers' && (collect($storeIds)->contains(fn($candidateStoreId) => $user->hasPermission('drivers.b2c.manage',(int)$candidateStoreId)) || $user->hasPermission('drivers.b2c.manage')))
                @include('admin._driver-assignment-management',['channel'=>'b2c'])
            @endif
            @if($module==='content' && $storeId > 0 && ($user->hasPermission('promotions.manage',$storeId) || $user->hasPermission('promotions.manage')))
                <div class="module-inline-form" style="margin-bottom:12px">
                    <strong>{{ app()->getLocale()==='ar'?'البانرات تُحفظ كمسودة':'Banners are saved as Draft' }}</strong>
                    <span>{{ app()->getLocale()==='ar'?'لن تتغير واجهة التطبيق المنشورة حتى تنشر المسودة من إدارة واجهة المتجر.':'The published app will not change until the Draft is published from Storefront management.' }}</span>
                    <a class="foodex-primary" href="{{ route('admin.b2c.module',['module'=>'storefront','store_id'=>$storeId] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ app()->getLocale()==='ar'?'إدارة المسودة والنشر':'Manage Draft & Publish' }}</a>
                </div>
                <form method="post" action="{{ route('admin.business.banners.store') }}" enctype="multipart/form-data" class="module-inline-form">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <input name="title" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'عنوان البانر':'Banner title' }}">
                    <input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" required aria-label="{{ app()->getLocale()==='ar'?'صورة البانر':'Banner image' }}">
                    <select name="target_ref" required><option value="">{{ app()->getLocale()==='ar'?'اختر المنتج أو التصنيف':'Select product or category' }}</option>@foreach(($moduleData['targets'] ?? []) as $target)<option value="{{ $target['ref'] }}">{{ $target['label'] }}</option>@endforeach</select>
                    <input type="number" name="sort_order" min="0" value="0" required placeholder="{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}">
                    <label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="is_active" value="1" checked> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                    <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة البانر للمسودة':'Add banner to Draft' }}</button>
                </form>
            @endif
            @if($module==='orders' && $storeId > 0 && (collect($storeIds)->contains(fn($candidateStoreId) => $user->hasPermission('orders.manage',(int)$candidateStoreId)) || $user->hasPermission('orders.manage')))
                @include('admin._dashboard-order-create',['channel'=>'b2c'])
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
                                    @if($column==='actions' && $module==='orders' && ($user->hasPermission('orders.manage',$row['_store_id']) || $user->hasPermission('orders.manage')))
                                        @include('admin._dashboard-order-actions',['channel'=>'b2c','row'=>$row])
                                    @elseif($column==='image' && $module==='content')
                                        <img src="{{ asset($row['image']) }}" alt="{{ $row['title'] }}" style="width:112px;height:58px;object-fit:cover;border-radius:10px;border:1px solid var(--foodex-border)">
                                    @elseif($column==='image' && $module==='customers')
                                        @if(!empty($row['image']))
                                            <img src="{{ asset(ltrim($row['image'],'/')) }}" alt="{{ $row['name'] }}" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid var(--foodex-border)">
                                        @else
                                            <span aria-label="{{ app()->getLocale()==='ar'?'صورة افتراضية للعميل':'Default customer avatar' }}" style="width:48px;height:48px;border-radius:50%;display:inline-grid;place-items:center;background:var(--foodex-background);border:1px solid var(--foodex-border);font-size:24px">👤</span>
                                        @endif
                                    @elseif($column==='actions' && $module==='customers')
                                        <div style="display:grid;gap:7px;min-width:300px">
                                            @if($user->hasPermission('customers.edit',$row['_store_id']) || $user->hasPermission('customers.edit'))
                                            <form method="post" action="{{ route('admin.business.customers.update',$row['_id']) }}" enctype="multipart/form-data" class="module-inline-form" style="margin:0;padding:10px">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="type" value="b2c">
                                                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                                                <input name="name" value="{{ $row['name'] }}" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم العميل':'Customer name' }}">
                                                <input name="phone" value="{{ $row['phone']==='-'?'':$row['phone'] }}" maxlength="100" placeholder="{{ app()->getLocale()==='ar'?'رقم الهاتف':'Phone number' }}">
                                                <input name="email" value="{{ $row['email']==='-'?'':$row['email'] }}" type="email" maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'البريد الإلكتروني':'Email address' }}">
                                                <input type="file" name="customer_image" accept="image/jpeg,image/png,image/webp" aria-label="{{ app()->getLocale()==='ar'?'استبدال صورة العميل':'Replace customer image' }}">
                                                @if(!empty($row['image']))<label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="remove_image" value="1"> {{ app()->getLocale()==='ar'?'حذف الصورة الحالية':'Remove current image' }}</label>@endif
                                                <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button>
                                            </form>
                                            @endif
                                            @if($user->hasPermission('customers.delete',$row['_store_id']) || $user->hasPermission('customers.delete'))
                                            <form method="post" action="{{ route('admin.business.customers.destroy',$row['_id']) }}" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'حذف العميل؟':'Delete this customer?' }}')">@csrf @method('DELETE')<input type="hidden" name="type" value="b2c">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="danger btn" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                                            @endif
                                        </div>
                                    @elseif($column==='actions' && $module==='content')
                                        <div style="display:grid;gap:7px;min-width:310px">
                                            <form method="post" action="{{ route('admin.business.banners.update',$row['_id']) }}" enctype="multipart/form-data" class="module-inline-form" style="margin:0;padding:10px">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="store_id" value="{{ $row['_store_id'] }}">
                                                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                                                <input name="title" value="{{ $row['title'] }}" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'عنوان البانر':'Banner title' }}">
                                                <input type="file" name="banner_image" accept="image/jpeg,image/png,image/webp" aria-label="{{ app()->getLocale()==='ar'?'استبدال صورة البانر':'Replace banner image' }}">
                                                 <select name="target_ref" required><option value="">{{ app()->getLocale()==='ar'?'اختر المنتج أو التصنيف':'Select product or category' }}</option>@foreach(($moduleData['targets'] ?? []) as $target)<option value="{{ $target['ref'] }}" @selected($row['_target_ref']===$target['ref'])>{{ $target['label'] }}</option>@endforeach</select>
                                                <input type="number" name="sort_order" min="0" value="{{ $row['sort_order'] }}" required>
                                                <label style="display:flex;align-items:center;gap:7px"><input type="checkbox" name="is_active" value="1" @checked($row['status'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                                                <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button>
                                            </form>
                                            <form method="post" action="{{ route('admin.business.banners.destroy',$row['_id']) }}" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'حذف البانر من المسودة؟':'Delete this banner from Draft?' }}')">@csrf @method('DELETE')<input type="hidden" name="store_id" value="{{ $row['_store_id'] }}">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="danger btn" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button></form>
                                        </div>
                                    @elseif($column==='actions' && is_array($row[$column] ?? null))
                                        <div class="module-links">
                                            @foreach($row[$column] as $action)<a href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach
                                        </div>
                                    @elseif($column==='actions')
                                        <span class="empty">—</span>
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
@if($module === 'dashboard' && $canViewDriverTracking)
@include('admin._driver-live-map-scripts')
@endif
</body>
</html>
