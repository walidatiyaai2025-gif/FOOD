@php
    $merchant = data_get($dashboard, 'merchant_intelligence');
    $merchantSummary = (array) data_get($merchant, 'summary', []);
    $merchantAttention = collect(data_get($merchant, 'attention', []));
    $merchantRisk = (array) data_get($merchant, 'visualizations.stock_risk', []);
    $merchantSpend = (array) data_get($merchant, 'visualizations.reorder_spend', []);
    $merchantAging = (array) data_get($merchant, 'visualizations.inventory_aging', []);
    $merchantCompare = collect(data_get($merchant, 'visualizations.retail_vs_wholesale', []))->take(-7)->values();
    $recentCustomers = collect($dashboard['recent_orders'] ?? [])->pluck('customer')->filter()->unique()->count();

    $riskCritical = (int) ($merchantRisk['critical_understock'] ?? 0)
        + (int) ($merchantRisk['understock'] ?? 0)
        + (int) ($merchantRisk['inventory_inconsistent'] ?? 0);
    $riskHealthy = (int) ($merchantRisk['balanced'] ?? 0);
    $riskOverstock = (int) ($merchantRisk['overstock'] ?? 0) + (int) ($merchantRisk['overstock_no_demand'] ?? 0);
    $riskUnknown = (int) ($merchantRisk['insufficient_data'] ?? 0);
    $riskTotal = max(1, $riskCritical + $riskHealthy + $riskOverstock + $riskUnknown);
    $riskP1 = round($riskCritical / $riskTotal * 100, 2);
    $riskP2 = round(($riskCritical + $riskHealthy) / $riskTotal * 100, 2);
    $riskP3 = round(($riskCritical + $riskHealthy + $riskOverstock) / $riskTotal * 100, 2);

    $spendMax = max(1, (float) max(array_values($merchantSpend ?: [0])));
    $agingMax = max(1, (float) collect($merchantAging)->max(fn ($bucket) => (float) ($bucket['quantity'] ?? 0)));
    $agingLabels = __('admin.b2c_dashboard.merchant_intelligence.aging');
@endphp

