<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.b2b_workspace.title') }} · FOODEX</title>
@include('admin._brand-components')
@if($module === 'dashboard' && $canViewDriverTracking)
<link rel="stylesheet" href="{{ asset('assets/leaflet/1.9.4/leaflet.css') }}">
<link rel="stylesheet" href="{{ asset('assets/admin/driver-live-map.css') }}">
@endif
<style>
*{box-sizing:border-box}body{margin:0;overflow-x:hidden}.layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}.sidebar{grid-column:2;grid-row:1;direction:rtl;padding:var(--foodex-space-5);position:sticky;inset-block-start:0;height:100vh}.main{grid-column:1;grid-row:1;direction:rtl;min-width:0;width:100%;max-width:none!important;padding:var(--foodex-space-8)}html[dir=ltr] .layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}html[dir=ltr] .sidebar{grid-column:1;direction:ltr}html[dir=ltr] .main{grid-column:2;direction:ltr}.headline{margin-bottom:var(--foodex-space-6)}.headline h1{margin:var(--foodex-space-1) 0 0}.headline .muted{max-width:760px}.muted{color:var(--foodex-muted)}.cards{display:grid;grid-template-columns:repeat(var(--foodex-card-columns,4),minmax(0,1fr));gap:12px;margin-bottom:var(--foodex-space-5)}.metric-card{position:relative;overflow:hidden;padding:14px!important;min-height:112px;display:grid;grid-template-columns:minmax(0,1fr) 40px;gap:10px;align-items:center;background:linear-gradient(145deg,#fff,#fbfcfd)!important;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}.metric-card:hover{transform:translateY(-2px);box-shadow:var(--foodex-shadow)!important;border-color:#cfe5d6!important}.metric-card:before{content:"";position:absolute;inset-inline-start:0;inset-block:0;width:4px;background:var(--foodex-green)}.metric-card:nth-child(2n):before{background:var(--foodex-orange)}.metric-card-copy{min-width:0}.metric-card strong{display:block;font-size:11px;color:var(--foodex-muted);font-weight:var(--foodex-font-weight-bold);line-height:1.3;min-height:29px}.metric-card p{font-family:var(--foodex-font-en);font-size:clamp(1.25rem,1.55vw,1.7rem);font-weight:var(--foodex-font-weight-bold);line-height:1.1;margin:8px 0 0;color:var(--foodex-ink);white-space:nowrap}.metric-card-icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.metric-card:nth-child(2n) .metric-card-icon{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.metric-card-icon .foodex-svg-icon{width:21px;height:21px}.panel{margin-top:var(--foodex-space-4);padding:var(--foodex-space-5)}.workspace-panel{box-shadow:var(--foodex-shadow)}.toolbar{display:flex;justify-content:space-between;gap:var(--foodex-space-4);align-items:flex-start;margin-bottom:var(--foodex-space-4)}.toolbar>div:first-child{max-width:520px}.links{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center}.links a{min-height:var(--foodex-control-height);display:inline-flex;align-items:center;padding:0 var(--foodex-space-3);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);text-decoration:none;background:var(--foodex-surface);color:var(--foodex-ink)}.links a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:#c9e7d3}.links a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green);box-shadow:0 8px 20px rgba(21,138,58,.14)}.table-wrap{overflow:auto;border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-sm)}.data{min-width:760px}.data th,.data td{vertical-align:middle}.state{display:inline-flex;align-items:center;gap:6px;font-weight:var(--foodex-font-weight-medium)}.state:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green)}.state.off:before{background:#98a2b3}.badge{display:inline-flex;align-items:center;min-height:26px;border-radius:999px;padding:3px 9px;background:var(--foodex-orange-soft);color:var(--foodex-orange);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}.badge.active,.badge.delivered{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.suspended,.badge.denied,.badge.cancelled{background:#fff0f0;color:var(--foodex-red)}.workspace-inline-form{padding:var(--foodex-space-4);margin-bottom:var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}.workspace-inline-form input,.workspace-inline-form select{min-width:150px}.empty-state{display:grid;place-items:center;min-height:160px;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;padding:var(--foodex-space-6);color:var(--foodex-muted)}@media(max-width:1279px){.cards{grid-template-columns:repeat(4,minmax(0,1fr))}.toolbar{flex-direction:column}}@media(max-width:1023px){.layout,html[dir=ltr] .layout{grid-template-columns:1fr}.sidebar,html[dir=ltr] .sidebar{grid-column:1;grid-row:1;position:relative;height:auto;max-height:320px;overflow:auto}.main,html[dir=ltr] .main{grid-column:1;grid-row:2;padding:var(--foodex-space-6)!important}}@media(max-width:767px){.main,html[dir=ltr] .main{padding:var(--foodex-space-4)!important}.cards{grid-template-columns:1fr}.toolbar{align-items:stretch}.links a{flex:1 1 auto;justify-content:center}.workspace-inline-form{align-items:stretch}.workspace-inline-form input,.workspace-inline-form select,.workspace-inline-form button{width:100%}.data{min-width:680px}}

/* B2B dashboard reference: 841x564 source ratio translated to live responsive admin geometry. */
.b2b-reference-dashboard{direction:ltr;display:grid;gap:12px;width:100%}.b2b-ref-header-tools{direction:rtl;display:flex;justify-content:flex-end}
.b2b-ref-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.b2b-ref-card{direction:rtl;background:#fff;border:1px solid #e7edf3;border-radius:12px;box-shadow:0 2px 10px rgba(16,24,40,.035);min-width:0}
.b2b-ref-kpi{min-height:96px;padding:14px 16px;display:grid;grid-template-columns:minmax(0,1fr) 48px;gap:12px;align-items:center}
.b2b-ref-kpi-copy{min-width:0}.b2b-ref-kpi-label{font-size:12px;color:#667085;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.b2b-ref-kpi-value{margin-top:3px;font-family:var(--foodex-font-en);font-size:22px;line-height:1.1;font-weight:700;color:#172033;white-space:nowrap}
.b2b-ref-kpi-delta{margin-top:7px;font-family:var(--foodex-font-en);font-size:11px;font-weight:700;color:#16a34a;display:flex;align-items:center;gap:4px}
.b2b-ref-kpi-delta.down{color:#ef4444}.b2b-ref-kpi-delta.neutral{color:#98a2b3}
.b2b-ref-kpi-icon{width:48px;height:48px;border-radius:10px;display:grid;place-items:center}
.b2b-ref-kpi-icon .foodex-svg-icon{width:25px;height:25px}
.b2b-ref-kpi:nth-child(1) .b2b-ref-kpi-icon{background:#e9fbef;color:#13984b}
.b2b-ref-kpi:nth-child(2) .b2b-ref-kpi-icon{background:#ebf5ff;color:#2d86dc}
.b2b-ref-kpi:nth-child(3) .b2b-ref-kpi-icon{background:#fff5e8;color:#f59e0b}
.b2b-ref-kpi:nth-child(4) .b2b-ref-kpi-icon{background:#fff6e9;color:#f59e0b}
.b2b-ref-middle{display:grid;grid-template-columns:repeat(var(--dashboard-primary-columns,2),minmax(0,1fr));gap:12px}
.b2b-ref-panel{padding:15px 16px}
.b2b-ref-panel-head{direction:ltr;display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.b2b-ref-panel-head h2{direction:rtl;text-align:start;margin:0;font-size:15px;line-height:1.2;font-weight:700}
.b2b-ref-filter{height:34px;min-height:34px!important;border:1px solid #e3e8ef;border-radius:8px;background:#fff;color:#475467;padding:0 10px;font-size:11px;font-weight:700}.b2b-date-range{display:flex;align-items:end;gap:6px;flex-wrap:wrap}.b2b-date-range label{display:grid;gap:3px;font-size:9px;font-weight:700;color:#667085}.b2b-date-range input{height:34px;min-height:34px!important;min-width:126px;padding:0 8px;font-family:var(--foodex-font-en);font-size:10px}.b2b-date-range .foodex-filter-action{min-height:34px;height:34px;padding-inline:12px;font-size:10px}
.b2b-ref-chart-wrap{position:relative;height:222px;direction:ltr}
.b2b-ref-chart-wrap svg{display:block;width:100%;height:194px;overflow:visible}
.b2b-ref-chart-grid{stroke:#edf1f5;stroke-width:1}
.b2b-ref-chart-line{fill:none;stroke:#16a34a;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.b2b-ref-chart-area{fill:url(#b2bRevenueGradient)}
.b2b-ref-chart-dot{fill:#fff;stroke:#16a34a;stroke-width:2.4}
.b2b-ref-chart-labels{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;margin-top:-2px;font-family:var(--foodex-font-en);font-size:9px;color:#7b8798;text-align:center}
.b2b-ref-axis{font-family:var(--foodex-font-en);font-size:9px;fill:#7b8798}
.b2b-ref-tooltip{fill:#175c35}.b2b-ref-tooltip-text{font-family:var(--foodex-font-en);font-size:9px;font-weight:700;fill:#fff}
.b2b-ref-donut-body{direction:ltr;min-height:222px;display:grid;grid-template-columns:minmax(126px,.95fr) minmax(145px,1.05fr);gap:16px;align-items:center}
.b2b-ref-donut{width:142px;height:142px;border-radius:50%;position:relative;margin:auto}
.b2b-ref-donut:after{content:"";position:absolute;inset:34px;border-radius:50%;background:#fff}
.b2b-ref-legend{direction:ltr;display:grid;gap:16px}.b2b-ref-legend-row{direction:ltr;display:grid;grid-template-columns:10px minmax(0,1fr) auto;gap:8px;align-items:center;font-size:12px}
.b2b-ref-legend-dot{width:9px;height:9px;border-radius:50%}.b2b-ref-legend-row strong{font-family:var(--foodex-font-en);font-size:11px}
.b2b-ref-bottom{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(260px,1fr) minmax(230px,.82fr);gap:12px}
.b2b-ref-bottom .b2b-ref-panel{min-height:264px}
.b2b-ref-orders{direction:ltr;width:100%;border-collapse:collapse;font-size:11px}.b2b-ref-orders th{direction:rtl;padding:9px 7px;background:#f8fafc;color:#667085;font-size:10px;font-weight:700;text-align:start;border-block:1px solid #edf1f5}.b2b-ref-orders td{direction:rtl;padding:10px 7px;border-bottom:1px solid #edf1f5;vertical-align:middle}.b2b-ref-orders tr:last-child td{border-bottom:0}
.b2b-ref-order-number{font-family:var(--foodex-font-en);font-weight:700;color:#344054}
.b2b-ref-status{display:inline-flex;align-items:center;justify-content:center;min-height:24px;border-radius:7px;padding:3px 8px;font-size:9px;font-weight:700;white-space:nowrap}
.b2b-ref-status.completed,.b2b-ref-status.delivered{background:#daf9e5;color:#168a46}
.b2b-ref-status.processing,.b2b-ref-status.confirmed,.b2b-ref-status.pending,.b2b-ref-status.paid,.b2b-ref-status.accepted{background:#fff3d7;color:#de8700}
.b2b-ref-status.assigned,.b2b-ref-status.picked_up,.b2b-ref-status.out_for_delivery,.b2b-ref-status.in_transit{background:#e6f4ff;color:#1f7ac6}
.b2b-ref-status.cancelled,.b2b-ref-status.refunded{background:#fff0f0;color:#dc2626}
.b2b-ref-products{display:grid}.b2b-ref-product{direction:ltr;display:grid;grid-template-columns:48px minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid #edf1f5}.b2b-ref-product:last-child{border-bottom:0}
.b2b-ref-product-thumb{width:48px;height:42px;border-radius:9px;background:#f6f8fb;border:1px solid #edf1f5;display:grid;place-items:center;overflow:hidden;color:#f59e0b}
.b2b-ref-product-thumb img{width:100%;height:100%;object-fit:contain}.b2b-ref-product-name{direction:rtl;text-align:start;font-size:11px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.b2b-ref-product-sku{font-family:var(--foodex-font-en);font-size:9px;color:#98a2b3}.b2b-ref-product-qty{font-family:var(--foodex-font-en);font-size:11px;font-weight:700;color:#475467}
.b2b-ref-alerts{display:grid}.b2b-ref-alert{direction:ltr;display:grid;grid-template-columns:42px minmax(0,1fr) 6px;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #edf1f5;color:inherit;text-decoration:none}.b2b-ref-alert:last-child{border-bottom:0}
.b2b-ref-alert-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center}.b2b-ref-alert-icon .foodex-svg-icon{width:22px;height:22px}
.b2b-ref-alert.orange .b2b-ref-alert-icon{background:#fff4dc;color:#f59e0b}.b2b-ref-alert.green .b2b-ref-alert-icon{background:#eaf9ef;color:#159447}.b2b-ref-alert-title{direction:rtl;text-align:start;display:block;font-size:11px;font-weight:700}.b2b-ref-alert-body{direction:rtl;text-align:start;display:block;margin-top:2px;font-size:9px;color:#7b8798;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.b2b-ref-alert-dot{width:6px;height:6px;border-radius:50%;background:#ef4444}
.b2b-ref-more{display:inline-flex;margin-top:8px;font-size:10px;font-weight:700;color:#2487e3;text-decoration:none}
.b2b-ref-empty{min-height:140px;display:grid;place-items:center;text-align:center;color:#98a2b3;font-size:11px}
@media(min-width:1600px){.b2b-ref-kpi{min-height:104px}.b2b-ref-chart-wrap{height:246px}.b2b-ref-chart-wrap svg{height:216px}.b2b-ref-donut-body{min-height:246px}.b2b-ref-bottom .b2b-ref-panel{min-height:286px}}
@media(max-width:1279px){.b2b-ref-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.b2b-ref-middle{grid-template-columns:1fr}.b2b-ref-bottom{grid-template-columns:1fr 1fr}.b2b-ref-bottom>.b2b-ref-panel:first-child{grid-column:1/-1}}
@media(max-width:820px){.b2b-ref-kpis,.b2b-ref-bottom{grid-template-columns:1fr}.b2b-ref-bottom>.b2b-ref-panel:first-child{grid-column:auto}.b2b-ref-donut-body{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.b2b-reference-dashboard{gap:10px}.b2b-ref-kpi{min-height:86px;padding:12px}.b2b-ref-kpi-value{font-size:19px}.b2b-ref-panel{padding:12px}.b2b-ref-chart-wrap{height:190px}.b2b-ref-chart-wrap svg{height:166px}.b2b-ref-donut-body{grid-template-columns:1fr;gap:10px}.b2b-ref-donut{width:126px;height:126px}.b2b-ref-donut:after{inset:31px}.b2b-ref-orders{min-width:560px}.b2b-ref-orders-wrap{overflow:auto}.b2b-ref-bottom .b2b-ref-panel{min-height:auto}}

</style>
</head>
<body>
<div class="layout b2b-premium-shell" data-b2b-premium="v1">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="main foodex-admin-page">
    @if($module !== 'dashboard')
    <div class="headline foodex-page-header">
        <div>
            <a class="muted" href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a>
            <h1>{{ __('admin.b2b_workspace.modules.'.$module) }}</h1>
            <div class="muted">{{ app()->getLocale()==='ar' ? 'FOODEX · إدارة الجملة ببيانات مباشرة من النظام' : 'FOODEX · Wholesale management with live server data' }}</div>
        </div>
        @include('admin._live-notifications')
    </div>
    @php
        $metricIcons = [
            'warehouses'=>'inventory',
            'clients'=>'customers',
            'products'=>'products',
            'inventory'=>'inventory',
            'orders'=>'orders',
            'drivers'=>'delivery',
            'pricing'=>'promotions',
            'finance'=>'revenue',
        ];
    @endphp
    <section class="cards" style="--foodex-card-columns:{{ min(8,max(1,count($counts))) }}" aria-label="{{ app()->getLocale()==='ar' ? 'مؤشرات إدارة الجملة' : 'B2B management metrics' }}">
        @foreach($counts as $key=>$value)
        <article class="card foodex-card metric-card">
            <div class="metric-card-copy">
                <strong>{{ __('admin.b2b_workspace.modules.'.$key) }}</strong>
                <p>{{ number_format($value) }}</p>
            </div>
            <span class="metric-card-icon">@include('admin._premium-icon',['name'=>$metricIcons[$key] ?? 'reports'])</span>
        </article>
        @endforeach
    </section>
    @endif


    @if($module === 'dashboard' && $dashboard)
    @php
        $isAr = app()->getLocale() === 'ar';
        $kpiConfig = [
            ['key'=>'sales','label'=>$isAr?'مبيعات الفترة':'Period sales','icon'=>'revenue','money'=>true],
            ['key'=>'orders','label'=>$isAr?'عدد الطلبات':'Orders','icon'=>'orders','money'=>false],
            ['key'=>'customers','label'=>$isAr?'عدد العملاء':'Customers','icon'=>'customers','money'=>false],
            ['key'=>'average','label'=>$isAr?'متوسط قيمة الطلب':'Average order value','icon'=>'storefront','money'=>true],
        ];
        $series = collect($dashboard['series'])->values();
        $maxRevenue = max(1, (float) $series->max('revenue'));
        $seriesCount = max(1, $series->count());
        $chartDenominator = max(1, $seriesCount - 1);
        $labelEvery = max(1, (int) ceil($seriesCount / 7));
        $chartPoints = $series->map(function ($point, $index) use ($maxRevenue, $chartDenominator) {
            $x = 38 + ($index * (486 / $chartDenominator));
            $y = 178 - (((float) $point['revenue'] / $maxRevenue) * 132);
            return ['x'=>round($x,1),'y'=>round($y,1),'revenue'=>(float)$point['revenue'],'label'=>$point['label']];
        })->all();
        $polyline = collect($chartPoints)->map(fn($point)=>$point['x'].','.$point['y'])->implode(' ');
        $areaPath = count($chartPoints)
            ? 'M '.$chartPoints[0]['x'].' 190 L '.collect($chartPoints)->map(fn($point)=>$point['x'].' '.$point['y'])->implode(' L ').' L '.$chartPoints[count($chartPoints)-1]['x'].' 190 Z'
            : '';
        $lastPoint = count($chartPoints) ? $chartPoints[count($chartPoints)-1] : ['x'=>524,'y'=>178,'revenue'=>0];
        $distribution = $dashboard['distribution'];
        $distributionCount = array_sum($distribution);
        $distributionTotal = max(1, $distributionCount);
        $processingPct = round(($distribution['processing'] / $distributionTotal) * 100, 1);
        $deliveryPct = round(($distribution['delivery'] / $distributionTotal) * 100, 1);
        $completedPct = round(($distribution['completed'] / $distributionTotal) * 100, 1);
        $processingEnd = $processingPct;
        $deliveryEnd = $processingPct + $deliveryPct;
        $statusLabels = [
            'pending'=>$isAr?'قيد الانتظار':'Pending',
            'confirmed'=>$isAr?'مؤكد':'Confirmed',
            'processing'=>$isAr?'قيد التجهيز':'Processing',
            'paid'=>$isAr?'مدفوع':'Paid',
            'accepted'=>$isAr?'مقبول':'Accepted',
            'assigned'=>$isAr?'تم التعيين':'Assigned',
            'picked_up'=>$isAr?'تم الاستلام':'Picked up',
            'out_for_delivery'=>$isAr?'قيد التوصيل':'Out for delivery',
            'in_transit'=>$isAr?'في الطريق':'In transit',
            'delivered'=>$isAr?'مكتمل':'Delivered',
            'completed'=>$isAr?'مكتمل':'Completed',
            'cancelled'=>$isAr?'ملغي':'Cancelled',
            'refunded'=>$isAr?'مسترد':'Refunded',
        ];
        $alertLabels = [
            'low_stock'=>$isAr?'مخزون منخفض':'Low stock',
            'new_order'=>$isAr?'طلب جديد':'New order',
            'delivered'=>$isAr?'تم التوصيل':'Delivered',
            'overdue_invoice'=>$isAr?'فاتورة متأخرة':'Overdue invoice',
        ];
    @endphp
    <section class="b2b-reference-dashboard" data-b2b-reference-dashboard="841x564">
        <div class="b2b-ref-header-tools">
            @include('admin._live-notifications')
        </div>
        <div class="b2b-ref-kpis">
            @foreach($kpiConfig as $config)
                @php
                    $kpi = $dashboard['kpis'][$config['key']];
                    $kpiValue = $config['money']
                        ? 'EGP '.number_format((float) $kpi['value'], 0)
                        : number_format((float) $kpi['value'], 0);
                    $delta = $kpi['delta'];
                    $deltaClass = $delta !== null && $delta < 0
                        ? 'down'
                        : ($delta === null || $delta == 0 ? 'neutral' : '');
                @endphp
                <article class="b2b-ref-card b2b-ref-kpi">
                    <div class="b2b-ref-kpi-copy">
                        <div class="b2b-ref-kpi-label">{{ $config['label'] }}</div>
                        <div class="b2b-ref-kpi-value">{{ $kpiValue }}</div>
                        <div class="b2b-ref-kpi-delta {{ $deltaClass }}">
                            @if($delta === null)
                                <span>—</span>
                            @elseif($delta > 0)
                                <span>↑</span><span>{{ number_format(abs($delta),1) }}%</span>
                            @elseif($delta < 0)
                                <span>↓</span><span>{{ number_format(abs($delta),1) }}%</span>
                            @else
                                <span>→</span><span>0%</span>
                            @endif
                        </div>
                    </div>
                    <div class="b2b-ref-kpi-icon">@include('admin._premium-icon',['name'=>$config['icon']])</div>
                </article>
            @endforeach
        </div>

        <div class="b2b-ref-middle" data-dashboard-primary-row style="--dashboard-primary-columns:{{ $canViewDriverTracking ? 3 : 2 }}">
            <article class="b2b-ref-card b2b-ref-panel" data-dashboard-primary-card="sales">
                <div class="b2b-ref-panel-head">
                    <h2>{{ $isAr?'المبيعات':'Sales' }}</h2>
                    <form class="b2b-date-range" method="get" action="{{ route('admin.b2b.module',['module'=>'dashboard']) }}">
                        <label>{{ $isAr?'من':'From' }}<input type="date" name="from" value="{{ request('from') }}"></label>
                        <label>{{ $isAr?'إلى':'To' }}<input type="date" name="to" value="{{ request('to') }}"></label>
                        <button class="foodex-filter-action" type="submit">{{ $isAr?'تطبيق':'Apply' }}</button>
                    </form>
                </div>
                <div class="b2b-ref-chart-wrap">
                    <svg viewBox="0 0 560 200" role="img" aria-label="{{ $isAr?'مبيعات الفترة المحددة':'Sales over the selected period' }}">
                        <defs><linearGradient id="b2bRevenueGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#33c56b" stop-opacity=".26"/><stop offset="100%" stop-color="#33c56b" stop-opacity=".02"/></linearGradient></defs>
                        @foreach([46,90,134,178] as $gridY)<line class="b2b-ref-chart-grid" x1="38" y1="{{ $gridY }}" x2="524" y2="{{ $gridY }}"/>@endforeach
                        <text class="b2b-ref-axis" x="5" y="181">0</text>
                        <text class="b2b-ref-axis" x="2" y="137">{{ number_format($maxRevenue*.33/1000,0) }}K</text>
                        <text class="b2b-ref-axis" x="2" y="93">{{ number_format($maxRevenue*.66/1000,0) }}K</text>
                        <text class="b2b-ref-axis" x="2" y="49">{{ number_format($maxRevenue/1000,0) }}K</text>
                        @if($areaPath)<path class="b2b-ref-chart-area" d="{{ $areaPath }}"/>@endif
                        @if($polyline)<polyline class="b2b-ref-chart-line" points="{{ $polyline }}"/>@endif
                        @foreach($chartPoints as $point)<circle class="b2b-ref-chart-dot" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.8"/>@endforeach
                        @if(count($chartPoints))
                            @php
                                $tooltipX = min(466, max(392, $lastPoint['x'] - 58));
                                $tooltipY = max(6, $lastPoint['y'] - 35);
                            @endphp
                            <rect class="b2b-ref-tooltip" x="{{ $tooltipX }}" y="{{ $tooltipY }}" rx="5" ry="5" width="88" height="24"/>
                            <text class="b2b-ref-tooltip-text" x="{{ $tooltipX + 44 }}" y="{{ $tooltipY + 16 }}" text-anchor="middle">EGP {{ number_format($lastPoint['revenue'],0) }}</text>
                        @endif
                    </svg>
                    <div class="b2b-ref-chart-labels" style="grid-template-columns:repeat({{ max(1,count($dashboard['series'])) }},minmax(0,1fr))">@foreach($dashboard['series'] as $index=>$point)<span>{{ ($index % $labelEvery === 0 || $index === count($dashboard['series'])-1) ? $point['label'] : '' }}</span>@endforeach</div>
                </div>
            </article>

            @if($canViewDriverTracking)
            <article class="b2b-ref-card b2b-ref-panel dashboard-live-map-card" data-dashboard-primary-card="driver-map" data-dashboard-live-driver-map>
                @include('admin._driver-live-map', [
                    'feedUrl' => $driverTrackingFeedUrl,
                    'secondaryFeedUrl' => $vanTrackingFeedUrl,
                    'trackingActor' => 'mixed',
                    'liveMapMode' => 'compact',
                    'showFilters' => false,
                    'showList' => false,
                    'showSummary' => true,
                    'pollMs' => 5000,
                    'ctaUrl' => $driverTrackingPageUrl,
                ])
            </article>
            @endif

            <article class="b2b-ref-card b2b-ref-panel" data-dashboard-primary-card="order-distribution">
                <div class="b2b-ref-panel-head"><h2>{{ $isAr?'توزيع الطلبات':'Order distribution' }}</h2></div>
                <div class="b2b-ref-donut-body">
                    <div class="b2b-ref-donut" style="background:{{ $distributionCount > 0 ? 'conic-gradient(#13984b 0 '.$processingEnd.'%,#73d99b '.$processingEnd.'% '.$deliveryEnd.'%,#2d86dc '.$deliveryEnd.'% 100%)' : '#edf1f5' }}"></div>
                    <div class="b2b-ref-legend">
                        <div class="b2b-ref-legend-row"><span class="b2b-ref-legend-dot" style="background:#13984b"></span><span>{{ $isAr?'قيد التجهيز':'Processing' }}</span><strong>{{ $processingPct }}%</strong></div>
                        <div class="b2b-ref-legend-row"><span class="b2b-ref-legend-dot" style="background:#73d99b"></span><span>{{ $isAr?'قيد التوصيل':'In delivery' }}</span><strong>{{ $deliveryPct }}%</strong></div>
                        <div class="b2b-ref-legend-row"><span class="b2b-ref-legend-dot" style="background:#2d86dc"></span><span>{{ $isAr?'مكتمل':'Completed' }}</span><strong>{{ $completedPct }}%</strong></div>
                    </div>
                </div>
            </article>
        </div>

        <div class="b2b-ref-bottom">
            <article class="b2b-ref-card b2b-ref-panel">
                <div class="b2b-ref-panel-head"><h2>{{ $isAr?'أحدث الطلبات':'Latest orders' }}</h2></div>
                @if(count($dashboard['recent_orders']))
                <div class="b2b-ref-orders-wrap">
                    <table class="b2b-ref-orders" data-pagination-exempt="bounded-dashboard-snapshot">
                        <thead><tr><th>#</th><th>{{ $isAr?'رقم الطلب':'Order' }}</th><th>{{ $isAr?'العميل':'Customer' }}</th><th>{{ $isAr?'مخزن الصرف':'Source warehouse' }}</th><th>{{ $isAr?'المبلغ':'Amount' }}</th><th>{{ $isAr?'الحالة':'Status' }}</th></tr></thead>
                        <tbody>
                        @foreach($dashboard['recent_orders'] as $index=>$order)
                            <tr>
                                <td>{{ $index+1 }}</td>
                                <td class="b2b-ref-order-number">#{{ $order['number'] }}</td>
                                <td>{{ $order['customer'] }}</td>
                                <td>{{ $order['warehouse'] ?: ($isAr?'غير محدد':'Not set') }}</td>
                                <td class="foodex-number">{{ $order['currency'] }} {{ number_format($order['amount'],0) }}</td>
                                <td><span class="b2b-ref-status {{ $order['status'] }}">{{ $statusLabels[$order['status']] ?? $order['status'] }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="b2b-ref-empty">{{ $isAr ? 'لا توجد طلبات حتى الآن' : 'No orders yet' }}</div>
                @endif
                <a class="b2b-ref-more" href="{{ route('admin.b2b.module',['module'=>'orders']) }}">{{ $isAr?'عرض كل الطلبات':'View all orders' }}</a>
            </article>

            <article class="b2b-ref-card b2b-ref-panel">
                <div class="b2b-ref-panel-head"><h2>{{ $isAr?'أكثر المنتجات مبيعًا':'Top-selling products' }}</h2><span class="muted" style="font-size:9px">{{ $isAr?'الكمية المباعة':'Sold qty' }}</span></div>
                @if(count($dashboard['top_products']))
                <div class="b2b-ref-products">
                    @foreach($dashboard['top_products'] as $product)
                    <div class="b2b-ref-product">
                        <div class="b2b-ref-product-thumb">
                            @if($product['image'])
                                <img src="{{ asset($product['image']) }}" alt="{{ $product['name'] }}">
                            @else
                                @include('admin._premium-icon',['name'=>'products'])
                            @endif
                        </div>
                        <div style="min-width:0"><div class="b2b-ref-product-name">{{ $product['name'] }}</div><div class="b2b-ref-product-sku">{{ $product['sku'] }}</div></div>
                        <div class="b2b-ref-product-qty">{{ number_format($product['quantity'],0) }}</div>
                    </div>
                    @endforeach
                </div>
                @else
                    <div class="b2b-ref-empty">{{ $isAr ? 'لا توجد مبيعات منتجات في الفترة المحددة' : 'No product sales in the selected period' }}</div>
                @endif
            </article>

            <article class="b2b-ref-card b2b-ref-panel">
                <div class="b2b-ref-panel-head"><h2>{{ $isAr?'إشعارات وتنبيهات':'Alerts & notifications' }}</h2><span style="width:6px;height:6px;border-radius:50%;background:#ef4444"></span></div>
                @if(count($dashboard['alerts']))
                <div class="b2b-ref-alerts">
                    @foreach($dashboard['alerts'] as $alert)
                    <a class="b2b-ref-alert {{ $alert['tone'] }}" href="{{ $alert['url'] }}">
                        <span class="b2b-ref-alert-icon">@include('admin._premium-icon',['name'=>$alert['icon']])</span>
                        <span style="min-width:0"><span class="b2b-ref-alert-title">{{ $alertLabels[$alert['title']] ?? $alert['title'] }}</span><span class="b2b-ref-alert-body">{{ $alert['body'] }}</span></span>
                        <span class="b2b-ref-alert-dot"></span>
                    </a>
                    @endforeach
                </div>
                @else
                    <div class="b2b-ref-empty">{{ $isAr ? 'لا توجد تنبيهات تشغيلية' : 'No operational alerts' }}</div>
                @endif
            </article>
        </div>
    </section>
    @endif

    @if($moduleData)
    @php
      $labels=app()->getLocale()==='ar'
      ? ['number'=>'رقم الطلب','client'=>'العميل','store'=>'الفرع','status'=>'الحالة','amount'=>'الإجمالي','created'=>'الإنشاء','code'=>'الكود','name'=>'الاسم','products'=>'المنتجات','orders'=>'الطلبات','company'=>'الشركة','email'=>'البريد','phone'=>'الهاتف','tax_number'=>'الرقم الضريبي','sku'=>'رمز المنتج','price'=>'السعر','available'=>'المتاح','actions'=>'إجراءات','availability'=>'التوفر','active'=>'نشط','assignments'=>'التعيينات','driver'=>'السائق','assignment_status'=>'حالة التعيين','tier'=>'شريحة السعر','product'=>'المنتج','unit_price'=>'سعر الوحدة','minimum_quantity'=>'الحد الأدنى','revenue'=>'الإيراد','average'=>'متوسط الطلب','setting'=>'الإعداد','value'=>'القيمة','warehouse'=>'المخزن','quantity'=>'الكمية','reserved'=>'المحجوز','invoice'=>'الفاتورة','paid'=>'المدفوع','balance'=>'الرصيد','issued_at'=>'تاريخ الإصدار','due'=>'الاستحقاق']
      : ['number'=>'Order','client'=>'Client','store'=>'Store','status'=>'Status','amount'=>'Amount','created'=>'Created','code'=>'Code','name'=>'Name','products'=>'Products','orders'=>'Orders','company'=>'Company','email'=>'Email','phone'=>'Phone','tax_number'=>'Tax number','sku'=>'SKU','price'=>'Price','available'=>'Available','actions'=>'Actions','availability'=>'Availability','active'=>'Active','assignments'=>'Assignments','driver'=>'Driver','assignment_status'=>'Assignment status','tier'=>'Price tier','product'=>'Product','unit_price'=>'Unit price','minimum_quantity'=>'Minimum quantity','revenue'=>'Revenue','average'=>'Average order','setting'=>'Setting','value'=>'Value','warehouse'=>'Warehouse','quantity'=>'Quantity','reserved'=>'Reserved','invoice'=>'Invoice','paid'=>'Paid','balance'=>'Balance','issued_at'=>'Issued','due'=>'Due'];
      $orderStateLabels=[
        'pending'=>app()->getLocale()==='ar'?'قيد الانتظار':'Pending',
        'confirmed'=>app()->getLocale()==='ar'?'مؤكد':'Confirmed',
        'preparing'=>app()->getLocale()==='ar'?'قيد التجهيز':'Preparing',
        'ready'=>app()->getLocale()==='ar'?'جاهز':'Ready',
        'assigned'=>app()->getLocale()==='ar'?'تم التعيين':'Assigned',
        'picked_up'=>app()->getLocale()==='ar'?'تم الاستلام':'Picked up',
        'out_for_delivery'=>app()->getLocale()==='ar'?'قيد التوصيل':'Out for delivery',
        'in_transit'=>app()->getLocale()==='ar'?'في الطريق':'In transit',
        'delivered'=>app()->getLocale()==='ar'?'تم التسليم':'Delivered',
        'completed'=>app()->getLocale()==='ar'?'مكتمل':'Completed',
        'failed'=>app()->getLocale()==='ar'?'تعذر التسليم':'Failed',
        'cancelled'=>app()->getLocale()==='ar'?'ملغي':'Cancelled',
        'refunded'=>app()->getLocale()==='ar'?'مسترد':'Refunded',
        'unassigned'=>app()->getLocale()==='ar'?'غير معين':'Unassigned',
      ];
      $workspaceStatusLabels=$orderStateLabels+[
        'active'=>__('admin.b2b_workspace.account_statuses.active'),
        'suspended'=>__('admin.b2b_workspace.account_statuses.suspended'),
        'denied'=>__('admin.b2b_workspace.account_statuses.denied'),
      ];
    @endphp
    @if(session('status'))<div class="panel" style="border-color:#b7dfc4;background:var(--foodex-green-soft);color:var(--foodex-green-dark)">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" style="border-color:#ffd0a6;background:var(--foodex-orange-soft)"><strong>{{ app()->getLocale()==='ar'?'تعذر تنفيذ العملية':'Action could not be completed' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="panel workspace-panel">
      <div class="toolbar">
        <div><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><div class="muted">{{ app()->getLocale()==='ar' ? 'النطاق هو متجر الجملة الرئيسي ومخازنه؛ لا توجد فروع جملة مستقلة.' : 'The scope is the main Wholesale operation and its warehouses; there are no separate Wholesale branches.' }}</div></div>
      </div>
      @if(!empty($moduleData['actions']))
      <div class="links workspace-inline-form">
        @foreach($moduleData['actions'] as $action)<a class="foodex-primary" href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach
      </div>
      @endif

      @if($module==='finance')
      @php
        $financeFilters = $moduleData['filters'] ?? ['from'=>null,'to'=>null,'customer_id'=>null];
        $financeQuery = array_filter([
            'from'=>$financeFilters['from'] ?? null,
            'to'=>$financeFilters['to'] ?? null,
            'customer_id'=>$financeFilters['customer_id'] ?? null,
        ], fn($value) => $value !== null && $value !== '');
      @endphp
      <form method="get" action="{{ route('admin.b2b.module',['module'=>'finance']) }}" class="links workspace-inline-form" aria-label="{{ app()->getLocale()==='ar'?'فلاتر الفواتير':'Invoice filters' }}">
        <label style="display:grid;gap:5px;font-size:12px;font-weight:700">
          <span>{{ app()->getLocale()==='ar'?'من':'From' }}</span>
          <input name="from" type="date" value="{{ $financeFilters['from'] ?? '' }}">
        </label>
        <label style="display:grid;gap:5px;font-size:12px;font-weight:700">
          <span>{{ app()->getLocale()==='ar'?'إلى':'To' }}</span>
          <input name="to" type="date" value="{{ $financeFilters['to'] ?? '' }}">
        </label>
        <label style="display:grid;gap:5px;font-size:12px;font-weight:700">
          <span>{{ app()->getLocale()==='ar'?'العميل':'Customer' }}</span>
          <select name="customer_id">
            <option value="">{{ app()->getLocale()==='ar'?'كل العملاء':'All customers' }}</option>
            @foreach($moduleData['customers'] as $customer)
              <option value="{{ $customer['id'] }}" @selected((int)($financeFilters['customer_id'] ?? 0)===$customer['id'])>{{ $customer['label'] }}</option>
            @endforeach
          </select>
        </label>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تطبيق الفلاتر':'Apply filters' }}</button>
        <a href="{{ route('admin.b2b.module',['module'=>'finance']) }}">{{ app()->getLocale()==='ar'?'إعادة ضبط':'Reset' }}</a>
      </form>

      <div class="workspace-inline-form" style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap">
        <div>
          <strong>{{ app()->getLocale()==='ar'?'نتيجة الفلترة':'Filtered result' }}: {{ number_format((int)($moduleData['summary']['invoice_count'] ?? 0)) }}</strong>
          <div class="muted" style="margin-top:5px">
            @forelse(($moduleData['summary']['totals'] ?? []) as $total)
              <span style="display:inline-block;margin-inline-end:12px">
                {{ $total['currency'] }}:
                {{ app()->getLocale()==='ar'?'الإجمالي':'total' }} {{ number_format((float)$total['total'],3) }} ·
                {{ app()->getLocale()==='ar'?'المدفوع':'paid' }} {{ number_format((float)$total['paid'],3) }} ·
                {{ app()->getLocale()==='ar'?'الرصيد':'balance' }} {{ number_format((float)$total['balance'],3) }}
              </span>
            @empty
              {{ app()->getLocale()==='ar'?'لا توجد فواتير تطابق الفلاتر الحالية.':'No invoices match the current filters.' }}
            @endforelse
          </div>
        </div>
        <div class="links">
          <a class="foodex-primary" href="{{ route('admin.b2b.module',array_merge(['module'=>'finance'],$financeQuery,['export'=>'xlsx'])) }}">Excel</a>
          <a class="foodex-primary" href="{{ route('admin.b2b.module',array_merge(['module'=>'finance'],$financeQuery,['export'=>'pdf'])) }}">PDF</a>
        </div>
        <div class="muted" style="flex-basis:100%;font-size:12px">
          {{ app()->getLocale()==='ar'
              ? 'التصدير يستخدم نفس الفلاتر والنتيجة الظاهرة. عند عدم وجود نتائج يتم إنشاء ملف صالح يحتوي سياق الفلاتر والعناوين بدون صفوف بيانات.'
              : 'Exports use the same filters and visible result set. With no matches, a valid file is generated with filter context and headers but no data rows.' }}
        </div>
      </div>
      @include('admin._field-operations-finance', ['fieldFinance' => $moduleData['field_operations'] ?? []])
      @endif

      @if($module==='storefront')
        @include('admin._wholesale-storefront-builder')
      @endif

      @if($module==='clients' && $user->hasPermission('b2b.accounts.manage'))
      <form method="post" action="{{ route('admin.b2b.clients.store') }}" class="links workspace-inline-form">
        @csrf
        <input name="company_name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم الشركة':'Company name' }}">
        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم مسؤول الحساب':'Account contact' }}">
        <input name="email" type="email" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'البريد الإلكتروني':'Email' }}">
        <input name="phone" maxlength="50" placeholder="{{ app()->getLocale()==='ar'?'الهاتف':'Phone' }}">
        <input name="tax_number" maxlength="100" placeholder="{{ app()->getLocale()==='ar'?'الرقم الضريبي':'Tax number' }}">
        <input name="password" type="password" required minlength="8" placeholder="{{ app()->getLocale()==='ar'?'كلمة المرور المؤقتة':'Temporary password' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إنشاء عميل جملة':'Create wholesale client' }}</button>
      </form>
      @endif

      @if($module==='products' && $user->hasPermission('catalog.manage'))
      <form method="post" action="{{ route('admin.b2b.categories.store') }}" class="links workspace-inline-form">
        @csrf
        <select name="parent_id"><option value="">{{ app()->getLocale()==='ar'?'بدون تصنيف أب':'No parent category' }}</option>@foreach($moduleData['categories'] as $category)<option value="{{ $category['id'] }}">{{ $category['name'] }}</option>@endforeach</select>
        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}">
        <input name="slug" required maxlength="255" placeholder="category-slug">
        <input type="hidden" name="is_active" value="1">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة تصنيف':'Add category' }}</button>
      </form>
      @endif

      @if($module==='products' && $user->hasPermission('catalog.create'))
      <form method="post" action="{{ route('admin.b2b.products.store') }}" class="links workspace-inline-form">
        @csrf
        <input name="sku" required maxlength="120" placeholder="SKU">
        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}">
        <select name="category_id"><option value="">{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}</option>@foreach($moduleData['categories'] as $category)<option value="{{ $category['id'] }}">{{ $category['name'] }}</option>@endforeach</select>
        <select name="brand_id"><option value="">{{ app()->getLocale()==='ar'?'العلامة':'Brand' }}</option>@foreach($moduleData['brands'] as $brand)<option value="{{ $brand['id'] }}">{{ $brand['name'] }}</option>@endforeach</select>
        <select name="unit_id" required><option value="">{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}</option>@foreach($moduleData['units'] as $unit)<option value="{{ $unit['id'] }}">{{ $unit['code'] }} · {{ $unit['name'] }}</option>@endforeach</select>
        <input name="price" type="number" min="0" step="0.001" placeholder="{{ app()->getLocale()==='ar'?'السعر':'Price' }}">
        <input name="description" maxlength="1000" placeholder="{{ app()->getLocale()==='ar'?'الوصف':'Description' }}">
        <input type="hidden" name="is_active" value="1">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة منتج جملة':'Add wholesale product' }}</button>
      </form>
      @endif

      @if($module==='inventory' && $user->hasPermission('inventory.manage'))
      <form method="post" action="{{ route('admin.b2b.warehouses.store') }}" class="links workspace-inline-form">
        @csrf
        <input name="code" required maxlength="80" placeholder="{{ app()->getLocale()==='ar'?'كود المخزن':'Warehouse code' }}">
        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم المخزن':'Warehouse name' }}">
        <input type="hidden" name="is_active" value="1">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إضافة مخزن':'Add warehouse' }}</button>
      </form>
      <form method="post" action="{{ route('admin.b2b.inventory.ensure') }}" class="links workspace-inline-form">
        @csrf
        <select name="warehouse_id" required><option value="">{{ app()->getLocale()==='ar'?'المخزن':'Warehouse' }}</option>@foreach($moduleData['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}">{{ $warehouse['name'] }}</option>@endforeach</select>
        <select name="product_id" required><option value="">{{ app()->getLocale()==='ar'?'المنتج':'Product' }}</option>@foreach($moduleData['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach</select>
        <input name="quantity" type="number" min="0" step="0.001" required placeholder="{{ app()->getLocale()==='ar'?'الكمية':'Quantity' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إنشاء/تحديث الرصيد':'Create/update balance' }}</button>
      </form>
      @endif

      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage'))
      <form method="post" action="{{ route('admin.b2b.drivers.store') }}" class="links workspace-inline-form">
        @csrf
        <input name="name" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'اسم السائق':'Driver name' }}">
        <input name="email" type="email" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'البريد':'Email' }}">
        <input name="password" type="password" required minlength="8" placeholder="{{ app()->getLocale()==='ar'?'كلمة المرور':'Password' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إنشاء سائق جملة':'Create wholesale driver' }}</button>
      </form>
      @endif

      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage') && !empty($moduleData['drivers']))
      <div class="panel" style="margin-bottom:12px">
        <strong>{{ app()->getLocale()==='ar'?'إعادة تعيين كلمة مرور السائق':'Reset driver password' }}</strong>
        <p class="muted">{{ app()->getLocale()==='ar'?'اختر السائق وحدد كلمة مرور جديدة. سيتم إلغاء جلسات السائق الحالية فوراً.':'Choose a driver and set a new password. Existing driver sessions will be revoked immediately.' }}</p>
        <div style="display:grid;gap:8px">
          @foreach($moduleData['drivers'] as $driver)
          <details data-foodex-operational-modal style="border:1px solid var(--foodex-border);border-radius:12px;padding:10px 12px;background:#fbfcfd">
                        <summary style="cursor:pointer;font-weight:700">{{ $driver['name'] }} · {{ app()->getLocale()==='ar'?'اسم المستخدم':'Username' }}: {{ $driver['username'] ?? '—' }} · {{ $driver['email'] }}</summary>
            <form method="post" action="{{ route('admin.b2b.drivers.password',['driver'=>$driver['id']]) }}" class="links workspace-inline-form" style="margin-top:10px">
              @csrf @method('patch')
              <input name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="{{ app()->getLocale()==='ar'?'كلمة المرور الجديدة':'New password' }}">
              <input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" placeholder="{{ app()->getLocale()==='ar'?'تأكيد كلمة المرور':'Confirm password' }}">
              <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إعادة تعيين كلمة المرور':'Reset password' }}</button>
            </form>
          </details>
          @endforeach
        </div>
      </div>
      @endif

      @if($module==='settings' && $user->hasPermission('settings.manage'))
      <form method="post" action="{{ route('admin.b2b.settings.save') }}" class="links workspace-inline-form">
        @csrf @method('put')
        <input name="key" required maxlength="255" placeholder="wholesale.setting.key">
        <input name="value" maxlength="5000" placeholder="{{ app()->getLocale()==='ar'?'القيمة':'Value' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ الإعداد':'Save setting' }}</button>
      </form>
      @endif
      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage'))
      <form method="post" action="{{ route('admin.b2b.drivers.assign') }}" class="links workspace-inline-form">
        @csrf
        <select name="driver_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر السائق':'Select driver' }}</option>@foreach($moduleData['drivers'] as $driver)<option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>@endforeach</select>
        <select name="order_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر الطلب':'Select order' }}</option>@foreach($moduleData['orders'] as $order)<option value="{{ $order['id'] }}">{{ $order['number'] }}</option>@endforeach</select>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين السائق':'Assign driver' }}</button>
      </form>
      @endif
      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage'))
        @include('admin._driver-assignment-management',['channel'=>'b2b'])
      @endif
      @if($module==='pricing' && $user->hasPermission('b2b.pricing.manage'))
      <form method="post" action="{{ route('admin.b2b.pricing.save') }}" class="links workspace-inline-form">
        @csrf
        <select name="price_tier_id" required><option value="">{{ app()->getLocale()==='ar'?'شريحة السعر':'Price tier' }}</option>@foreach($moduleData['tiers'] as $tier)<option value="{{ $tier['id'] }}">{{ $tier['name'] }}</option>@endforeach</select>
        <select name="product_id" required><option value="">{{ app()->getLocale()==='ar'?'المنتج':'Product' }}</option>@foreach($moduleData['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach</select>
        <input name="unit_price" type="number" min="0" step="0.001" placeholder="{{ app()->getLocale()==='ar'?'سعر الوحدة':'Unit price' }}" required>
        <input name="minimum_quantity" type="number" min="0.001" step="0.001" placeholder="{{ app()->getLocale()==='ar'?'الحد الأدنى':'Minimum quantity' }}" required>
        <input type="hidden" name="is_active" value="1">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ قاعدة السعر':'Save price rule' }}</button>
      </form>
      @endif
      @if($module==='orders' && $user->hasPermission('orders.manage'))
      @include('admin._dashboard-order-create',['channel'=>'b2b'])
      @endif
      @if($module!=='storefront' && count($moduleData['rows']))
      <div class="table-wrap"><table class="data foodex-table" data-pagination-required><thead><tr>@foreach($moduleData['columns'] as $column)<th>{{ $labels[$column]??$column }}</th>@endforeach</tr></thead><tbody>
      @foreach($moduleData['rows'] as $row)<tr>@foreach($moduleData['columns'] as $column)<td>
        @if(in_array($column,['status','availability','active'],true) && is_bool($row[$column]))<span class="state {{ $row[$column]?'':'off' }}">{{ $row[$column]?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
        @elseif($column==='status')<span class="badge {{ $row[$column] }}">{{ $workspaceStatusLabels[$row[$column]] ?? __('admin.b2b_workspace.account_statuses.pending') }}</span>
        @elseif($column==='assignment_status'){{ $orderStateLabels[$row[$column]] ?? $row[$column] }}
        @elseif($column==='actions' && in_array($module,['reports','finance'],true) && is_array($row['actions'] ?? null))
          <div class="links">@foreach($row['actions'] as $action)<a href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach</div>
        @elseif($column==='actions' && $module==='clients' && $user->hasPermission('b2b.accounts.manage'))
          @if(!empty($row['_retail_linked']))
            <span class="muted" style="display:inline-block;max-width:260px;font-size:12px;line-height:1.55">
              {{ app()->getLocale()==='ar'
                  ? 'الحالة مرتبطة بمتجر التجزئة «'.($row['_retail_store_name'] ?: '—').'» ويتم التحكم بها من حالة المتجر.'
                  : 'Status is controlled by the linked Retail store “'.($row['_retail_store_name'] ?: '—').'”.' }}
            </span>
          @else
            <form method="post" action="{{ route('admin.b2b.clients.status',['account'=>$row['_id']]) }}" class="links">
              @csrf @method('patch')
              <select name="status" required>@foreach(['pending','active','suspended','denied'] as $state)<option value="{{ $state }}" @selected($row['status']===$state)>{{ __('admin.b2b_workspace.account_statuses.'.$state) }}</option>@endforeach</select>
              <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button>
            </form>
          @endif
        @elseif($column==='actions' && $module==='products' && $user->hasPermission('catalog.edit'))
          <details data-foodex-operational-modal><summary>{{ app()->getLocale()==='ar'?'تعديل':'Edit' }}</summary>
          <form method="post" action="{{ route('admin.b2b.products.update',['product'=>$row['_id']]) }}" class="links workspace-inline-form">
            @csrf @method('patch')
            <input name="sku" value="{{ $row['sku'] }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: PROD-001':'e.g. PROD-001' }}" required maxlength="120">
            <input name="name" value="{{ $row['name'] }}" placeholder="{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}" required maxlength="255">
            <select name="category_id"><option value="">{{ app()->getLocale()==='ar'?'بدون تصنيف':'No category' }}</option>@foreach($moduleData['categories'] as $category)<option value="{{ $category['id'] }}" @selected($row['_category_id']===$category['id'])>{{ $category['name'] }}</option>@endforeach</select>
            <select name="brand_id"><option value="">{{ app()->getLocale()==='ar'?'بدون علامة':'No brand' }}</option>@foreach($moduleData['brands'] as $brand)<option value="{{ $brand['id'] }}" @selected($row['_brand_id']===$brand['id'])>{{ $brand['name'] }}</option>@endforeach</select>
            <select name="unit_id" required>@foreach($moduleData['units'] as $unit)<option value="{{ $unit['id'] }}" @selected($row['_unit_id']===$unit['id'])>{{ $unit['code'] }} · {{ $unit['name'] }}</option>@endforeach</select>
            <input name="price" type="number" min="0" step="0.001" value="{{ $row['price']==='-'?'':str_replace([' EGP',','],'',$row['price']) }}" placeholder="{{ app()->getLocale()==='ar'?'مثال: 1.250':'e.g. 1.250' }}">
            <input name="description" maxlength="1000" value="{{ $row['_description'] }}" placeholder="{{ app()->getLocale()==='ar'?'وصف مختصر للمنتج':'Short product description' }}">
            <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($row['status'])> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
            <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ':'Save' }}</button>
          </form></details>
        @elseif($column==='actions' && $module==='inventory' && $user->hasPermission('inventory.adjust'))
          <form method="post" action="{{ route('admin.b2b.inventory.adjust',['inventory'=>$row['_id']]) }}" class="links">
            @csrf @method('patch')
            <input name="quantity_delta" type="number" step="0.001" required placeholder="+/-">
            <input name="reason" required maxlength="255" placeholder="{{ app()->getLocale()==='ar'?'السبب':'Reason' }}">
            <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعديل':'Adjust' }}</button>
          </form>
        @elseif($column==='actions' && $module==='orders' && $user->hasPermission('orders.manage'))
          @include('admin._dashboard-order-actions',['channel'=>'b2b','row'=>$row])
        @else{{ $row[$column] }}@endif
      </td>@endforeach</tr>@endforeach
      </tbody></table></div>
      @if(isset($moduleData['rows_paginator'])){{ $moduleData['rows_paginator']->links() }}@endif
      @elseif($module!=='storefront')
      <div class="empty-state" role="status">{{ app()->getLocale()==='ar' ? 'لا توجد بيانات متاحة في هذا القسم.' : 'No records are available in this section.' }}</div>
      @endif
    </section>
    @else
    <section class="panel workspace-panel"><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><p class="muted">{{ __('admin.b2b_workspace.empty_hint') }}</p></section>
    @endif
</main>
</div>
@if($module === 'dashboard' && $canViewDriverTracking)
@include('admin._driver-live-map-scripts')
@endif
</body>
</html>
