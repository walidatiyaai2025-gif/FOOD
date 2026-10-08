@php
    $merchant = data_get($dashboard, 'merchant_intelligence');
    $ar = app()->getLocale() === 'ar';
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

    $factLabels = $ar
        ? [
            'available_retail_stock' => 'المخزون المتاح',
            'days_of_cover' => 'أيام التغطية',
            'expected_lead_time_days' => 'مدة التوريد المتوقعة',
            'reorder_point' => 'نقطة إعادة الطلب',
            'target_stock' => 'المخزون المستهدف',
            'retail_units_needed' => 'وحدات التجزئة المطلوبة',
            'quantity_conversion_factor' => 'معامل التحويل',
            'minimum_order_quantity' => 'الحد الأدنى لطلب الجملة',
            'ordering_increment' => 'خطوة كمية الطلب',
            'wholesale_available_quantity' => 'المتاح لدى الجملة',
            'availability_limited' => 'محدود بتوفر الجملة',
            'movement_class' => 'حركة المنتج',
            'stock_risk' => 'مخاطر المخزون',
            'history_days' => 'أيام البيانات',
        ]
        : [
            'available_retail_stock' => 'Available Retail stock',
            'days_of_cover' => 'Days of cover',
            'expected_lead_time_days' => 'Expected lead time',
            'reorder_point' => 'Reorder point',
            'target_stock' => 'Target stock',
            'retail_units_needed' => 'Retail units needed',
            'quantity_conversion_factor' => 'Conversion factor',
            'minimum_order_quantity' => 'Wholesale MOQ',
            'ordering_increment' => 'Order increment',
            'wholesale_available_quantity' => 'Wholesale availability',
            'availability_limited' => 'Availability limited',
            'movement_class' => 'Movement class',
            'stock_risk' => 'Stock risk',
            'history_days' => 'History days',
        ];
@endphp

