<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('admin.b2c_workspace.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:var(--foodex-font-ui);background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit;text-decoration:none}.dashboard-layout{display:grid;grid-template-columns:minmax(0,1fr) 225px;min-height:100vh}.dashboard-shell{min-width:0}.dashboard-sidebar{grid-column:2;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);min-height:100vh}
        html[dir=ltr] .dashboard-layout{grid-template-columns:225px minmax(0,1fr)}html[dir=ltr] .dashboard-sidebar{grid-column:1;grid-row:1}html[dir=ltr] .dashboard-shell{grid-column:2;grid-row:1}
        .topbar{height:76px;background:#fff;border-bottom:1px solid var(--foodex-border);display:grid;grid-template-columns:minmax(210px,1fr) minmax(320px,520px) minmax(230px,1fr);align-items:center;gap:18px;padding:0 24px;position:sticky;top:0;z-index:10}
        .profile{display:flex;align-items:center;gap:10px}.avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;border:1px solid #d5eddd}.profile small{display:block;color:var(--foodex-muted)}
        .top-actions{display:flex;align-items:center;justify-content:flex-end;gap:16px;color:#475467}.language,.bell{display:inline-flex;align-items:center;gap:7px}.bell{position:relative;font-size:20px}.bell b{position:absolute;width:9px;height:9px;border-radius:50%;background:var(--foodex-orange);inset:-1px -2px auto auto}
        .global-search{position:relative}.global-search input{width:100%;height:44px;border:1px solid var(--foodex-border);border-radius:10px;padding:0 42px 0 15px;background:#fff;outline:none}.global-search input:focus{border-color:var(--foodex-green);box-shadow:0 0 0 3px rgba(21,138,58,.1)}.search-icon{position:absolute;inset-inline-end:14px;top:12px;color:#667085}
        .search-results{position:absolute;top:50px;inset-inline:0;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:var(--foodex-shadow);padding:8px;z-index:30}.search-results a{display:flex;justify-content:space-between;gap:10px;padding:9px 10px;border-radius:8px}.search-results a:hover{background:var(--foodex-green-soft)}.search-results small{color:var(--foodex-muted)}
        .content{padding:22px 24px 30px}.headline{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:18px}.headline h1{margin:0 0 5px;font-size:28px}.headline p{margin:0;color:var(--foodex-muted)}.date-control{display:flex;gap:8px;align-items:center;background:#fff;border:1px solid var(--foodex-border);border-radius:10px;padding:8px 12px}.date-control input{border:0;outline:0;background:transparent;color:var(--foodex-ink)}
        .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.kpi{background:#fff;border:1px solid var(--foodex-border);border-radius:15px;padding:17px;min-height:142px;box-shadow:0 7px 20px rgba(16,24,40,.035)}.kpi-head{display:flex;align-items:center;gap:12px}.kpi-icon{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-size:21px}.green{background:var(--foodex-green-soft);color:var(--foodex-green)}.orange{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.blue{background:#edf4ff;color:var(--foodex-blue)}.kpi-label{font-weight:750}.kpi-label small{display:block;font-weight:500;color:var(--foodex-muted);margin-top:2px}.kpi-value{font-size:28px;font-weight:850;margin:13px 0 7px}.delta{font-size:12px;color:var(--foodex-green);background:var(--foodex-green-soft);border-radius:999px;padding:4px 8px;display:inline-flex}.delta.down{color:var(--foodex-red);background:#fff0f0}.delta.na{color:var(--foodex-muted);background:#f2f4f7}
        .middle{display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:14px;margin-top:14px}.panel{background:#fff;border:1px solid var(--foodex-border);border-radius:15px;padding:18px;box-shadow:0 7px 20px rgba(16,24,40,.035)}.panel-title{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px}.panel-title h2{font-size:17px;margin:0}.panel-title small{display:block;color:var(--foodex-muted);font-weight:500;margin-top:2px}.legend{display:flex;gap:14px;color:var(--foodex-muted);font-size:12px}.dot{width:9px;height:9px;border-radius:50%;display:inline-block;margin-inline-end:4px}.chart{height:245px;width:100%;overflow:hidden}.chart svg{width:100%;height:100%}.chart-grid{stroke:#edf0f4;stroke-width:1}.orders-bar{fill:var(--foodex-orange-bright)}.revenue-line{fill:none;stroke:var(--foodex-green);stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.revenue-dot{fill:#fff;stroke:var(--foodex-green);stroke-width:3}.axis-label{font-size:10px;fill:#98a2b3}
        .donut-wrap{display:flex;align-items:center;gap:18px;min-height:245px}.donut{width:165px;height:165px;border-radius:50%;position:relative;flex:0 0 auto}.donut:after{content:"";position:absolute;inset:31px;background:#fff;border-radius:50%}.donut-center{position:absolute;inset:0;z-index:2;display:grid;place-content:center;text-align:center;font-weight:850;font-size:23px}.donut-center small{font-size:11px;color:var(--foodex-muted);font-weight:600}.status-list{display:grid;gap:12px;flex:1}.status-row{display:flex;justify-content:space-between;gap:12px;font-size:13px}.status-row span:first-child{display:flex;align-items:center;gap:7px}
        .bottom{display:grid;grid-template-columns:minmax(250px,.9fr) minmax(420px,1.45fr) minmax(230px,.85fr);gap:14px;margin-top:14px}.section-link{color:var(--foodex-green);font-size:12px;font-weight:750}.stock-list,.recent-list{display:grid}.stock,.recent{border-bottom:1px solid #f0f2f5;padding:10px 0}.stock:last-child,.recent:last-child{border-bottom:0}.stock{display:grid;grid-template-columns:42px 1fr auto;align-items:center;gap:10px}.product-thumb{width:38px;height:38px;border-radius:9px;background:var(--foodex-orange-soft);display:grid;place-items:center;font-size:18px}.stock strong{display:block;font-size:13px}.stock small{color:var(--foodex-muted)}.stock-count{color:var(--foodex-red);background:#fff0f0;border-radius:8px;padding:5px 7px;font-size:11px;font-weight:750}
        .recent{display:grid;grid-template-columns:72px 1fr 68px 96px 96px 74px;gap:8px;align-items:center;font-size:12px}.recent.header{color:var(--foodex-muted);font-weight:700;background:#fafbfc;border-radius:8px;padding:8px}.badge{display:inline-flex;justify-content:center;border-radius:999px;padding:4px 7px;font-size:10px;font-weight:750}.badge.delivered,.badge.completed{background:var(--foodex-green-soft);color:var(--foodex-green)}.badge.out_for_delivery,.badge.in_transit,.badge.assigned,.badge.picked_up{background:#edf4ff;color:var(--foodex-blue)}.badge.cancelled,.badge.refunded{background:#fff0f0;color:var(--foodex-red)}.badge.pending,.badge.processing,.badge.confirmed,.badge.paid,.badge.accepted{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.quick{min-height:94px;border-radius:12px;padding:13px 9px;display:grid;place-content:center;text-align:center;font-size:12px;font-weight:750}.quick:nth-child(4n+1){background:var(--foodex-orange-soft);color:#b54c06}.quick:nth-child(4n+2){background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.quick:nth-child(4n+3){background:#edf4ff;color:#2465c7}.quick:nth-child(4n){background:#f3edff;color:#7047c8}.quick i{font-style:normal;font-size:25px;margin-bottom:5px}
        .apps-card{margin:15px 12px;background:linear-gradient(145deg,#fff8ef,#fff0dc);border:1px solid #ffe3bf;border-radius:14px;padding:13px;text-align:center}.phone{width:58px;height:96px;border-radius:12px;background:#172033;margin:5px auto 9px;padding:6px;transform:rotate(-7deg);box-shadow:0 8px 20px rgba(16,24,40,.18)}.phone-screen{height:100%;border-radius:8px;background:linear-gradient(var(--foodex-green),#fff);display:grid;place-items:center;color:#fff;font-size:10px}.store-links{display:flex;justify-content:center;gap:8px;margin-top:10px}.store-links a{background:#fff;border:1px solid var(--foodex-border);border-radius:8px;padding:5px 7px;font-size:11px}
        .module-layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}.module-layout aside{background:#fff;border-inline-end:1px solid var(--foodex-border);padding:24px}.module-layout main{padding:28px}.module-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}.module-card,.module-panel{background:white;border:1px solid var(--foodex-border);border-radius:16px;padding:18px}.module-panel{margin-top:18px}.empty{color:var(--foodex-muted)}.module-toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.module-table-wrap{overflow:auto;border:1px solid var(--foodex-border);border-radius:12px}.module-table{width:100%;border-collapse:collapse;min-width:760px}.module-table th,.module-table td{padding:12px 14px;border-bottom:1px solid #eef1f4;text-align:start;font-size:13px}.module-table th{background:#f8faf9;color:#667085;font-size:12px}.module-table tr:last-child td{border-bottom:0}.state-dot{display:inline-flex;align-items:center;gap:6px}.state-dot:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green)}.state-dot.off:before{background:#98a2b3}.module-links{display:flex;flex-wrap:wrap;gap:8px}.module-links a{border:1px solid var(--foodex-border);background:#fff;border-radius:9px;padding:8px 11px;font-size:12px;font-weight:700}.module-links a.active{background:var(--foodex-green-soft);border-color:#cae8d4;color:var(--foodex-green-dark)}
        @media(max-width:1180px){.kpis{grid-template-columns:1fr 1fr}.middle{grid-template-columns:1fr}.bottom{grid-template-columns:1fr 1fr}.bottom .panel:nth-child(2){grid-column:1/-1;grid-row:1}.recent{grid-template-columns:70px 1fr 64px 90px 90px 70px}}
        @media(max-width:860px){.dashboard-layout{grid-template-columns:1fr}.dashboard-sidebar{display:none}.dashboard-shell{grid-column:1!important}.topbar{grid-template-columns:1fr auto;height:auto;min-height:68px;padding:12px 16px}.global-search{grid-column:1/-1;grid-row:2}.profile{grid-row:1}.top-actions{grid-row:1}.bottom{grid-template-columns:1fr}.bottom .panel:nth-child(2){grid-column:auto;grid-row:auto}.module-layout{grid-template-columns:1fr}.module-layout aside{display:none}}
        @media(max-width:620px){.content{padding:16px}.kpis{grid-template-columns:1fr}.headline{align-items:flex-start;flex-direction:column}.donut-wrap{flex-direction:column}.recent.header{display:none}.recent{grid-template-columns:1fr auto;gap:4px}.recent>*:nth-child(3),.recent>*:nth-child(4){display:none}.quick-grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>
@if($module === 'dashboard' && $dashboard)
<div class="dashboard-layout">
    <section class="dashboard-shell">
        <header class="topbar">
            <div class="profile">
                <div class="avatar">{{ mb_substr($user->name, 0, 1) }}</div>
                <div><strong>{{ $user->name }}</strong><small>{{ __('admin.b2c_dashboard.system_manager') }}</small></div>
            </div>
            <form class="global-search" method="get" action="{{ route('admin.b2c.dashboard') }}">
                <input type="hidden" name="date" value="{{ $dashboard['selected_date'] }}">
                <input name="q" value="{{ request('q') }}" placeholder="{{ __('admin.b2c_dashboard.search_placeholder') }}" autocomplete="off">
                <span class="search-icon">⌕</span>
                @if(request('q') && count($dashboard['search']))
                <div class="search-results">
                    @foreach($dashboard['search'] as $result)
                    <a href="{{ $result['route'] }}"><span><strong>{{ $result['title'] }}</strong><small> · {{ $result['subtitle'] }}</small></span><small>{{ __('admin.b2c_dashboard.search_types.'.$result['type']) }}</small></a>
                    @endforeach
                </div>
                @endif
            </form>
            <div class="top-actions">
                <span class="language">◎ {{ app()->getLocale()==='ar' ? 'العربية' : 'English' }}</span>
                <span class="bell">♧ @if($dashboard['notifications_unread']>0)<b></b>@endif</span>
            </div>
        </header>

        <main class="content">
            <div class="headline">
                <div><h1>{{ __('admin.b2c_dashboard.hello', ['name'=>$user->name]) }} 👋</h1><p>{{ __('admin.b2c_dashboard.subtitle') }}</p></div>
                <form class="date-control" method="get" action="{{ route('admin.b2c.dashboard') }}"><span>⌄</span><input type="date" name="date" value="{{ $dashboard['selected_date'] }}" onchange="this.form.submit()"></form>
            </div>

            @php
                $kpis = [
                    ['key'=>'active_users','icon'=>'♟','class'=>'green','label'=>'active_users'],
                    ['key'=>'orders','icon'=>'🛒','class'=>'orange','label'=>'orders'],
                    ['key'=>'revenue','icon'=>'▣','class'=>'green','label'=>'revenue'],
                    ['key'=>'products_sold','icon'=>'◇','class'=>'orange','label'=>'products_sold'],
                ];
            @endphp
            <section class="kpis">
                @foreach($kpis as $item)
                    @php $metric=$dashboard['kpis'][$item['key']]; $delta=$metric['delta']; @endphp
                    <article class="kpi">
                        <div class="kpi-head"><span class="kpi-icon {{ $item['class'] }}">{{ $item['icon'] }}</span><span class="kpi-label">{{ __('admin.b2c_dashboard.kpis.'.$item['label']) }}<small>{{ __('admin.b2c_dashboard.kpis.'.$item['label'].'_en') }}</small></span></div>
                        <div class="kpi-value">
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
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.orders_revenue') }}</h2><small>Orders & Revenue</small></div><div class="legend"><span><i class="dot" style="background:var(--foodex-orange)"></i>{{ __('admin.b2c_dashboard.orders') }}</span><span><i class="dot" style="background:var(--foodex-green)"></i>{{ __('admin.b2c_dashboard.revenue') }}</span></div></div>
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
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.distribution') }}</h2><small>Orders Distribution</small></div></div>
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
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.low_stock') }}</h2><small>Low Stock Products</small></div><a class="section-link" href="{{ route('admin.b2c.module',['module'=>'inventory']) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="stock-list">
                        @forelse($dashboard['low_stock'] as $product)
                            <div class="stock"><span class="product-thumb">▧</span><span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></span><span class="stock-count">{{ number_format($product['available'],0) }} {{ __('admin.b2c_dashboard.remaining') }}</span></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_low_stock') }}</div> @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.recent_orders') }}</h2><small>Recent Orders</small></div><a class="section-link" href="{{ route('admin.b2c.module',['module'=>'orders']) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                    <div class="recent-list">
                        <div class="recent header"><span>#</span><span>{{ __('admin.b2c_dashboard.customer') }}</span><span>{{ __('admin.b2c_dashboard.items') }}</span><span>{{ __('admin.b2c_dashboard.amount') }}</span><span>{{ __('admin.b2c_dashboard.status_label') }}</span><span>{{ __('admin.b2c_dashboard.time') }}</span></div>
                        @forelse($dashboard['recent_orders'] as $order)
                            <div class="recent"><strong>{{ $order['number'] }}</strong><span>{{ $order['customer'] }}</span><span>{{ $order['items'] }}</span><span>{{ $order['currency'] }} {{ number_format($order['amount'],3) }}</span><span><i class="badge {{ $order['status'] }}">{{ __('admin.b2c_dashboard.status.'.$order['status']) }}</i></span><small>{{ \Carbon\Carbon::parse($order['created_at'])->locale(app()->getLocale())->diffForHumans() }}</small></div>
                        @empty <div class="empty">{{ __('admin.b2c_dashboard.no_orders') }}</div> @endforelse
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.quick_actions') }}</h2><small>Quick Actions</small></div></div>
                    <div class="quick-grid">
                        @foreach($dashboard['quick_actions'] as $action)
                            @php $icons=['add_product'=>'◇+','manage_orders'=>'🛒','send_notification'=>'♢','view_reports'=>'▥']; @endphp
                            <a class="quick" href="{{ route($action['route'],$action['params']) }}"><i>{{ $icons[$action['key']]??'•' }}</i>{{ __('admin.b2c_dashboard.actions.'.$action['key']) }}</a>
                        @endforeach
                    </div>
                </article>
            </section>
        </main>
    </section>

    <aside class="dashboard-sidebar sidebar">
        @include('admin._sidebar')
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
<div class="module-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main>
        <div><a href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a> / {{ __('admin.b2c_workspace.modules.'.$module) }}</div>
        <p>{{ __('admin.b2c_workspace.assigned_scope') }}: {{ implode(', ', $storeIds) }}</p>
        <h1>{{ __('admin.b2c_workspace.modules.'.$module) }}</h1>
        <div class="module-cards">@foreach($counts as $key=>$value)<div class="module-card"><strong>{{ __('admin.b2c_workspace.modules.'.$key) }}</strong><p>{{ $value }}</p></div>@endforeach</div>
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
        <section class="module-panel">
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
                <div class="module-links" style="margin-bottom:14px">
                    @foreach($moduleData['actions'] as $action)
                        <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif
            @if(count($moduleData['rows']))
                <div class="module-table-wrap">
                    <table class="module-table">
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
                <p class="empty">{{ app()->getLocale()==='ar' ? 'لا توجد بيانات في هذا القسم للمتاجر المصرح بها.' : 'No records are available in this section for the assigned stores.' }}</p>
            @endif
        </section>
        @else
        <section class="module-panel"><strong>{{ __('admin.b2c_workspace.authoritative') }}</strong><p class="empty">{{ __('admin.b2c_workspace.empty_hint') }}</p></section>
        @endif
    </main>
</div>
@endif
</body>
</html>