<style id="retail-dashboard-control-center-styles">
    .dashboard-control-center{min-height:0}
    .dashboard-pane{display:none;min-height:0}
    .dashboard-pane.active{display:block}
    .rc-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
    .rc-kpi{min-width:0;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);padding:12px 14px;box-shadow:var(--foodex-shadow-sm)}
    .rc-kpi-label{display:block;color:var(--foodex-muted);font-size:.7rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .rc-kpi-value{display:block;margin-top:7px;font-family:var(--foodex-font-en);font-size:1.35rem;font-weight:800;line-height:1.05}
    .rc-kpi-help{display:block;margin-top:5px;color:var(--foodex-muted);font-size:.62rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .rc-grid{display:grid;gap:10px;min-height:0}
    .rc-overview-main{grid-template-columns:minmax(0,1.7fr) minmax(280px,.72fr)}
    .rc-overview-bottom{grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr)}
    .rc-card{min-width:0;min-height:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:12px 14px;box-shadow:var(--foodex-shadow-sm);overflow:hidden}
    .rc-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px}
    .rc-card-head h2{margin:0;font-size:.9rem;font-weight:800}.rc-card-head small{display:block;margin-top:2px;color:var(--foodex-muted);font-size:.64rem}
    .rc-chart{height:100%;min-height:150px;max-height:230px}.rc-chart svg{width:100%;height:100%}
    .rc-attention-list,.rc-compact-list{display:grid;gap:6px;min-height:0}
    .rc-attention-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;border:1px solid var(--foodex-border);border-radius:10px;padding:8px 9px}
    .rc-attention-row strong,.rc-list-row strong{display:block;font-size:.72rem}.rc-attention-row small,.rc-list-row small{display:block;color:var(--foodex-muted);font-size:.61rem;margin-top:2px}
    .rc-state{display:inline-flex;align-items:center;border-radius:999px;padding:3px 7px;font-size:.59rem;font-weight:800;white-space:nowrap}
    .rc-state.now{background:rgba(239,83,80,.10);color:var(--foodex-red)}.rc-state.soon{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.rc-state.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.rc-state.info{background:rgba(75,140,245,.12);color:var(--foodex-blue)}
    .rc-list-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--foodex-border)}.rc-list-row:last-child{border-bottom:0}
    .rc-list-value{font-family:var(--foodex-font-en);font-weight:800;font-size:.68rem;white-space:nowrap}
    .rc-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
    .rc-action-card{display:flex;flex-direction:column;justify-content:space-between;min-height:126px}
    .rc-action-card p{margin:4px 0 12px;color:var(--foodex-muted);font-size:.7rem;line-height:1.55}
    .rc-sales-main{grid-template-columns:minmax(0,1.7fr) minmax(270px,.7fr)}
    .rc-sales-bottom{grid-template-columns:1fr}
    .rc-mini-donut-wrap{display:flex;align-items:center;justify-content:center;gap:18px;height:100%}
    .rc-mini-donut{width:126px;height:126px;border-radius:50%;position:relative;flex:0 0 auto}.rc-mini-donut:after{content:"";position:absolute;inset:26px;background:var(--foodex-surface);border-radius:50%}
    .rc-status-list{display:grid;gap:7px;min-width:130px}.rc-status-line{display:flex;justify-content:space-between;gap:10px;font-size:.67rem}
    .rc-inventory-main{grid-template-columns:minmax(0,1.55fr) minmax(270px,.65fr)}
    .rc-inventory-list{display:grid;gap:7px;max-height:100%;overflow:auto;padding-inline-end:2px}
    .rc-inventory-row{border:1px solid var(--foodex-border);border-radius:11px;padding:9px;display:grid;grid-template-columns:minmax(150px,1fr) repeat(3,minmax(74px,.42fr)) auto;gap:8px;align-items:center}
    .rc-metric span{display:block;color:var(--foodex-muted);font-size:.59rem}.rc-metric b{display:block;margin-top:2px;font-size:.68rem}
    .rc-customer-actions{grid-template-columns:repeat(3,minmax(0,1fr))}
    .rc-analytics{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));height:100%}
    .rc-bars{display:grid;gap:8px}.rc-bar{display:grid;grid-template-columns:minmax(90px,.8fr) 1.8fr auto;gap:8px;align-items:center;font-size:.64rem}.rc-track{height:9px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.rc-track i{display:block;height:100%;background:var(--foodex-green);border-radius:inherit}.rc-bar.danger .rc-track i{background:var(--foodex-red)}.rc-bar.warning .rc-track i{background:var(--foodex-orange)}.rc-bar.info .rc-track i{background:var(--foodex-blue)}
    .rc-compare{display:flex;align-items:end;gap:7px;height:150px;padding-top:8px}.rc-day{flex:1;min-width:22px;height:135px;display:grid;grid-template-columns:1fr 1fr;gap:2px;align-items:end;position:relative;padding-bottom:18px}.rc-day i{min-height:2px;border-radius:3px 3px 1px 1px}.rc-day i:first-child{background:var(--foodex-viz-primary)}.rc-day i:nth-child(2){background:var(--foodex-viz-info)}.rc-day small{position:absolute;bottom:0;inset-inline:0;text-align:center;color:var(--foodex-muted);font-size:.54rem}
    .rc-empty{display:grid;place-items:center;min-height:86px;color:var(--foodex-muted);font-size:.7rem;text-align:center}

    @media(min-width:1200px) and (min-height:720px){
        .dashboard-shell{height:100vh;overflow:hidden}
        .content[data-retail-control-center]{height:calc(100vh - var(--foodex-header-height));overflow:hidden;display:flex;flex-direction:column;padding-bottom:12px}
        .content[data-retail-control-center]>.headline,.content[data-retail-control-center]>.retail-dashboard-nav{flex:0 0 auto}
        .dashboard-control-center{flex:1 1 auto;overflow:hidden}
        .dashboard-pane.active{height:100%;overflow:hidden}
        .dashboard-pane--overview.active{display:grid;grid-template-rows:auto minmax(0,1fr) minmax(116px,.56fr);gap:10px}
        .dashboard-pane--sales.active{display:grid;grid-template-rows:auto minmax(0,1fr) minmax(120px,.52fr);gap:10px}
        .dashboard-pane--inventory.active{display:grid;grid-template-rows:auto minmax(0,1fr);gap:10px}
        .dashboard-pane--customers.active{display:grid;grid-template-rows:auto minmax(0,1fr);gap:10px}
        .dashboard-pane--analytics.active{display:block;height:100%}
        .rc-overview-main,.rc-overview-bottom,.rc-sales-main,.rc-sales-bottom,.rc-inventory-main{height:100%}
        .rc-card{height:100%}
    }
    @media(max-width:1199px){
        .dashboard-pane.active{display:grid;gap:10px}.rc-overview-main,.rc-overview-bottom,.rc-sales-main,.rc-inventory-main,.rc-analytics{grid-template-columns:1fr}
        .rc-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.rc-customer-actions{grid-template-columns:1fr 1fr}
    }
    @media(max-width:767px){
        .rc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.rc-customer-actions{grid-template-columns:1fr}.rc-inventory-row{grid-template-columns:1fr 1fr}.rc-inventory-row>div:first-child{grid-column:1/-1}
        .rc-overview-main,.rc-overview-bottom,.rc-sales-main,.rc-inventory-main,.rc-analytics{grid-template-columns:1fr}.rc-card{overflow:visible}.rc-chart{height:180px}
    }
</style>

<section class="dashboard-control-center" data-retail-dashboard-control-center>
    @if($dashboardTab === 'overview')
    <div class="dashboard-pane dashboard-pane--overview active" data-dashboard-pane="overview">
        <div class="rc-kpis">
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.revenue') }}</span><b class="rc-kpi-value">{{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)data_get($dashboard,'kpis.revenue.value',0), $dashboard['currency']) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.vs_previous') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.orders') }}</span><b class="rc-kpi-value">{{ number_format((int)data_get($dashboard,'kpis.orders.value',0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.total_orders') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.products_sold') }}</span><b class="rc-kpi-value">{{ number_format((int)data_get($dashboard,'kpis.products_sold.value',0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_workspace.modules.products') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['reorder_now'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now_help') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['blocked'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention_help') }}</small></article>
        </div>

        <div class="rc-grid rc-overview-main">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.orders_revenue') }}</h2><small>{{ $dashboard['selected_from'] }} — {{ $dashboard['selected_to'] }}</small></div><a class="section-link" href="{{ route('admin.b2c.dashboard',array_merge($dashboardTabBase,['dashboard_tab'=>'sales'])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                @php
                    $overviewSeries = collect($dashboard['series'] ?? [])->take(-7)->values();
                    $overviewMaxOrders = max(1,(int)$overviewSeries->max('orders'));
                    $overviewMaxRevenue = max(1,(float)$overviewSeries->max('revenue'));
                    $overviewCount = max(1,$overviewSeries->count());
                    $overviewDenominator = max(1,$overviewCount-1);
                    $overviewStep = 560/$overviewDenominator;
                    $overviewPoints = [];
                    foreach($overviewSeries as $i=>$row){$x=50+$i*$overviewStep;$y=170-(((float)$row['revenue']/$overviewMaxRevenue)*112);$overviewPoints[]="$x,$y";}
                @endphp
                <div class="rc-chart"><svg viewBox="0 0 650 195" preserveAspectRatio="none" role="img" aria-label="{{ __('admin.b2c_dashboard.orders_revenue') }}">
                    @for($y=38;$y<=170;$y+=44)<line class="chart-grid" x1="34" y1="{{ $y }}" x2="630" y2="{{ $y }}"/>@endfor
                    @foreach($overviewSeries as $i=>$row)
                        @php $cx=50+$i*$overviewStep; $h=max(4,((float)$row['orders']/$overviewMaxOrders*94)); @endphp
                        <rect class="orders-bar" x="{{ $cx-12 }}" y="{{ 170-$h }}" width="24" height="{{ $h }}" rx="4"/>
                        <text class="axis-label" x="{{ $cx }}" y="190" text-anchor="middle">{{ $row['label'] }}</text>
                    @endforeach
                    <polyline class="revenue-line" points="{{ implode(' ',$overviewPoints) }}"/>
                    @foreach($overviewPoints as $point) @php [$cx,$cy]=explode(',',$point); @endphp <circle class="revenue-dot" cx="{{ $cx }}" cy="{{ $cy }}" r="3.5"/> @endforeach
                </svg></div>
            </article>

            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_subtitle') }}</small></div><button type="button" class="section-link" data-dashboard-assistant-open style="border:0;background:transparent;cursor:pointer">{{ __('admin.b2c_dashboard_tabs.assistant') }}</button></div>
                <div class="rc-attention-list">
                    @forelse($merchantAttention->take(3) as $row)
                        @php
                            $action=(string)data_get($row,'recommendation.action','healthy');
                            $stateClass=$action==='reorder_now'?'now':($action==='reorder_soon'?'soon':($action==='healthy'?'ok':'info'));
                            $actionKey=in_array($action,['reorder_now','reorder_soon','do_not_reorder','missing_mapping','invalid_mapping','ambiguous_mapping','insufficient_data','healthy'],true)?$action:'review_needed';
                        @endphp
                        <a class="rc-attention-row" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">
                            <span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }} · {{ __('admin.b2c_dashboard.merchant_intelligence.metrics.available') }} {{ number_format((float)data_get($row,'inventory.available',0),1) }}</small></span>
                            <span class="rc-state {{ $stateClass }}">{{ __('admin.b2c_dashboard.merchant_intelligence.actions.'.$actionKey) }}</span>
                        </a>
                    @empty
                        <div class="rc-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_attention') }}</div>
                    @endforelse
                </div>
            </article>
        </div>

        <div class="rc-grid rc-overview-bottom">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.recent_orders') }}</h2></div><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'orders','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                <div class="rc-compact-list">
                    @forelse(collect($dashboard['recent_orders'] ?? [])->take(3) as $order)
                        <div class="rc-list-row"><span><strong>{{ $order['number'] }} · {{ $order['customer'] }}</strong><small>{{ $order['items'] }} {{ __('admin.b2c_dashboard.items') }} · {{ __('admin.b2c_dashboard.status.'.$order['status']) }}</small></span><b class="rc-list-value">{{ $order['currency'] }} {{ \App\Support\DisplayNumber::money((float)$order['amount'], $order['currency']) }}</b></div>
                    @empty <div class="rc-empty">{{ __('admin.b2c_dashboard.no_orders') }}</div> @endforelse
                </div>
            </article>
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.low_stock') }}</h2></div><a class="section-link" href="{{ route('admin.b2c.dashboard',array_merge($dashboardTabBase,['dashboard_tab'=>'inventory'])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                <div class="rc-compact-list">
                    @forelse(collect($dashboard['low_stock'] ?? [])->take(3) as $product)
                        <div class="rc-list-row"><span><strong>{{ $product['name'] }}</strong><small>{{ $product['sku'] }}</small></span><b class="rc-list-value">{{ number_format((float)$product['available'],0) }} {{ __('admin.b2c_dashboard.remaining') }}</b></div>
                    @empty <div class="rc-empty">{{ __('admin.b2c_dashboard.no_low_stock') }}</div> @endforelse
                </div>
            </article>
        </div>
    </div>
    @endif

    @if($dashboardTab === 'sales')
    <div class="dashboard-pane dashboard-pane--sales active" data-dashboard-pane="sales">
        @php
            $salesKpis = [
                ['key'=>'active_users','label'=>'active_users'],
                ['key'=>'orders','label'=>'orders'],
                ['key'=>'revenue','label'=>'revenue'],
                ['key'=>'products_sold','label'=>'products_sold'],
            ];
        @endphp
        <div class="rc-kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
            @foreach($salesKpis as $item)
                @php $metric=$dashboard['kpis'][$item['key']]; @endphp
                <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.'.$item['label']) }}</span><b class="rc-kpi-value">@if($item['key']==='revenue'){{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)$metric['value'], $dashboard['currency']) }}@else{{ number_format((float)$metric['value'],0) }}@endif</b><small class="rc-kpi-help">{{ $item['key']==='active_users' ? __('admin.b2c_dashboard.active_window',['minutes'=>$metric['window_minutes']]) : __('admin.b2c_dashboard.vs_previous') }}</small></article>
            @endforeach
        </div>

        <div class="rc-grid rc-sales-main">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.orders_revenue') }}</h2><small>{{ $dashboard['selected_from'] }} — {{ $dashboard['selected_to'] }}</small></div></div>
                @php
                    $salesSeries=collect($dashboard['series'] ?? [])->values();$salesMaxOrders=max(1,(int)$salesSeries->max('orders'));$salesMaxRevenue=max(1,(float)$salesSeries->max('revenue'));$salesCount=max(1,$salesSeries->count());$salesDen=max(1,$salesCount-1);$salesStep=560/$salesDen;$salesEvery=max(1,(int)ceil($salesCount/7));$salesPoints=[];
                    foreach($salesSeries as $i=>$row){$x=50+$i*$salesStep;$y=170-(((float)$row['revenue']/$salesMaxRevenue)*112);$salesPoints[]="$x,$y";}
                @endphp
                <div class="rc-chart"><svg viewBox="0 0 650 195" preserveAspectRatio="none" role="img" aria-label="{{ __('admin.b2c_dashboard.orders_revenue') }}">
                    @for($y=38;$y<=170;$y+=44)<line class="chart-grid" x1="34" y1="{{ $y }}" x2="630" y2="{{ $y }}"/>@endfor
                    @foreach($salesSeries as $i=>$row)
                        @php $cx=50+$i*$salesStep;$bw=max(8,min(24,$salesStep*.4));$h=max(4,((float)$row['orders']/$salesMaxOrders*94)); @endphp
                        <rect class="orders-bar" x="{{ $cx-($bw/2) }}" y="{{ 170-$h }}" width="{{ $bw }}" height="{{ $h }}" rx="4"/>
                        @if($i % $salesEvery === 0 || $i === $salesCount-1)<text class="axis-label" x="{{ $cx }}" y="190" text-anchor="middle">{{ $row['label'] }}</text>@endif
                    @endforeach
                    <polyline class="revenue-line" points="{{ implode(' ',$salesPoints) }}"/>
                </svg></div>
            </article>

            @php
                $dist=$dashboard['distribution'];$total=array_sum($dist);$p1=$total?($dist['processing']/$total*100):0;$p2=$total?($dist['out_for_delivery']/$total*100):0;$p3=$total?($dist['delivered']/$total*100):0;
                $distributionGradient="conic-gradient(var(--foodex-blue) 0 {$p1}%, var(--foodex-orange) {$p1}% ".($p1+$p2)."%, var(--foodex-green) ".($p1+$p2)."% ".($p1+$p2+$p3)."%, var(--foodex-red) ".($p1+$p2+$p3)."% 100%)";
            @endphp
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.distribution') }}</h2></div></div>
                <div class="rc-mini-donut-wrap">
                    <div class="rc-mini-donut" style="background:{{ $distributionGradient }}"></div>
                    <div class="rc-status-list">
                        @foreach(['processing'=>'var(--foodex-blue)','out_for_delivery'=>'var(--foodex-orange)','delivered'=>'var(--foodex-green)','cancelled'=>'var(--foodex-red)'] as $status=>$color)
                        <div class="rc-status-line"><span><i class="dot" style="background:{{ $color }}"></i>{{ __('admin.b2c_dashboard.status.'.$status) }}</span><b>{{ $total?round($dist[$status]/$total*100):0 }}%</b></div>
                        @endforeach
                    </div>
                </div>
            </article>
        </div>

        <div class="rc-grid rc-sales-bottom">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.recent_orders') }}</h2></div><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'orders','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.view_all') }}</a></div>
                <div class="rc-compact-list">
                    @forelse(collect($dashboard['recent_orders'] ?? [])->take(4) as $order)
                    <div class="rc-list-row"><span><strong>{{ $order['number'] }} · {{ $order['customer'] }}</strong><small>{{ __('admin.b2c_dashboard.status.'.$order['status']) }} · {{ $order['items'] }} {{ __('admin.b2c_dashboard.items') }}</small></span><b class="rc-list-value">{{ $order['currency'] }} {{ \App\Support\DisplayNumber::money((float)$order['amount'], $order['currency']) }}</b></div>
                    @empty <div class="rc-empty">{{ __('admin.b2c_dashboard.no_orders') }}</div> @endforelse
                </div>
            </article>
        </div>
    </div>
    @endif

    @if($dashboardTab === 'inventory')
    <div class="dashboard-pane dashboard-pane--inventory active" data-dashboard-pane="inventory">
        <div class="rc-kpis">
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['reorder_now'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now_help') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_soon') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['reorder_soon'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_soon_help') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['blocked'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention_help') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.expected_spend') }}</span><b class="rc-kpi-value">{{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)($merchantSummary['expected_reorder_spend'] ?? 0), $dashboard['currency']) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_workspace.modules.incoming_orders') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.do_not_reorder') }}</span><b class="rc-kpi-value">{{ number_format((int)($merchantSummary['do_not_reorder'] ?? 0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.merchant_intelligence.summary.do_not_reorder_help') }}</small></article>
        </div>

        <div class="rc-grid rc-inventory-main">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_subtitle') }}</small></div><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.merchant_intelligence.open_inventory') }}</a></div>
                <div class="rc-inventory-list">
                    @forelse($merchantAttention->take(6) as $row)
                        @php
                            $action=(string)data_get($row,'recommendation.action','healthy');
                            $stateClass=$action==='reorder_now'?'now':($action==='reorder_soon'?'soon':($action==='healthy'?'ok':'info'));
                            $actionKey=in_array($action,['reorder_now','reorder_soon','do_not_reorder','missing_mapping','invalid_mapping','ambiguous_mapping','insufficient_data','healthy'],true)?$action:'review_needed';
                        @endphp
                        <div class="rc-inventory-row">
                            <div><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small><br><span class="rc-state {{ $stateClass }}">{{ __('admin.b2c_dashboard.merchant_intelligence.actions.'.$actionKey) }}</span></div>
                            <div class="rc-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.available') }}</span><b>{{ number_format((float)data_get($row,'inventory.available',0),1) }}</b></div>
                            <div class="rc-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.days_cover') }}</span><b>{{ data_get($row,'inventory.days_of_cover')===null?'—':number_format((float)data_get($row,'inventory.days_of_cover'),1) }}</b></div>
                            <div class="rc-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.expected_cost') }}</span><b>{{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)data_get($row,'recommendation.expected_cost',0), $dashboard['currency']) }}</b></div>
                            <a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.merchant_intelligence.open_product') }}</a>
                        </div>
                    @empty <div class="rc-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_attention') }}</div> @endforelse
                </div>
            </article>

            <article class="rc-card rc-action-card">
                <div>
                    <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.purchase_plan_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.purchase_plan_subtitle') }}</small></div></div>
                    <p>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.cart_safety_note') }}</p>
                    <div class="rc-compact-list">
                        <div class="rc-list-row"><span><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.expected_spend') }}</strong></span><b class="rc-list-value">{{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)($merchantSummary['expected_reorder_spend'] ?? 0), $dashboard['currency']) }}</b></div>
                        <div class="rc-list-row"><span><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now') }}</strong></span><b class="rc-list-value">{{ number_format((int)($merchantSummary['reorder_now'] ?? 0)) }}</b></div>
                    </div>
                </div>
                <div class="rc-actions">
                    <button type="button" class="foodex-action-primary" data-dashboard-assistant-open data-assistant-anchor="suggested-purchase-plan">{{ __('admin.b2c_dashboard.merchant_intelligence.open_purchase_plan') }}</button>
                    <a class="foodex-action-secondary" href="{{ route('admin.b2c.module',array_merge(['module'=>'incoming_orders','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_workspace.modules.incoming_orders') }}</a>
                </div>
            </article>
        </div>
    </div>
    @endif

    @if($dashboardTab === 'customers')
    <div class="dashboard-pane dashboard-pane--customers active" data-dashboard-pane="customers">
        <div class="rc-kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.active_users') }}</span><b class="rc-kpi-value">{{ number_format((int)data_get($dashboard,'kpis.active_users.value',0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.active_window',['minutes'=>data_get($dashboard,'kpis.active_users.window_minutes',15)]) }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_workspace.modules.customers') }}</span><b class="rc-kpi-value">{{ number_format($recentCustomers) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.recent_orders') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.orders') }}</span><b class="rc-kpi-value">{{ number_format((int)data_get($dashboard,'kpis.orders.value',0)) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_workspace.modules.orders') }}</small></article>
            <article class="rc-kpi"><span class="rc-kpi-label">{{ __('admin.b2c_dashboard.kpis.revenue') }}</span><b class="rc-kpi-value">{{ $dashboard['currency'] }} {{ \App\Support\DisplayNumber::money((float)data_get($dashboard,'kpis.revenue.value',0), $dashboard['currency']) }}</b><small class="rc-kpi-help">{{ __('admin.b2c_dashboard.vs_previous') }}</small></article>
        </div>

        <div class="rc-grid rc-customer-actions">
            <article class="rc-card rc-action-card"><div><div class="rc-card-head"><h2>{{ __('admin.b2c_workspace.modules.customers') }}</h2></div><p>{{ app()->getLocale()==='ar'?'إدارة ملفات العملاء وبيانات الاتصال من شاشة واحدة.':'Manage customer profiles and contact details from one operational screen.' }}</p></div><a class="foodex-action-primary" href="{{ route('admin.b2c.module',array_merge(['module'=>'customers','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_workspace.modules.customers') }}</a></article>
            <article class="rc-card rc-action-card"><div><div class="rc-card-head"><h2>{{ __('admin.b2c_workspace.modules.orders') }}</h2></div><p>{{ app()->getLocale()==='ar'?'راجع طلبات عملاء التجزئة وحالتها بدون مغادرة نطاق المتجر.':'Review retail customer orders and their status without leaving the store scope.' }}</p></div><a class="foodex-action-primary" href="{{ route('admin.b2c.module',array_merge(['module'=>'orders','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_workspace.modules.orders') }}</a></article>
            <article class="rc-card rc-action-card"><div><div class="rc-card-head"><h2>{{ __('admin.b2c_workspace.modules.promotions') }}</h2></div><p>{{ app()->getLocale()==='ar'?'انتقل للعروض والتواصل لزيادة التحويل والعودة للشراء.':'Open promotions and customer communication to improve conversion and repeat purchase.' }}</p></div><a class="foodex-action-primary" href="{{ route('admin.b2c.module',array_merge(['module'=>'promotions','store_id'=>$storeId],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_workspace.modules.promotions') }}</a></article>
        </div>
    </div>
    @endif

    @if($dashboardTab === 'analytics')
    <div class="dashboard-pane dashboard-pane--analytics active" data-dashboard-pane="analytics">
        <div class="rc-grid rc-analytics">
            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.retail_vs_wholesale_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.retail_vs_wholesale_subtitle') }}</small></div></div>
                @php $compareMax=max(1,(float)$merchantCompare->max(fn($row)=>max((float)($row['retail_sales']??0),(float)($row['wholesale_purchases']??0)))); @endphp
                @if($merchantCompare->isNotEmpty())
                <div class="rc-compare">
                    @foreach($merchantCompare as $day)
                    <div class="rc-day" title="{{ $day['label'] }}"><i style="height:{{ max(2,round((float)$day['retail_sales']/$compareMax*112)) }}px"></i><i style="height:{{ max(2,round((float)$day['wholesale_purchases']/$compareMax*112)) }}px"></i><small>{{ $day['label'] }}</small></div>
                    @endforeach
                </div>
                @else <div class="rc-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_activity') }}</div> @endif
            </article>

            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk_subtitle') }}</small></div></div>
                <div class="rc-mini-donut-wrap"><div class="foodex-viz-donut" style="--foodex-viz-p1:{{ $riskP1 }}%;--foodex-viz-p2:{{ $riskP2 }}%;--foodex-viz-p3:{{ $riskP3 }}%"></div><div class="rc-status-list"><div class="rc-status-line"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.critical_low') }}</span><b>{{ $riskCritical }}</b></div><div class="rc-status-line"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.balanced') }}</span><b>{{ $riskHealthy }}</b></div><div class="rc-status-line"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.overstock') }}</span><b>{{ $riskOverstock }}</b></div><div class="rc-status-line"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.insufficient_data_label') }}</span><b>{{ $riskUnknown }}</b></div></div></div>
            </article>

            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.reorder_spend_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.reorder_spend_subtitle') }}</small></div></div>
                <div class="rc-bars">
                    @foreach([['reorder_now','danger'],['reorder_soon','warning'],['availability_limited','info']] as [$key,$class])
                        @php $value=(float)($merchantSpend[$key]??0); @endphp
                        <div class="rc-bar {{ $class }}"><span>{{ $key==='availability_limited'?__('admin.b2c_dashboard.merchant_intelligence.availability_limited'):__('admin.b2c_dashboard.merchant_intelligence.summary.'.$key) }}</span><span class="rc-track"><i style="width:{{ round($value/$spendMax*100,1) }}%"></i></span><b>{{ \App\Support\DisplayNumber::money($value,$dashboard['currency']) }}</b></div>
                    @endforeach
                </div>
            </article>

            <article class="rc-card">
                <div class="rc-card-head"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.inventory_aging_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.inventory_aging_subtitle') }}</small></div></div>
                <div class="rc-bars">
                    @foreach($agingLabels as $key=>$label)
                        @php $qty=(float)data_get($merchantAging,$key.'.quantity',0); @endphp
                        <div class="rc-bar"><span>{{ $label }}</span><span class="rc-track"><i style="width:{{ round($qty/$agingMax*100,1) }}%"></i></span><b>{{ number_format($qty,1) }}</b></div>
                    @endforeach
                </div>
            </article>
        </div>
    </div>
    @endif
</section>