@if($merchant)
<style id="merchant-intelligence-dashboard-styles">
    .merchant-intelligence{display:grid;gap:var(--foodex-space-3);margin-top:var(--foodex-space-3)}
    .merchant-section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:var(--foodex-space-3)}
    .merchant-section-head h2{margin:0;font-size:1.05rem}.merchant-section-head p{margin:3px 0 0;color:var(--foodex-muted);font-size:.75rem}
    .merchant-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:var(--foodex-space-3)}
    .merchant-kpi{display:block;padding:var(--foodex-space-3);min-height:92px;text-decoration:none}.merchant-kpi strong{display:block;font-size:.73rem;color:var(--foodex-muted)}.merchant-kpi b{display:block;margin-top:8px;font-size:1.45rem}.merchant-kpi small{display:block;margin-top:3px;color:var(--foodex-muted)}
    .merchant-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(280px,.8fr);gap:var(--foodex-space-3)}
    .merchant-recommendations{display:grid;gap:8px}.merchant-rec{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:10px;display:grid;grid-template-columns:minmax(150px,1.25fr) repeat(4,minmax(74px,.55fr)) auto;gap:9px;align-items:center}.merchant-rec strong{display:block}.merchant-rec small{color:var(--foodex-muted)}.merchant-rec-metric span{display:block;color:var(--foodex-muted);font-size:.66rem}.merchant-rec-metric b{font-size:.77rem}.merchant-rec-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.merchant-rec-actions a{min-height:34px;padding:0 10px;font-size:.7rem}.merchant-why{grid-column:1/-1;border-top:1px dashed var(--foodex-border);padding-top:7px}.merchant-why summary{cursor:pointer;color:var(--foodex-green-dark);font-weight:700}.merchant-facts{display:flex;flex-wrap:wrap;gap:6px;margin-top:7px}.merchant-fact{background:var(--foodex-background);border-radius:999px;padding:5px 8px;font-size:.67rem}
    .merchant-state{display:inline-flex;border-radius:999px;padding:4px 7px;font-size:.64rem;font-weight:700}.merchant-state.now{background:rgba(239,83,80,.10);color:var(--foodex-red)}.merchant-state.soon{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.merchant-state.healthy{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.merchant-state.blocked{background:rgba(75,140,245,.12);color:var(--foodex-blue)}.merchant-state.protect{background:var(--foodex-background);color:var(--foodex-muted)}
    .merchant-account-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.merchant-account-metric{padding:9px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-sm)}.merchant-account-metric span{display:block;color:var(--foodex-muted);font-size:.68rem}.merchant-account-metric b{display:block;margin-top:3px;font-size:.9rem}
    .merchant-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
    .merchant-compare{display:flex;gap:8px;align-items:flex-end;min-height:170px;overflow-x:auto;padding-top:10px}.merchant-compare-day{min-width:30px;flex:1;display:grid;grid-template-columns:1fr 1fr;gap:3px;align-items:end;height:150px;position:relative;padding-bottom:22px}.merchant-compare-day i{display:block;border-radius:4px 4px 2px 2px;min-height:2px}.merchant-compare-day i:first-child{background:var(--foodex-viz-primary)}.merchant-compare-day i:nth-child(2){background:var(--foodex-viz-info)}.merchant-compare-day small{position:absolute;bottom:0;inset-inline:0;text-align:center;color:var(--foodex-muted);font-size:.58rem}
    .merchant-risk-wrap{display:flex;align-items:center;justify-content:center;gap:20px;min-height:170px}.merchant-risk-list{display:grid;gap:8px;font-size:.72rem}.merchant-risk-list span{display:flex;justify-content:space-between;gap:20px}
    .merchant-simple-bars{display:grid;gap:10px}.merchant-simple-row{display:grid;grid-template-columns:minmax(90px,.8fr) 1.8fr auto;gap:9px;align-items:center;font-size:.72rem}.merchant-simple-track{height:11px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.merchant-simple-track i{height:100%;display:block;border-radius:inherit;background:var(--foodex-green)}.merchant-simple-row.warning .merchant-simple-track i{background:var(--foodex-orange)}.merchant-simple-row.danger .merchant-simple-track i{background:var(--foodex-red)}.merchant-simple-row.info .merchant-simple-track i{background:var(--foodex-blue)}
    .merchant-scatter{position:relative;min-height:190px;border-inline-start:1px solid var(--foodex-border);border-bottom:1px solid var(--foodex-border);margin:12px 14px 24px 28px;background:linear-gradient(to right,var(--foodex-viz-grid) 1px,transparent 1px),linear-gradient(to top,var(--foodex-viz-grid) 1px,transparent 1px);background-size:25% 25%}.merchant-dot{position:absolute;width:12px;height:12px;border-radius:50%;background:var(--foodex-green);transform:translate(-50%,50%);border:2px solid #fff;box-shadow:0 0 0 1px var(--foodex-border)}.merchant-dot[data-action="reorder_now"]{background:var(--foodex-red)}.merchant-dot[data-action="reorder_soon"]{background:var(--foodex-orange)}.merchant-scatter-x,.merchant-scatter-y{position:absolute;color:var(--foodex-muted);font-size:.62rem}.merchant-scatter-x{bottom:-22px;inset-inline:0;text-align:center}.merchant-scatter-y{inset-inline-start:-30px;top:50%;writing-mode:vertical-rl;transform:translateY(-50%)}
    .merchant-plan{overflow-x:auto}.merchant-plan table{width:100%;border-collapse:collapse;min-width:780px}.merchant-plan th,.merchant-plan td{padding:9px;border-bottom:1px solid var(--foodex-border);text-align:start;font-size:.72rem}.merchant-plan th{color:var(--foodex-muted);background:var(--foodex-background)}.merchant-plan strong{display:block}.merchant-plan small{color:var(--foodex-muted)}
    .merchant-secondary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}.merchant-mini-list{display:grid;gap:7px}.merchant-mini-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px;border-bottom:1px solid var(--foodex-border)}.merchant-mini-row:last-child{border-bottom:0}.merchant-mini-row small{display:block;color:var(--foodex-muted)}
    @media(max-width:1180px){.merchant-summary{grid-template-columns:repeat(3,minmax(0,1fr))}.merchant-grid,.merchant-charts{grid-template-columns:1fr}.merchant-rec{grid-template-columns:minmax(150px,1fr) repeat(2,minmax(80px,.5fr));}.merchant-rec-actions{grid-column:1/-1;justify-content:flex-start}}
    @media(max-width:720px){.merchant-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.merchant-secondary{grid-template-columns:1fr}.merchant-rec{grid-template-columns:1fr 1fr}.merchant-rec>div:first-child{grid-column:1/-1}.merchant-account-grid{grid-template-columns:1fr 1fr}}
</style>

<section class="merchant-intelligence" data-merchant-intelligence>
    <div class="merchant-section-head">
        <div>
            <h2>{{ $ar ? 'متجرك اليوم' : 'Your store today' }}</h2>
            <p>{{ $ar ? 'قرارات المخزون وإعادة الطلب مبنية على المبيعات الفعلية، المخزون الحالي ومدة توريد الجملة.' : 'Inventory and reorder decisions use actual sell-through, current stock and observed Wholesale lead time.' }}</p>
        </div>
        <span class="merchant-state healthy">{{ $ar ? 'مدة التوريد' : 'Lead time' }}: {{ number_format((float)data_get($merchant,'lead_time.expected_days',0),1) }} {{ $ar ? 'يوم' : 'days' }}</span>
    </div>

    <div class="merchant-summary">
        <a class="merchant-kpi foodex-viz-card" href="#merchant-recommendations"><strong>{{ $ar ? 'اطلب الآن' : 'Reorder now' }}</strong><b>{{ number_format((int)($summary['reorder_now'] ?? 0)) }}</b><small>{{ $ar ? 'منتجات معرضة لنفاد قريب' : 'Products inside lead-time risk' }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-recommendations"><strong>{{ $ar ? 'اطلب قريبًا' : 'Reorder soon' }}</strong><b>{{ number_format((int)($summary['reorder_soon'] ?? 0)) }}</b><small>{{ $ar ? 'تحت نقطة إعادة الطلب' : 'Below reorder point' }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-blockers"><strong>{{ $ar ? 'تحتاج تدخل' : 'Needs attention' }}</strong><b>{{ number_format((int)($summary['blocked'] ?? 0)) }}</b><small>{{ $ar ? 'ربط أو بيانات أو توفر' : 'Mapping, data or availability' }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#suggested-purchase-plan"><strong>{{ $ar ? 'خطة الشراء المتوقعة' : 'Expected reorder spend' }}</strong><b>{{ number_format((float)($summary['expected_reorder_spend'] ?? 0),3) }}</b><small>{{ data_get($account,'finance.currency',$dashboard['currency']) }}</small></a>
        <a class="merchant-kpi foodex-viz-card" href="#merchant-slow-movers"><strong>{{ $ar ? 'لا تطلب الآن' : 'Do not reorder' }}</strong><b>{{ number_format((int)($summary['do_not_reorder'] ?? 0)) }}</b><small>{{ $ar ? 'بطيء / زائد عن الحاجة' : 'Slow / overstock protection' }}</small></a>
    </div>

    <div class="merchant-grid">
        <article class="panel" id="merchant-recommendations">
            <div class="panel-title">
                <div><h2>{{ $ar ? 'ما الذي يحتاج انتباهك اليوم؟' : 'What needs attention today?' }}</h2><small>{{ $ar ? 'الأعلى أولوية أولًا، مع سبب القرار ومسار مباشر للمنتج.' : 'Highest priority first, with the decision reason and exact product context.' }}</small></div>
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
                        $actionLabel = $ar
                            ? match($action) {
                                'reorder_now' => 'اطلب الآن',
                                'reorder_soon' => 'اطلب قريبًا',
                                'do_not_reorder' => 'لا تطلب',
                                'missing_mapping' => 'الربط مفقود',
                                'invalid_mapping' => 'ربط غير صالح',
                                'ambiguous_mapping' => 'ربط غير واضح',
                                'insufficient_data' => 'بيانات غير كافية',
                                default => 'يحتاج مراجعة',
                            }
                            : match($action) {
                                'reorder_now' => 'Reorder now',
                                'reorder_soon' => 'Reorder soon',
                                'do_not_reorder' => 'Do not reorder',
                                'missing_mapping' => 'Missing mapping',
                                'invalid_mapping' => 'Invalid mapping',
                                'ambiguous_mapping' => 'Ambiguous mapping',
                                'insufficient_data' => 'Insufficient data',
                                default => 'Review needed',
                            };
                        $inventoryUrl = route('admin.b2c.module', array_merge([
                            'module'=>'inventory',
                            'store_id'=>$storeId,
                            'focus_product'=>(int)$row['retail_product_id'],
                        ], $supportAccess ? ['support_access'=>1] : []));
                    @endphp
                    <div class="merchant-rec">
                        <div><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small><br><span class="merchant-state {{ $stateClass }}">{{ $actionLabel }}</span></div>
                        <div class="merchant-rec-metric"><span>{{ $ar ? 'المتاح' : 'Available' }}</span><b>{{ number_format((float)data_get($row,'inventory.available',0),2) }}</b></div>
                        <div class="merchant-rec-metric"><span>{{ $ar ? 'البيع/يوم' : 'Units/day' }}</span><b>{{ number_format((float)data_get($row,'inventory.velocity_units_per_day',0),2) }}</b></div>
                        <div class="merchant-rec-metric"><span>{{ $ar ? 'أيام التغطية' : 'Days cover' }}</span><b>{{ data_get($row,'inventory.days_of_cover')===null ? '—' : number_format((float)data_get($row,'inventory.days_of_cover'),1) }}</b></div>
                        <div class="merchant-rec-metric"><span>{{ $ar ? 'الكمية المقترحة' : 'Suggested qty' }}</span><b>{{ number_format((float)data_get($row,'recommendation.recommended_wholesale_quantity',0),2) }}</b></div>
                        <div class="merchant-rec-actions">
                            <a class="foodex-action-secondary" href="{{ $inventoryUrl }}">{{ $ar ? 'فتح المخزون' : 'Open inventory' }}</a>
                            @if((bool)data_get($row,'recommendation.is_executable',false))
                                <a class="foodex-action-primary" href="#suggested-purchase-plan">{{ $ar ? 'فتح خطة الشراء' : 'Open purchase plan' }}</a>
                            @endif
                        </div>
                        <details class="merchant-why">
                            <summary>{{ $ar ? 'لماذا هذه التوصية؟' : 'Why this recommendation?' }}</summary>
                            <div class="merchant-facts">
                                @foreach((array)($row['reason_facts'] ?? []) as $key=>$value)
                                    @if(isset($factLabels[$key]))
                                        <span class="merchant-fact"><b>{{ $factLabels[$key] }}:</b>
                                            @if(is_bool($value)) {{ $value ? ($ar?'نعم':'Yes') : ($ar?'لا':'No') }}
                                            @elseif(is_numeric($value)) {{ number_format((float)$value,2) }}
                                            @else {{ $value }}
                                            @endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    </div>
                @empty
                    <div class="foodex-viz-empty">{{ $ar ? 'لا توجد توصيات تحتاج تدخلاً الآن.' : 'No recommendations need intervention right now.' }}</div>
                @endforelse
            </div>
        </article>

        @if((bool)data_get($merchant,'owner_context.is_owner'))
        <article class="panel" data-owner-wholesale-account>
            <div class="panel-title"><div><h2>{{ $ar ? 'حسابي لدى الجملة' : 'My Wholesale account' }}</h2><small>{{ $ar ? 'هذه البيانات شخصية للمالك ولا تظهر لموظف متجر التجزئة.' : 'Owner-only commercial finance; Retail staff do not receive this personal account context.' }}</small></div></div>
            @if($account)
                <strong>{{ $account['customer_name'] }}</strong>
                <p class="empty">{{ $ar ? 'شريحة السعر' : 'Price tier' }}: {{ $account['price_tier'] ?? ($ar?'غير محددة':'Not configured') }} · {{ $ar ? 'الحالة' : 'Status' }}: {{ $account['status'] }}</p>
                @if(data_get($account,'finance_status')==='available')
                    <div class="merchant-account-grid">
                        <div class="merchant-account-metric"><span>{{ $ar ? 'القوة الشرائية' : 'Purchasing power' }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.purchasing_power'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ $ar ? 'الائتمان المتاح' : 'Available credit' }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.available_credit_line'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ $ar ? 'الرصيد المفتوح' : 'Open amount' }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.open_amount'),3) }}</b></div>
                        <div class="merchant-account-metric"><span>{{ $ar ? 'المتأخر' : 'Overdue' }}</span><b>{{ data_get($account,'finance.currency') }} {{ number_format((float)data_get($account,'finance.overdue_amount'),3) }}</b></div>
                    </div>
                @else
                    <div class="foodex-viz-empty">{{ $ar ? 'الحساب مرتبط، لكن الملخص المالي غير متاح حاليًا.' : 'The account is linked, but its finance summary is currently unavailable.' }}</div>
                @endif
            @else
                <div class="foodex-viz-empty">{{ $ar ? 'ملكية المتجر مثبتة، لكن حساب الجملة الشخصي غير مكتمل الإعداد.' : 'Store ownership is confirmed, but the personal Wholesale account setup is incomplete.' }}</div>
            @endif
        </article>
        @endif
    </div>

    <div class="merchant-charts">
        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'مبيعات التجزئة مقابل مشتريات الجملة' : 'Retail sales vs Wholesale purchases' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'حتى 31 يومًا من الفترة المختارة.' : 'Up to the latest 31 days in the selected range.' }}</p></div></div>
            @if($compareSeries->isNotEmpty())
                <div class="merchant-compare" role="img" aria-label="{{ $ar ? 'مقارنة يومية بين قيمة مبيعات التجزئة وقيمة مشتريات الجملة' : 'Daily comparison of Retail sales and Wholesale purchase value' }}">
                    @foreach($compareSeries as $day)
                        <div class="merchant-compare-day" title="{{ $day['label'] }} · {{ number_format((float)$day['retail_sales'],3) }} / {{ number_format((float)$day['wholesale_purchases'],3) }}">
                            <i style="height:{{ max(2,round((float)$day['retail_sales']/$compareMax*126)) }}px"></i>
                            <i style="height:{{ max(2,round((float)$day['wholesale_purchases']/$compareMax*126)) }}px"></i>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
                <div class="foodex-viz-legend"><span class="foodex-viz-legend-item"><i class="foodex-viz-swatch"></i>{{ $ar ? 'مبيعات التجزئة' : 'Retail sales' }}</span><span class="foodex-viz-legend-item"><i class="foodex-viz-swatch" style="background:var(--foodex-blue)"></i>{{ $ar ? 'مشتريات الجملة المستلمة' : 'Received Wholesale purchases' }}</span></div>
            @else
                <div class="foodex-viz-empty">{{ $ar ? 'لا توجد حركة في الفترة.' : 'No activity in this range.' }}</div>
            @endif
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'توزيع مخاطر المخزون' : 'Stock-risk distribution' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'حرج / متوازن / زائد / غير كافٍ' : 'Critical / balanced / overstock / insufficient data' }}</p></div></div>
            <div class="merchant-risk-wrap">
                <div class="foodex-viz-donut" style="--foodex-viz-p1:{{ $riskP1 }}%;--foodex-viz-p2:{{ $riskP2 }}%;--foodex-viz-p3:{{ $riskP3 }}%" role="img" aria-label="{{ $ar ? 'توزيع حالات مخاطر المخزون' : 'Distribution of inventory risk states' }}"></div>
                <div class="merchant-risk-list">
                    <span><em>{{ $ar ? 'حرج / ناقص' : 'Critical / low' }}</em><b>{{ $riskCritical }}</b></span>
                    <span><em>{{ $ar ? 'متوازن' : 'Balanced' }}</em><b>{{ $riskHealthy }}</b></span>
                    <span><em>{{ $ar ? 'زائد' : 'Overstock' }}</em><b>{{ $riskOverstock }}</b></span>
                    <span><em>{{ $ar ? 'بيانات غير كافية' : 'Insufficient data' }}</em><b>{{ $riskUnknown }}</b></span>
                </div>
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'توقع إنفاق إعادة الطلب' : 'Reorder spend forecast' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'قيمة الكميات القابلة للتنفيذ وفق سعر الجملة الحالي.' : 'Executable quantities valued at the current authoritative Wholesale price.' }}</p></div></div>
            <div class="merchant-simple-bars">
                @foreach([
                    ['key'=>'reorder_now','label'=>$ar?'اطلب الآن':'Reorder now','class'=>'danger'],
                    ['key'=>'reorder_soon','label'=>$ar?'اطلب قريبًا':'Reorder soon','class'=>'warning'],
                    ['key'=>'availability_limited','label'=>$ar?'محدود بالتوفر':'Availability limited','class'=>'info'],
                ] as $bar)
                    @php $barValue=(float)($spend[$bar['key']] ?? 0); @endphp
                    <div class="merchant-simple-row {{ $bar['class'] }}"><span>{{ $bar['label'] }}</span><span class="merchant-simple-track"><i style="width:{{ round($barValue/$spendMax*100,1) }}%"></i></span><b>{{ number_format($barValue,3) }}</b></div>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'الهامش × سرعة البيع' : 'Margin × velocity' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'يساعد على تمييز المنتجات السريعة والمربحة عن المخزون منخفض العائد.' : 'Highlights fast, profitable products versus lower-return inventory.' }}</p></div></div>
            @if($marginVelocity->isNotEmpty())
                <div class="merchant-scatter" role="img" aria-label="{{ $ar ? 'علاقة هامش الربح بسرعة البيع' : 'Relationship between gross margin and sales velocity' }}">
                    @foreach($marginVelocity as $point)
                        @php
                            $left=8+min(1,(float)$point['velocity']/$maxVelocity)*84;
                            $bottom=8+min(1,max(0,(float)$point['margin_percent'])/$maxMargin)*84;
                        @endphp
                        <span class="merchant-dot" data-action="{{ $point['action'] }}" style="left:{{ round($left,1) }}%;bottom:{{ round($bottom,1) }}%" title="{{ $point['name'] }} · {{ number_format((float)$point['velocity'],2) }}/day · {{ number_format((float)$point['margin_percent'],1) }}%"></span>
                    @endforeach
                    <span class="merchant-scatter-x">{{ $ar ? 'سرعة البيع ←' : 'Sales velocity →' }}</span>
                    <span class="merchant-scatter-y">{{ $ar ? 'هامش الربح ←' : 'Gross margin →' }}</span>
                </div>
            @else
                <div class="foodex-viz-empty">{{ $ar ? 'لا توجد بيانات هامش كافية.' : 'No sufficient margin data.' }}</div>
            @endif
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'تقادم المخزون' : 'Inventory aging' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'كمية المخزون المتبقي حسب عمر الاستلام.' : 'Remaining on-hand quantity by receipt age.' }}</p></div></div>
            <div class="merchant-simple-bars">
                @foreach([
                    'days_0_7'=>$ar?'0–7 أيام':'0–7 days',
                    'days_8_30'=>$ar?'8–30 يوم':'8–30 days',
                    'days_31_60'=>$ar?'31–60 يوم':'31–60 days',
                    'days_61_plus'=>$ar?'61+ يوم':'61+ days',
                    'unknown'=>$ar?'عمر غير معروف':'Unknown age',
                ] as $key=>$label)
                    @php $qty=(float)data_get($aging,$key.'.quantity',0); @endphp
                    <div class="merchant-simple-row"><span>{{ $label }}</span><span class="merchant-simple-track"><i style="width:{{ round($qty/$agingMax*100,1) }}%"></i></span><b>{{ number_format($qty,2) }}</b></div>
                @endforeach
            </div>
        </article>

        <article class="foodex-viz-card">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title">{{ $ar ? 'مخاطر المبيعات المفقودة' : 'Lost-sales exposure' }}</h3><p class="foodex-viz-subtitle">{{ $ar ? 'تقدير قيمة المبيعات المعرضة للخطر قبل وصول التوريد.' : 'Estimated revenue at risk before replenishment can arrive.' }}</p></div></div>
            <div style="display:grid;place-items:center;min-height:170px;text-align:center">
                <div><strong style="font-size:1.8rem">{{ data_get($account,'finance.currency',$dashboard['currency']) }} {{ number_format((float)($summary['lost_sales_risk_revenue'] ?? 0),3) }}</strong><p class="empty">{{ $ar ? 'استنادًا إلى سرعة البيع وأيام التغطية ومدة التوريد.' : 'Based on velocity, days of cover and expected lead time.' }}</p></div>
            </div>
        </article>
    </div>

    <article class="panel" id="suggested-purchase-plan">
        <div class="panel-title"><div><h2>{{ $ar ? 'خطة شراء الجملة المقترحة' : 'Suggested Wholesale purchase plan' }}</h2><small>{{ $ar ? 'خطة قابلة للتنفيذ حسب MOQ وخطوة الطلب والتوفر الحالي. لا يتم إنشاء طلب تلقائيًا.' : 'Executable against MOQ, ordering increment and live availability. No order is placed automatically.' }}</small></div></div>
        <div class="merchant-plan">
            @if($executablePlan->isNotEmpty())
            <table>
                <thead><tr><th>{{ $ar?'منتج التجزئة':'Retail product' }}</th><th>{{ $ar?'مصدر الجملة':'Wholesale source' }}</th><th>{{ $ar?'كمية الجملة':'Wholesale qty' }}</th><th>{{ $ar?'ما يعادل وحدات التجزئة':'Retail equivalent' }}</th><th>{{ $ar?'كراتين تقريبية':'Case equivalent' }}</th><th>{{ $ar?'التكلفة المتوقعة':'Expected cost' }}</th><th>{{ $ar?'الأولوية':'Priority' }}</th></tr></thead>
                <tbody>
                @foreach($executablePlan as $row)
                    <tr>
                        <td><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small></td>
                        <td><strong>{{ data_get($row,'mapping.source_name','—') }}</strong><small>{{ data_get($row,'mapping.source_sku','') }}</small></td>
                        <td>{{ number_format((float)data_get($row,'recommendation.recommended_wholesale_quantity',0),2) }}</td>
                        <td>{{ number_format((float)data_get($row,'recommendation.recommended_retail_equivalent',0),2) }}</td>
                        <td>{{ data_get($row,'commercial.recommended_case_equivalent')===null?'—':number_format((float)data_get($row,'commercial.recommended_case_equivalent'),2) }}</td>
                        <td>{{ number_format((float)data_get($row,'recommendation.expected_cost',0),3) }}</td>
                        <td>{{ number_format((float)($row['priority_score'] ?? 0),1) }}/100</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @else
                <div class="foodex-viz-empty">{{ $ar ? 'لا توجد كميات شراء قابلة للتنفيذ الآن.' : 'There are no executable purchase quantities right now.' }}</div>
            @endif
        </div>
    </article>

    <div class="merchant-secondary" id="merchant-blockers">
        <article class="panel">
            <div class="panel-title"><div><h2>{{ $ar ? 'منتجات تحتاج ربط الجملة' : 'Products needing Wholesale mapping' }}</h2><small>{{ $ar ? 'لا يتم تخمين مصدر بديل.' : 'FOODEX does not guess a substitute source.' }}</small></div></div>
            <div class="merchant-mini-list">
                @forelse(data_get($merchant,'sections.missing_mapping',[]) as $row)
                    <div class="merchant-mini-row"><span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }}</small></span><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">{{ $ar?'فتح المنتج':'Open product' }}</a></div>
                @empty <div class="empty">{{ $ar ? 'كل المنتجات النشطة لديها ربط صالح أو لا تحتاج إعادة طلب.' : 'No active product currently has a missing mapping blocker.' }}</div> @endforelse
            </div>
        </article>
        <article class="panel" id="merchant-slow-movers">
            <div class="panel-title"><div><h2>{{ $ar ? 'بطيء / مخزون زائد' : 'Slow movers / overstock' }}</h2><small>{{ $ar ? 'توصيات حماية رأس المال: لا تطلب الآن.' : 'Working-capital protection: do not reorder now.' }}</small></div></div>
            <div class="merchant-mini-list">
                @forelse(data_get($merchant,'sections.slow_movers',[]) as $row)
                    <div class="merchant-mini-row"><span><strong>{{ $row['name'] }}</strong><small>{{ $row['sku'] }} · {{ data_get($row,'inventory.days_of_cover')===null?'—':number_format((float)data_get($row,'inventory.days_of_cover'),1).' '.($ar?'يوم':'days') }}</small></span><a class="section-link" href="{{ route('admin.b2c.module',array_merge(['module'=>'inventory','store_id'=>$storeId,'focus_product'=>(int)$row['retail_product_id']],$supportAccess?['support_access'=>1]:[])) }}">{{ $ar?'فتح المخزون':'Open inventory' }}</a></div>
                @empty <div class="empty">{{ $ar ? 'لا توجد منتجات بطيئة أو زائدة حاليًا.' : 'No slow-mover or overstock recommendations right now.' }}</div> @endforelse
            </div>
        </article>
    </div>
</section>
@endif
