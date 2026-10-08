@php
    $merchant = data_get($dashboard, 'merchant_intelligence');
    $summary = (array) data_get($merchant, 'summary', []);
    $account = data_get($merchant, 'wholesale_account');
    $attention = collect(data_get($merchant, 'attention', []));
    $recommendations = collect(data_get($merchant, 'recommendations', []));
    $executablePlan = $recommendations
        ->filter(fn ($row) => (bool) data_get($row, 'recommendation.is_executable', false))
        ->filter(fn ($row) => in_array((string) data_get($row, 'recommendation.action'), ['reorder_now', 'reorder_soon'], true))
        ->values();

    $compareSeries = collect(data_get($merchant, 'visualizations.retail_vs_wholesale', []));
    $compareMax = max(1, (float) $compareSeries->max(
        fn ($row) => max((float) ($row['retail_sales'] ?? 0), (float) ($row['wholesale_purchases'] ?? 0))
    ));

    $risk = (array) data_get($merchant, 'visualizations.stock_risk', []);
    $riskCritical = (int) ($risk['critical_understock'] ?? 0)
        + (int) ($risk['understock'] ?? 0)
        + (int) ($risk['inventory_inconsistent'] ?? 0);
    $riskHealthy = (int) ($risk['balanced'] ?? 0);
    $riskOverstock = (int) ($risk['overstock'] ?? 0) + (int) ($risk['overstock_no_demand'] ?? 0);
    $riskUnknown = (int) ($risk['insufficient_data'] ?? 0);
    $riskTotal = max(1, $riskCritical + $riskHealthy + $riskOverstock + $riskUnknown);
    $riskP1 = round($riskCritical / $riskTotal * 100, 2);
    $riskP2 = round(($riskCritical + $riskHealthy) / $riskTotal * 100, 2);
    $riskP3 = round(($riskCritical + $riskHealthy + $riskOverstock) / $riskTotal * 100, 2);

    $spend = (array) data_get($merchant, 'visualizations.reorder_spend', []);
    $spendMax = max(1, (float) max(array_values($spend ?: [0])));

    $aging = (array) data_get($merchant, 'visualizations.inventory_aging', []);
    $agingMax = max(1, (float) collect($aging)->max(fn ($bucket) => (float) ($bucket['quantity'] ?? 0)));

    $marginVelocity = collect(data_get($merchant, 'visualizations.margin_velocity', []));
    $maxVelocity = max(0.001, (float) $marginVelocity->max(fn ($row) => (float) ($row['velocity'] ?? 0)));
    $maxMargin = max(1, (float) $marginVelocity->max(fn ($row) => max(0, (float) ($row['margin_percent'] ?? 0))));

    $factLabels = __('admin.b2c_dashboard.merchant_intelligence.facts');
    $agingLabels = __('admin.b2c_dashboard.merchant_intelligence.aging');
@endphp

@if($merchant)
<style id="merchant-intelligence-dashboard-styles">
    .merchant-intelligence{display:grid;gap:var(--foodex-space-3);margin-top:var(--foodex-space-3)}
    .merchant-section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:var(--foodex-space-3)}
    .merchant-section-head h2{margin:0;font-size:1.05rem}.merchant-section-head p{margin:3px 0 0;color:var(--foodex-muted);font-size:.75rem}
    .merchant-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:var(--foodex-space-3)}
    .merchant-kpi{display:block;padding:var(--foodex-space-3);min-height:92px;text-decoration:none}.merchant-kpi strong{display:block;font-size:.73rem;color:var(--foodex-muted)}.merchant-kpi b{display:block;margin-top:8px;font-size:1.45rem}.merchant-kpi small{display:block;margin-top:3px;color:var(--foodex-muted)}
    .merchant-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.8fr);gap:var(--foodex-space-3)}
    .merchant-recommendations{display:grid;gap:8px}.merchant-rec{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:10px;display:grid;grid-template-columns:minmax(180px,1fr) minmax(0,3fr) auto;gap:10px;align-items:center}.merchant-rec strong{display:block}.merchant-rec small{color:var(--foodex-muted)}.merchant-rec-product{min-width:0}.merchant-rec-spark{display:flex;align-items:center;gap:6px;margin-top:6px}.merchant-rec-spark svg{width:62px;height:30px}.merchant-rec-metrics{display:grid;grid-template-columns:repeat(6,minmax(72px,1fr));gap:8px}.merchant-rec-metric span{display:block;color:var(--foodex-muted);font-size:.66rem}.merchant-rec-metric b{font-size:.77rem}.merchant-rec-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.merchant-rec-actions a{min-height:34px;padding:0 10px;font-size:.7rem}.merchant-why{grid-column:1/-1;border-top:1px dashed var(--foodex-border);padding-top:7px}.merchant-why summary{cursor:pointer;color:var(--foodex-green-dark);font-weight:700}.merchant-facts{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}.merchant-fact{background:var(--foodex-background);border-radius:999px;padding:5px 8px;font-size:.67rem}
    .merchant-state{display:inline-flex;border-radius:999px;padding:4px 7px;font-size:.64rem;font-weight:700}.merchant-state.now{background:rgba(239,83,80,.10);color:var(--foodex-red)}.merchant-state.soon{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.merchant-state.healthy{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.merchant-state.blocked{background:rgba(75,140,245,.12);color:var(--foodex-blue)}.merchant-state.protect{background:var(--foodex-background);color:var(--foodex-muted)}
    .merchant-account-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.merchant-account-metric{padding:9px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-sm)}.merchant-account-metric span{display:block;color:var(--foodex-muted);font-size:.68rem}.merchant-account-metric b{display:block;margin-top:3px;font-size:.9rem}
    .merchant-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
    .merchant-compare{display:flex;gap:8px;align-items:flex-end;min-height:170px;overflow-x:auto;padding-top:10px}.merchant-compare-day{min-width:30px;flex:1;display:grid;grid-template-columns:1fr 1fr;gap:3px;align-items:end;height:150px;position:relative;padding-bottom:22px}.merchant-compare-day i{display:block;border-radius:4px 4px 2px 2px;min-height:2px}.merchant-compare-day i:first-child{background:var(--foodex-viz-primary)}.merchant-compare-day i:nth-child(2){background:var(--foodex-viz-info)}.merchant-compare-day small{position:absolute;bottom:0;inset-inline:0;text-align:center;color:var(--foodex-muted);font-size:.58rem}
    .merchant-risk-wrap{display:flex;align-items:center;justify-content:center;gap:20px;min-height:170px}.merchant-risk-list{display:grid;gap:8px;font-size:.72rem}.merchant-risk-list span{display:flex;justify-content:space-between;gap:20px}
    .merchant-simple-bars{display:grid;gap:10px}.merchant-simple-row{display:grid;grid-template-columns:minmax(90px,.8fr) 1.8fr auto;gap:9px;align-items:center;font-size:.72rem}.merchant-simple-track{height:11px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.merchant-simple-track i{height:100%;display:block;border-radius:inherit;background:var(--foodex-green)}.merchant-simple-row.warning .merchant-simple-track i{background:var(--foodex-orange)}.merchant-simple-row.danger .merchant-simple-track i{background:var(--foodex-red)}.merchant-simple-row.info .merchant-simple-track i{background:var(--foodex-blue)}
    .merchant-scatter{position:relative;min-height:190px;border-inline-start:1px solid var(--foodex-border);border-bottom:1px solid var(--foodex-border);margin:12px 14px 24px 28px;background:linear-gradient(to right,var(--foodex-viz-grid) 1px,transparent 1px),linear-gradient(to top,var(--foodex-viz-grid) 1px,transparent 1px);background-size:25% 25%}.merchant-dot{position:absolute;width:12px;height:12px;border-radius:50%;background:var(--foodex-green);transform:translate(-50%,50%);border:2px solid #fff;box-shadow:0 0 0 1px var(--foodex-border)}.merchant-dot[data-action="reorder_now"]{background:var(--foodex-red)}.merchant-dot[data-action="reorder_soon"]{background:var(--foodex-orange)}.merchant-scatter-x,.merchant-scatter-y{position:absolute;color:var(--foodex-muted);font-size:.62rem}.merchant-scatter-x{bottom:-22px;inset-inline:0;text-align:center}.merchant-scatter-y{inset-inline-start:-30px;top:50%;writing-mode:vertical-rl;transform:translateY(-50%)}
    .merchant-plan-list{display:grid;gap:8px}.merchant-plan-row{display:grid;grid-template-columns:minmax(150px,1.2fr) minmax(150px,1fr) repeat(5,minmax(80px,.65fr));gap:8px;align-items:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:10px}.merchant-plan-cell span{display:block;color:var(--foodex-muted);font-size:.63rem}.merchant-plan-cell b{font-size:.74rem}.merchant-plan-cell strong{display:block}.merchant-plan-cell small{display:block;color:var(--foodex-muted)}
    .merchant-secondary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}.merchant-mini-list{display:grid;gap:7px}.merchant-mini-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px;border-bottom:1px solid var(--foodex-border)}.merchant-mini-row:last-child{border-bottom:0}.merchant-mini-row small{display:block;color:var(--foodex-muted)}
    @media(max-width:1180px){.merchant-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.merchant-grid,.merchant-charts{grid-template-columns:1fr}.merchant-rec{grid-template-columns:minmax(160px,.8fr) minmax(0,2fr)}.merchant-rec-actions{grid-column:1/-1;justify-content:flex-start}.merchant-rec-metrics{grid-template-columns:repeat(3,minmax(76px,1fr))}.merchant-plan-row{grid-template-columns:repeat(3,minmax(120px,1fr))}}
    @media(max-width:720px){.merchant-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.merchant-secondary{grid-template-columns:1fr}.merchant-rec{grid-template-columns:1fr}.merchant-rec-product,.merchant-rec-metrics,.merchant-rec-actions{grid-column:1}.merchant-rec-metrics{grid-template-columns:repeat(2,minmax(90px,1fr))}.merchant-account-grid{grid-template-columns:1fr 1fr}.merchant-plan-row{grid-template-columns:1fr 1fr}}
</style>

<section class="merchant-intelligence" data-merchant-intelligence>
    <div class="merchant-section-head">
        <div>
            <h2>{{ __('admin.b2c_dashboard.merchant_intelligence.store_today') }}</h2>
            <p>{{ __('admin.b2c_dashboard.merchant_intelligence.store_today_subtitle') }}</p>
        </div>
        <span class="merchant-state healthy">{{ __('admin.b2c_dashboard.merchant_intelligence.lead_time_value', ['days'=>number_format((float)data_get($merchant,'lead_time.expected_days',0),1)]) }}</span>
    </div>

    <div class="merchant-summary">
        <a class="merchant-kpi foodex-viz-card" href="#merchant-recommendations"><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now') }}</strong><b>{{ number_format((int)($summary['reorder_now'] ?? 0)) }}</b><small>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now_help') }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-recommendations"><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_soon') }}</strong><b>{{ number_format((int)($summary['reorder_soon'] ?? 0)) }}</b><small>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.reorder_soon_help') }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-blockers"><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention') }}</strong><b>{{ number_format((int)($summary['blocked'] ?? 0)) }}</b><small>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.needs_attention_help') }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#suggested-purchase-plan"><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.expected_spend') }}</strong><b>{{ number_format((float)($summary['expected_reorder_spend'] ?? 0),3) }}</b><small>{{ data_get($account,'finance.currency',$dashboard['currency']) }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-slow-movers"><strong>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.do_not_reorder') }}</strong><b>{{ number_format((int)($summary['do_not_reorder'] ?? 0)) }}</b><small>{{ __('admin.b2c_dashboard.merchant_intelligence.summary.do_not_reorder_help') }}</small></a>
    </div>

    <div class="merchant-grid">
        <article class="panel" id="merchant-recommendations">
            <div class="panel-title">
                <div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.attention_subtitle') }}</small></div>
            </div>
            <div class="merchant-recommendations">
                @forelse($attention as $row)
                    @php
                        $action = (string)data_get($row,'recommendation.action','healthy');
                        $stateClass = match($action) {
                            'reorder_now' => 'now',
                            'reorder_soon' => 'soon',
                            'do_not_reorder' => 'protect',
                            'healthy' => 'healthy',
                            default => 'blocked',
                        };
                        $actionKey = in_array($action, ['reorder_now','reorder_soon','do_not_reorder','missing_mapping','invalid_mapping','ambiguous_mapping','insufficient_data','healthy'], true)
                            ? $action
                            : 'review_needed';
                        $actionLabel = __('admin.b2c_dashboard.merchant_intelligence.actions.'.$actionKey);
                        $inventoryUrl = route('admin.b2c.module', array_merge([
                            'module'=>'inventory',
                            'store_id'=>$storeId,
                            'focus_product'=>(int)$row['retail_product_id'],
                        ], $supportAccess ? ['support_access'=>1] : []));
                        $spark = (array)data_get($merchant,'visualizations.sales_sparklines.'.(int)$row['retail_product_id'],[0,0,0]);
                        $sparkMax = max(0.001, ...array_map('floatval',$spark));
                        $sparkPoints = collect($spark)->values()->map(function($value,$index) use ($sparkMax) {
                            $x = 4 + ($index * 27);
                            $y = 26 - (((float)$value / $sparkMax) * 20);

                            return $x.','.round($y,1);
                        })->implode(' ');
                        $stockoutAt = data_get($row,'inventory.estimated_stockout_at');
                        $caseEquivalent = data_get($row,'commercial.recommended_case_equivalent');
                    @endphp
                    <div class="merchant-rec">
                        <div class="merchant-rec-product"><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small><br><span class="merchant-state {{ $stateClass }}">{{ $actionLabel }}</span><div class="merchant-rec-spark"><svg class="foodex-viz-sparkline" viewBox="0 0 62 30" role="img" aria-label="{{ __('admin.b2c_dashboard.merchant_intelligence.sales_pace_aria') }}"><polyline class="foodex-viz-line" points="{{ $sparkPoints }}"/></svg><small>{{ __('admin.b2c_dashboard.merchant_intelligence.sales_pace') }}</small></div></div>
                        <div class="merchant-rec-metrics">
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.available') }}</span><b>{{ number_format((float)data_get($row,'inventory.available',0),2) }}</b></div>
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.units_per_day') }}</span><b>{{ number_format((float)data_get($row,'inventory.velocity_units_per_day',0),2) }}</b></div>
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.days_cover') }}</span><b>{{ data_get($row,'inventory.days_of_cover')===null ? '—' : number_format((float)data_get($row,'inventory.days_of_cover'),1) }}</b></div>
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.expected_stockout') }}</span><b>{{ $stockoutAt ? CarbonCarbon::parse($stockoutAt)->format('d/m') : '—' }}</b></div>
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.qty_cases') }}</span><b>{{ number_format((float)data_get($row,'recommendation.recommended_wholesale_quantity',0),2) }} / {{ $caseEquivalent===null ? '—' : number_format((float)$caseEquivalent,2) }}</b></div>
                            <div class="merchant-rec-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.metrics.expected_cost') }}</span><b>{{ number_format((float)data_get($row,'recommendation.expected_cost',0),3) }}</b></div>
                        </div>
                        <div class="merchant-rec-actions">
                            <a class="foodex-action-secondary" href="{{ $inventoryUrl }}">{{ __('admin.b2c_dashboard.merchant_intelligence.open_inventory') }}</a>
                            @if((bool)data_get($row,'recommendation.is_executable',false))
                                <a class="foodex-action-primary" href="#suggested-purchase-plan">{{ __('admin.b2c_dashboard.merchant_intelligence.open_purchase_plan') }}</a>
                            @endif
                        </div>
                        <details class="merchant-why">
                            <summary>{{ __('admin.b2c_dashboard.merchant_intelligence.why_recommendation') }}</summary>
                            <div class="merchant-facts">
                                @foreach((array)($row['reason_facts'] ?? []) as $key=>$value)
                                    @if(isset($factLabels[$key]))
                                        <span class="merchant-fact"><b>{{ $factLabels[$key] }}:</b>
                                            @if($key==='movement_class')
                                                {{ __('admin.b2c_dashboard.merchant_intelligence.movement_class.'.(string)$value) }}
                                            @elseif($key==='stock_risk')
                                                {{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk.'.(string)$value) }}
                                            @elseif(is_bool($value))
                                                {{ $value ? __('admin.b2c_dashboard.merchant_intelligence.yes') : __('admin.b2c_dashboard.merchant_intelligence.no') }}
                                            @elseif(is_numeric($value))
                                                {{ number_format((float)$value,2) }}
                                            @else
                                                {{ $value }}
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    </div>
                @empty
                    <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_attention') }}</div>
                @endforelse
            </div>
        </article>

        @if((bool)data_get($merchant,'owner_context.is_owner'))
        <article class="panel" data-owner-wholesale-account>
            <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.owner_account_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.owner_account_subtitle') }}</small></div></div>
            @if($account)
                @php
                    $accountStatus=(string)data_get($account,'status','inactive');
                    $accountStatusKey=in_array($accountStatus,['active','inactive','pending','suspended','blocked'],true)?$accountStatus:'inactive';
                @endphp
                <strong>{{ $account['customer_name'] }}</strong>
                <p class="empty">{{ __('admin.b2c_dashboard.merchant_intelligence.price_tier') }}: {{ $account['price_tier'] ?? __('admin.b2c_dashboard.merchant_intelligence.not_configured') }} · {{ __('admin.b2c_dashboard.merchant_intelligence.status') }}: {{ __('admin.b2c_dashboard.merchant_intelligence.account_status.'.$accountStatusKey) }}</p>
                @if(data_get($account,'finance_status')==='available')
                    <div class="merchant-account-grid">
                        <div class="merchant-account-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.purchasing_power') }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.purchasing_power'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.available_credit') }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.available_credit_line'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.open_amount') }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.open_amount'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.overdue') }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.overdue_amount'),3) }}</b></div>
                    </div>
                @else
                    <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.finance_unavailable') }}</div>
                @endif
            @else
                <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.owner_setup_incomplete') }}</div>
            @endif
        </article>
        @endif
    </div>

    <div class="merchant-charts">
        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.retail_vs_wholesale_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.retail_vs_wholesale_subtitle') }}</p></div></div>
            @if($compareSeries->isNotEmpty())
                <div class="merchant-compare" role="img" aria-label="{{ __('admin.b2c_dashboard.merchant_intelligence.retail_vs_wholesale_aria') }}">
                    @foreach($compareSeries as $day)
                        <div class="merchant-compare-day" title="{{ $day['label'] }} · {{ number_format((float)$day['retail_sales'],3) }} / {{ number_format((float)$day['wholesale_purchases'],3) }}">
                            <i style="height:{{ max(2,round((float)$day['retail_sales']/$compareMax*126)) }}px"></i>
                            <i style="height:{{ max(2,round((float)$day['wholesale_purchases']/$compareMax*126)) }}px"></i>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
                <div class="foodex-viz-legend"><span class="foodex-viz-legend-item"><i class="foodex-viz-swatch"></i>{{ __('admin.b2c_dashboard.merchant_intelligence.retail_sales') }}</span><span class="foodex-viz-legend-item"><i class="foodex-viz-swatch" style="background:var(--foodex-blue)"></i>{{ __('admin.b2c_dashboard.merchant_intelligence.wholesale_purchases') }}</span></div>
            @else
                <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_activity') }}</div>
            @endif
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk_subtitle') }}</p></div></div>
            <div class="merchant-risk-wrap">
                <div class="foodex-viz-donut" style="--foodex-viz-p1:{{ $riskP1 }}%;--foodex-viz-p2:{{ $riskP2 }}%;--foodex-viz-p3:{{ $riskP3 }}%" role="img" aria-label="{{ __('admin.b2c_dashboard.merchant_intelligence.stock_risk_aria') }}"></div>
                <div class="merchant-risk-list">
                    <span><em>{{ __('admin.b2c_dashboard.merchant_intelligence.critical_low') }}</em><b>{{ $riskCritical }}</b></span>
                    <span><em>{{ __('admin.b2c_dashboard.merchant_intelligence.balanced') }}</em><b>{{ $riskHealthy }}</b></span>
                    <span><em>{{ __('admin.b2c_dashboard.merchant_intelligence.overstock') }}</em><b>{{ $riskOverstock }}</b></span>
                    <span><em>{{ __('admin.b2c_dashboard.merchant_intelligence.insufficient_data_label') }}</em><b>{{ $riskUnknown }}</b></span>
                </div>
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.reorder_spend_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.reorder_spend_subtitle') }}</p></div></div>
            <div class="merchant-simple-bars">
                @foreach([
                    ['key'=>'reorder_now','label'=>__('admin.b2c_dashboard.merchant_intelligence.summary.reorder_now'),'class'=>'danger'],
                    ['key'=>'reorder_soon','label'=>__('admin.b2c_dashboard.merchant_intelligence.summary.reorder_soon'),'class'=>'warning'],
                    ['key'=>'availability_limited','label'=>__('admin.b2c_dashboard.merchant_intelligence.availability_limited'),'class'=>'info'],
                ] as $bar)
                    @php $barValue=(float)($spend[$bar['key']] ?? 0); @endphp
                    <div class="merchant-simple-row {{ $bar['class'] }}"><span>{{ $bar['label'] }}</span><span class="merchant-simple-track"><i style="width:{{ round($barValue/$spendMax*100,1) }}%"></i></span><b>{{ number_format($barValue,3) }}</b></div>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.margin_velocity_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.margin_velocity_subtitle') }}</p></div></div>
            @if($marginVelocity->isNotEmpty())
                <div class="merchant-scatter" role="img" aria-label="{{ __('admin.b2c_dashboard.merchant_intelligence.margin_velocity_aria') }}">
                    @foreach($marginVelocity as $point)
                        @php
                            $left=8+min(1,(float)$point['velocity']/$maxVelocity)*84;
                            $bottom=8+min(1,max(0,(float)$point['margin_percent'])/$maxMargin)*84;
                        @endphp
                        <span class="merchant-dot" data-action="{{ $point['action'] }}" style="left:{{ round($left,1) }}%;bottom:{{ round($bottom,1) }}%" title="{{ $point['name'] }} · {{ number_format((float)$point['velocity'],2) }} · {{ number_format((float)$point['margin_percent'],1) }}%"></span>
                    @endforeach
                    <span class="merchant-scatter-x">{{ __('admin.b2c_dashboard.merchant_intelligence.sales_velocity_axis') }}</span>
                    <span class="merchant-scatter-y">{{ __('admin.b2c_dashboard.merchant_intelligence.gross_margin_axis') }}</span>
                </div>
            @else
                <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_margin_data') }}</div>
            @endif
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.inventory_aging_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.inventory_aging_subtitle') }}</p></div></div>
            <div class="merchant-simple-bars">
                @foreach($agingLabels as $key=>$label)
                    @php $qty=(float)data_get($aging,$key.'.quantity',0); @endphp
                    <div class="merchant-simple-row"><span>{{ $label }}</span><span class="merchant-simple-track"><i style="width:{{ round($qty/$agingMax*100,1) }}%"></i></span><b>{{ number_format($qty,2) }}</b></div>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ __('admin.b2c_dashboard.merchant_intelligence.lost_sales_title') }}</h3><p class="foodex-viz-subtitle">{{ __('admin.b2c_dashboard.merchant_intelligence.lost_sales_subtitle') }}</p></div></div>
            <div style="display:grid;place-items:center;min-height:170px;text-align:center">
                <div><strong style="font-size:1.8rem">{{ data_get($account,'finance.currency',$dashboard['currency']) }} {{ number_format((float)($summary['lost_sales_risk_revenue'] ?? 0),3) }}</strong><p class="empty">{{ __('admin.b2c_dashboard.merchant_intelligence.lost_sales_help') }}</p></div>
            </div>
        </article>
    </div>

    <article class="panel" id="suggested-purchase-plan">
        <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.purchase_plan_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.purchase_plan_subtitle') }}</small></div></div>
        <div class="merchant-plan-list">
            @forelse($executablePlan as $row)
                <div class="merchant-plan-row">
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.retail_product') }}</span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.wholesale_source') }}</span><strong>{{ data_get($row,'mapping.source_name','—') }}</strong><small>{{ data_get($row,'mapping.source_sku','') }}</small></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.wholesale_qty') }}</span><b>{{ number_format((float)data_get($row,'recommendation.recommended_wholesale_quantity',0),2) }}</b></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.retail_equivalent') }}</span><b>{{ number_format((float)data_get($row,'recommendation.recommended_retail_equivalent',0),2) }}</b></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.case_equivalent') }}</span><b>{{ data_get($row,'commercial.recommended_case_equivalent')===null?'—':number_format((float)data_get($row,'commercial.recommended_case_equivalent'),2) }}</b></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.expected_cost') }}</span><b>{{ number_format((float)data_get($row,'recommendation.expected_cost',0),3) }}</b></div>
                    <div class="merchant-plan-cell"><span>{{ __('admin.b2c_dashboard.merchant_intelligence.plan.priority') }}</span><b>{{ number_format((float)($row['priority_score'] ?? 0),1) }}/100</b></div>
                </div>
            @empty
                <div class="foodex-viz-empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_executable_plan') }}</div>
            @endforelse
        </div>
    </article>

    <div class="merchant-secondary" id="merchant-blockers">
        <article class="panel">
            <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.mapping_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.mapping_subtitle') }}</small></div></div>
            <div class="merchant-mini-list">
                @forelse(data_get($merchant,'sections.missing_mapping',[]) as $row)
                    <div class="merchant-mini-row"><span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small></span><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.merchant_intelligence.open_product') }}</a></div>
                @empty <div class="empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_missing_mapping') }}</div> @endforelse
            </div>
        </article>
        <article class="panel" id="merchant-slow-movers">
            <div class="panel-title"><div><h2>{{ __('admin.b2c_dashboard.merchant_intelligence.slow_title') }}</h2><small>{{ __('admin.b2c_dashboard.merchant_intelligence.slow_subtitle') }}</small></div></div>
            <div class="merchant-mini-list">
                @forelse(data_get($merchant,'sections.slow_movers',[]) as $row)
                    <div class="merchant-mini-row"><span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }} · {{ data_get($row,'inventory.days_of_cover')===null?'—':number_format((float)data_get($row,'inventory.days_of_cover'),1).' '.__('admin.b2c_dashboard.merchant_intelligence.days_suffix') }}</small></span><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">{{ __('admin.b2c_dashboard.merchant_intelligence.open_inventory') }}</a></div>
                @empty <div class="empty">{{ __('admin.b2c_dashboard.merchant_intelligence.no_slow_movers') }}</div> @endforelse
            </div>
        </article>
    </div>
</section>
@endif
