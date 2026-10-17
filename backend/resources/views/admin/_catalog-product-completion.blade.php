@php
    $catalogDashboard = $catalogDashboard ?? null;
    $attributeDefinitions = $attributeDefinitions ?? null;
    $product360 = $product360 ?? null;
    $category360 = $category360 ?? null;
    $importRuns = $importRuns ?? null;
    $chartTitles = [
        'category_mix' => __('catalog_completion.charts.category_mix'),
        'brand_mix' => __('catalog_completion.charts.brand_mix'),
        'availability' => __('catalog_completion.charts.availability'),
        'margin_distribution' => __('catalog_completion.charts.margin_distribution'),
        'sales_by_product' => __('catalog_completion.charts.sales_by_product'),
        'sales_by_category' => __('catalog_completion.charts.sales_by_category'),
        'import_errors' => __('catalog_completion.charts.import_errors'),
    ];
@endphp

@if($tab === 'overview' && $catalogDashboard)
<section class="foodex-module-overview" data-foodex-module-overview data-state="{{ $catalogDashboard['state'] }}">
    @php
        $scopeOptions = ['' => __('catalog_completion.common.all_authorized_stores')];
        foreach ($stores as $catalogScopeStore) {
            $scopeOptions[(string) $catalogScopeStore->id] = $catalogScopeStore->name;
        }
        $moduleDashboardFilters = [
            'action' => route('admin.catalog.index'),
            'scope_name' => 'store_id',
            'scope_value' => (string) request('store_id', ''),
            'scope_options' => $scopeOptions,
            'from_value' => $catalogDashboard['selected_from'],
            'to_value' => $catalogDashboard['selected_to'],
            'hidden' => ['tab' => 'overview'] + (!empty($scopeParams['support_access']) ? ['support_access' => 1] : []),
            'reset_url' => route('admin.catalog.index', ['tab' => 'overview'] + (!empty($scopeParams['support_access']) ? ['support_access' => 1] : [])),
            'export_url' => $reportsUrl,
        ];
    @endphp

    @include('admin._module-dashboard-filters', ['moduleDashboardFilters' => $moduleDashboardFilters])

    @if($catalogDashboard['state'] !== 'current')
        @include('admin._module-dashboard-state', ['state' => $catalogDashboard['state']])
    @else
        <div class="foodex-module-kpis">
            @foreach($catalogDashboard['kpis'] as $catalogMetric)
                @php
                    $metricParams = array_merge(
                        ['tab' => $catalogMetric['tab']],
                        $scopeParams,
                        $catalogMetric['filters'] ?? []
                    );
                    $metric = [
                        'label' => __('catalog_completion.kpis.'.$catalogMetric['key']),
                        'value' => $catalogMetric['value'],
                        'last_refresh' => $catalogMetric['last_refresh'],
                        'drilldown_url' => route('admin.catalog.index', $metricParams),
                    ];
                @endphp
                @include('admin._module-dashboard-kpi', ['metric' => $metric])
            @endforeach
        </div>

        <div class="foodex-module-panels" style="grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr))">
            @foreach($catalogDashboard['charts'] as $chartKey => $chartRows)
                <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart="{{ $chartKey }}">
                    <div class="foodex-viz-header">
                        <div>
                            <h2 class="foodex-viz-title">{{ $chartTitles[$chartKey] ?? $chartKey }}</h2>
                            <p class="foodex-viz-subtitle">{{ $catalogDashboard['selected_from'] }} — {{ $catalogDashboard['selected_to'] }}</p>
                        </div>
                    </div>
                    @if($chartRows === [])
                        <div class="foodex-viz-empty">{{ __('catalog_completion.common.empty') }}</div>
                    @else
                        @php($chartMax = max(1, (float) collect($chartRows)->max('value')))
                        <div class="foodex-viz-bars" role="img" aria-label="{{ $chartTitles[$chartKey] ?? $chartKey }}">
                            @foreach($chartRows as $point)
                                @php
                                    $rawLabel = (string) ($point['label'] ?? '');
                                    $labelKey = $rawLabel !== '' ? $rawLabel : ($chartKey === 'brand_mix' ? 'unbranded' : 'uncategorized');
                                    $translated = __('catalog_completion.chart_labels.'.$labelKey);
                                    $pointLabel = $translated === 'catalog_completion.chart_labels.'.$labelKey ? ($rawLabel !== '' ? $rawLabel : '—') : $translated;
                                    $barPercent = max(2, min(100, ((float) $point['value'] / $chartMax) * 100));
                                @endphp
                                <div class="foodex-viz-bar-row">
                                    <span class="foodex-viz-bar-label">{{ $pointLabel }}</span>
                                    <div class="foodex-viz-bar-track"><span class="foodex-viz-bar" style="--foodex-viz-value:{{ $barPercent }}%"></span></div>
                                    <strong class="foodex-number">{{ $point['value'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                        <div class="foodex-module-chart-table-wrap" data-pagination-exempt="bounded-catalog-dashboard-series">
                            <table class="foodex-module-chart-table">
                                <thead><tr><th>{{ __('catalog_completion.common.reference') }}</th><th>{{ __('catalog_completion.common.value') }}</th></tr></thead>
                                <tbody>
                                @foreach($chartRows as $point)
                                    @php
                                        $rawLabel = (string) ($point['label'] ?? '');
                                        $labelKey = $rawLabel !== '' ? $rawLabel : ($chartKey === 'brand_mix' ? 'unbranded' : 'uncategorized');
                                        $translated = __('catalog_completion.chart_labels.'.$labelKey);
                                        $pointLabel = $translated === 'catalog_completion.chart_labels.'.$labelKey ? ($rawLabel !== '' ? $rawLabel : '—') : $translated;
                                    @endphp
                                    <tr><td>{{ $pointLabel }}</td><td class="foodex-number">{{ $point['value'] }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>

        <article class="card">
            <h2>{{ __('catalog_completion.exceptions.title') }}</h2>
            <div class="catalog-completion-lines">
                @foreach($catalogDashboard['exceptions'] as $exception)
                    @php($exceptionParams = array_merge(['tab' => $exception['tab']], $scopeParams, $exception['filters'] ?? []))
                    <a class="catalog-completion-line" href="{{ route('admin.catalog.index', $exceptionParams) }}">
                        <strong>{{ __('catalog_completion.exceptions.'.$exception['key']) }}</strong>
                        <span class="foodex-number">{{ $exception['count'] }}</span>
                    </a>
                @endforeach
            </div>
        </article>

        <article class="card">
            <div class="catalog-completion-heading">
                <div>
                    <h2>{{ __('catalog_completion.reports.title') }}</h2>
                    <p class="muted">{{ __('catalog_completion.subtitle') }}</p>
                </div>
                <a class="btn" href="{{ $reportsUrl }}">{{ __('catalog_completion.reports.open_bi') }}</a>
            </div>
            <div class="catalog-report-grid">
                @foreach(['availability','category_brand_performance','margin_exceptions','lifecycle','data_quality'] as $catalogReport)
                    <a class="catalog-report-card" href="{{ route('admin.erp.bi', ['report' => 'catalog.'.$catalogReport] + (request('store_id') ? ['store_id' => request('store_id')] : [])) }}">
                        <strong>{{ __('catalog_completion.reports.'.$catalogReport.'.title') }}</strong>
                        <span>{{ __('catalog_completion.reports.'.$catalogReport.'.description') }}</span>
                    </a>
                @endforeach
            </div>
        </article>
    @endif
</section>
@endif

@if($tab === 'attributes' && $attributeDefinitions)
<div class="grid catalog-completion-attributes">
    <section class="card">
        <h2>{{ __('catalog_completion.attributes.create') }}</h2>
        <p class="muted">{{ __('catalog_completion.attributes.description') }}</p>
        <form class="form catalog-completion-mutation" method="post" action="{{ route('admin.catalog.attributes.store') }}" data-catalog-scope-form>
            @csrf
            @if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
            <label>{{ __('catalog_completion.product_360.store') }}
                <select name="store_id" required data-catalog-store-context>
                    @if($stores->count() > 1)<option value="">—</option>@endif
                    @foreach($stores as $attributeStore)
                        <option value="{{ $attributeStore->id }}" data-catalog-id="{{ $attributeStore->default_catalog_id ?? '' }}" data-channel="{{ strtolower((string) $attributeStore->type_code) }}" @selected((int) request('store_id') === (int) $attributeStore->id || $stores->count() === 1)>{{ $attributeStore->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('catalog_completion.attributes.category') }}
                <select name="category_id" data-catalog-dependent>
                    <option value="">{{ __('catalog_completion.attributes.all_categories') }}</option>
                    @foreach($categories as $attributeCategory)
                        <option value="{{ $attributeCategory->id }}" data-catalog-id="{{ $attributeCategory->catalog_id }}">{{ $attributeCategory->name }} — {{ $attributeCategory->catalog_store_name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="row">
                <label>{{ __('catalog_completion.attributes.code') }}<input name="code" maxlength="80" pattern="[A-Za-z0-9._-]+" required></label>
                <label>{{ __('catalog_completion.attributes.type') }}
                    <select name="data_type" required>
                        @foreach(['text','number','boolean','date','select','multi_select'] as $attributeType)
                            <option value="{{ $attributeType }}">{{ __('catalog_completion.attributes.types.'.$attributeType) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="row">
                <label>{{ __('catalog_completion.attributes.label_ar') }}<input name="label_ar" maxlength="255" dir="rtl" required></label>
                <label>{{ __('catalog_completion.attributes.label_en') }}<input name="label_en" maxlength="255" dir="ltr" required></label>
            </div>
            <label>{{ __('catalog_completion.attributes.allowed_values') }}
                <textarea name="allowed_values" placeholder="{{ __('catalog_completion.attributes.allowed_values_hint') }}"></textarea>
            </label>
            <div class="row">
                <label class="check"><input type="checkbox" name="is_required" value="1">{{ __('catalog_completion.attributes.required') }}</label>
                <label class="check"><input type="checkbox" name="is_active" value="1" checked>{{ __('catalog_completion.attributes.active') }}</label>
            </div>
            <button class="btn primary">{{ __('catalog_completion.attributes.create') }}</button>
        </form>
    </section>

    <section class="card table-wrap">
        <h2>{{ __('catalog_completion.attributes.title') }}</h2>
        <table class="table" data-pagination-required>
            <thead><tr><th>{{ __('catalog_completion.attributes.code') }}</th><th>{{ __('catalog_completion.attributes.type') }}</th><th>{{ __('catalog_completion.attributes.category') }}</th><th>{{ __('catalog_completion.common.actions') }}</th></tr></thead>
            <tbody>
            @forelse($attributeDefinitions as $attribute)
                @php($allowedValues = $attribute->allowed_values ? (json_decode((string) $attribute->allowed_values, true) ?: []) : [])
                <tr>
                    <td><strong>{{ $attribute->code }}</strong><div class="muted">{{ app()->getLocale()==='ar' ? $attribute->label_ar : $attribute->label_en }}</div></td>
                    <td>{{ __('catalog_completion.attributes.types.'.$attribute->data_type) }}</td>
                    <td>{{ $attribute->category_name ?: __('catalog_completion.attributes.all_categories') }}<div class="muted">{{ $attribute->store_name }}</div></td>
                    <td>
                        <details class="catalog-actions">
                            <summary aria-label="{{ __('catalog_completion.common.actions') }}">⋮</summary>
                            <div class="catalog-action-menu catalog-attribute-menu">
                                <form class="form catalog-completion-mutation" method="post" action="{{ route('admin.catalog.attributes.update', $attribute->id) }}">
                                    @csrf @method('PATCH')
                                    @if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
                                    <input name="code" value="{{ $attribute->code }}" required>
                                    <select name="data_type">@foreach(['text','number','boolean','date','select','multi_select'] as $attributeType)<option value="{{ $attributeType }}" @selected($attribute->data_type===$attributeType)>{{ __('catalog_completion.attributes.types.'.$attributeType) }}</option>@endforeach</select>
                                    <input name="label_ar" value="{{ $attribute->label_ar }}" dir="rtl" required>
                                    <input name="label_en" value="{{ $attribute->label_en }}" dir="ltr" required>
                                    <select name="category_id"><option value="">{{ __('catalog_completion.attributes.all_categories') }}</option>@foreach($categories->where('catalog_id',$attribute->catalog_id) as $attributeCategory)<option value="{{ $attributeCategory->id }}" @selected((int)$attribute->category_id===(int)$attributeCategory->id)>{{ $attributeCategory->name }}</option>@endforeach</select>
                                    <textarea name="allowed_values">{{ implode("\n", $allowedValues) }}</textarea>
                                    <label class="check"><input type="checkbox" name="is_required" value="1" @checked($attribute->is_required)>{{ __('catalog_completion.attributes.required') }}</label>
                                    <label class="check"><input type="checkbox" name="is_active" value="1" @checked($attribute->is_active)>{{ __('catalog_completion.attributes.active') }}</label>
                                    <button class="btn primary">{{ __('catalog_completion.attributes.save') }}</button>
                                </form>
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4"><div class="catalog-empty">{{ __('catalog_completion.attributes.no_attributes') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $attributeDefinitions->links() }}
    </section>
</div>
@endif

@if($tab === 'products' && $product360)
@php($productDetail = $product360['product'])
<section class="card catalog-360" data-catalog-product-360>
    <div class="catalog-completion-heading">
        <div>
            <h2>{{ __('catalog_completion.product_360.title') }} · {{ $productDetail->name }}</h2>
            <p class="muted">{{ $productDetail->sku }} · {{ $productDetail->store_id }} · {{ $productDetail->channel }}</p>
        </div>
        <a class="btn" href="{{ route('admin.catalog.index', array_merge(['tab'=>'products'], $scopeParams)) }}">×</a>
    </div>

    <div class="catalog-360-grid">
        <article>
            <h3>{{ __('catalog_completion.product_360.identity') }}</h3>
            <dl class="catalog-360-facts">
                <div><dt>{{ __('catalog_completion.product_360.store') }}</dt><dd>{{ $stores->firstWhere('id',$productDetail->store_id)?->name ?? '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.channel') }}</dt><dd>{{ strtoupper((string)$productDetail->channel) }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.category') }}</dt><dd>{{ $productDetail->category_name ?: '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.brand') }}</dt><dd>{{ $productDetail->brand_name ?: '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.unit') }}</dt><dd>{{ $productDetail->unit_name ?: '—' }} @if($productDetail->unit_code)({{ $productDetail->unit_code }})@endif</dd></div>
            </dl>
        </article>
        <article>
            <h3>{{ __('catalog_completion.product_360.availability') }}</h3>
            <dl class="catalog-360-facts">
                <div><dt>{{ __('catalog_completion.product_360.available_quantity') }}</dt><dd>{{ AppSupportDisplayNumber::quantity((float)($productDetail->available_quantity ?? 0)) }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.price') }}</dt><dd>{{ $productDetail->price ?? '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.cost') }}</dt><dd>{{ $productDetail->cost_price ?? '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.lifecycle.state') }}</dt><dd>{{ __('catalog_completion.lifecycle.states.'.$productDetail->lifecycle_state) }}</dd></div>
            </dl>
        </article>
        <article>
            <h3>{{ __('catalog_completion.product_360.commercial') }}</h3>
            <dl class="catalog-360-facts">
                <div><dt>{{ __('catalog_completion.product_360.commercial_status') }}</dt><dd>{{ $productDetail->commercial_status ?: '—' }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.selling_units') }}</dt><dd>{{ $product360['selling_units']->count() }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.availability_windows') }}</dt><dd>{{ $product360['availability_windows']->count() }}</dd></div>
                <div><dt>{{ __('catalog_completion.product_360.targeting_rules') }}</dt><dd>{{ $product360['commercial_rules']->count() }}</dd></div>
            </dl>
            @if(strtolower((string)$productDetail->channel)==='b2b')
                <a class="btn" href="{{ route('admin.commercial.sales-control', ['store_id'=>$productDetail->store_id,'product'=>$productDetail->id]) }}">{{ __('catalog_completion.product_360.open_sales_control') }}</a>
            @endif
        </article>
    </div>

    <div class="catalog-360-grid">
        <article>
            <h3>{{ __('catalog_completion.lifecycle.title') }}</h3>
            <form class="form catalog-completion-mutation" method="post" action="{{ route('admin.catalog.products.lifecycle',$productDetail->id) }}">
                @csrf
                @if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
                <label>{{ __('catalog_completion.lifecycle.state') }}
                    <select name="state" required>
                        @foreach(['draft','active','paused','end_of_life'] as $lifecycleState)<option value="{{ $lifecycleState }}" @selected($productDetail->lifecycle_state===$lifecycleState)>{{ __('catalog_completion.lifecycle.states.'.$lifecycleState) }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('catalog_completion.lifecycle.effective_at') }}<input type="datetime-local" name="effective_at"></label>
                <label>{{ __('catalog_completion.lifecycle.reason') }}<textarea name="reason" maxlength="2000"></textarea></label>
                <button class="btn primary">{{ __('catalog_completion.lifecycle.transition') }}</button>
            </form>
        </article>
        <article>
            <h3>{{ __('catalog_completion.product_360.attributes') }}</h3>
            <form class="form catalog-completion-mutation" method="post" action="{{ route('admin.catalog.products.attributes.save',$productDetail->id) }}">
                @csrf @method('PUT')
                @if(!empty($scopeParams['support_access']))<input type="hidden" name="support_access" value="1">@endif
                @forelse($product360['attributes'] as $definition)
                    @php($currentValue=$definition->decoded_value)
                    <label>{{ app()->getLocale()==='ar' ? $definition->label_ar : $definition->label_en }} @if($definition->is_required)<span aria-hidden="true">*</span>@endif
                        @if($definition->data_type==='boolean')
                            <select name="attributes[{{ $definition->id }}]" @required($definition->is_required)><option value="">—</option><option value="1" @selected($currentValue===true)>{{ __('catalog_completion.common.yes') }}</option><option value="0" @selected($currentValue===false)>{{ __('catalog_completion.common.no') }}</option></select>
                        @elseif($definition->data_type==='select')
                            <select name="attributes[{{ $definition->id }}]" @required($definition->is_required)><option value="">—</option>@foreach($definition->decoded_allowed_values as $allowedValue)<option value="{{ $allowedValue }}" @selected($currentValue===$allowedValue)>{{ $allowedValue }}</option>@endforeach</select>
                        @elseif($definition->data_type==='multi_select')
                            <select name="attributes[{{ $definition->id }}][]" multiple @required($definition->is_required)>@foreach($definition->decoded_allowed_values as $allowedValue)<option value="{{ $allowedValue }}" @selected(is_array($currentValue) && in_array($allowedValue,$currentValue,true))>{{ $allowedValue }}</option>@endforeach</select>
                        @else
                            <input type="{{ $definition->data_type==='number'?'number':($definition->data_type==='date'?'date':'text') }}" name="attributes[{{ $definition->id }}]" value="{{ is_scalar($currentValue)?$currentValue:'' }}" @required($definition->is_required)>
                        @endif
                    </label>
                @empty
                    <p class="muted">{{ __('catalog_completion.attributes.no_attributes') }}</p>
                @endforelse
                @if($product360['attributes']->isNotEmpty())<button class="btn primary">{{ __('catalog_completion.product_360.save_attributes') }}</button>@endif
            </form>
        </article>
    </div>

    <div class="catalog-360-grid">
        <article>
            <h3>{{ __('catalog_completion.lifecycle.history') }}</h3>
            <div class="table-wrap" data-pagination-exempt="bounded-product-lifecycle-history">
                <table class="table"><thead><tr><th>{{ __('catalog_completion.lifecycle.state') }}</th><th>{{ __('catalog_completion.lifecycle.effective_at') }}</th><th>{{ __('catalog_completion.lifecycle.reason') }}</th></tr></thead><tbody>
                @forelse($product360['lifecycle'] as $transition)<tr><td>{{ __('catalog_completion.lifecycle.states.'.$transition->to_state) }}</td><td>{{ $transition->effective_at }}</td><td>{{ $transition->reason ?: '—' }}</td></tr>@empty<tr><td colspan="3">{{ __('catalog_completion.common.empty') }}</td></tr>@endforelse
                </tbody></table>
            </div>
        </article>
        <article>
            <h3>{{ __('catalog_completion.product_360.orders') }}</h3>
            <div class="table-wrap" data-pagination-exempt="bounded-product-recent-orders">
                <table class="table"><thead><tr><th>{{ __('catalog_completion.common.reference') }}</th><th>{{ __('catalog_completion.common.value') }}</th></tr></thead><tbody>
                @forelse($product360['recent_orders'] as $order)<tr><td>{{ $order->order_number }}</td><td>{{ $order->quantity }} · {{ $order->line_total }} {{ $order->currency }}</td></tr>@empty<tr><td colspan="2">{{ __('catalog_completion.common.empty') }}</td></tr>@endforelse
                </tbody></table>
            </div>
        </article>
    </div>
</section>
@endif

@if($tab === 'categories' && $category360)
@php($categoryDetail=$category360['category'])
<section class="card catalog-360" data-catalog-category-360>
    <div class="catalog-completion-heading">
        <div><h2>{{ __('catalog_completion.category_360.title') }} · {{ $categoryDetail->name }}</h2><p class="muted">{{ $categoryDetail->store_name }} · {{ $categoryDetail->parent_name ?: '—' }}</p></div>
        <a class="btn" href="{{ route('admin.catalog.index', array_merge(['tab'=>'categories'], $scopeParams)) }}">×</a>
    </div>
    <div class="foodex-module-kpis">
        @foreach([
            ['key'=>'products','value'=>$category360['product_count']],
            ['key'=>'active_products','value'=>$category360['active_count']],
            ['key'=>'unpriced_products','value'=>$category360['unpriced_count']],
        ] as $categoryMetric)
            @include('admin._module-dashboard-kpi',['metric'=>['label'=>__('catalog_completion.category_360.'.$categoryMetric['key']),'value'=>$categoryMetric['value']]])
        @endforeach
    </div>
    <div class="catalog-360-grid">
        <article><h3>{{ __('catalog_completion.category_360.subcategories') }}</h3><div class="catalog-completion-lines">@forelse($category360['subcategories'] as $child)<span class="catalog-completion-line"><strong>{{ $child->name }}</strong></span>@empty<p class="muted">{{ __('catalog_completion.common.empty') }}</p>@endforelse</div></article>
        <article><h3>{{ __('catalog_completion.category_360.attributes') }}</h3><div class="catalog-completion-lines">@forelse($category360['attributes'] as $attribute)<span class="catalog-completion-line"><strong>{{ app()->getLocale()==='ar'?$attribute->label_ar:$attribute->label_en }}</strong><span>{{ __('catalog_completion.attributes.types.'.$attribute->data_type) }}</span></span>@empty<p class="muted">{{ __('catalog_completion.common.empty') }}</p>@endforelse</div></article>
        <article><h3>{{ __('catalog_completion.category_360.top_products') }}</h3><div class="catalog-completion-lines">@forelse($category360['top_products'] as $topProduct)<a class="catalog-completion-line" href="{{ route('admin.catalog.index',array_merge(['tab'=>'products','product_detail'=>$topProduct->id],$scopeParams)) }}"><strong>{{ $topProduct->name }}</strong><span>{{ $topProduct->revenue }}</span></a>@empty<p class="muted">{{ __('catalog_completion.common.empty') }}</p>@endforelse</div></article>
    </div>
</section>
@endif

@if($tab === 'import' && $importRuns)
<section class="card catalog-import-history">
    <div class="catalog-completion-heading">
        <div><h2>{{ __('catalog_completion.import.history') }}</h2><p class="muted">{{ __('catalog_completion.import.retry_note') }}</p></div>
        <a class="btn" href="{{ route('admin.catalog.export',$scopeParams) }}">{{ __('catalog_completion.import.export') }}</a>
    </div>
    <div class="table-wrap">
        <table class="table" data-pagination-required>
            <thead><tr><th>{{ __('catalog_completion.import.created_at') }}</th><th>{{ __('catalog_completion.import.status') }}</th><th>{{ __('catalog_completion.import.counts') }}</th><th>{{ __('catalog_completion.import.errors') }}</th></tr></thead>
            <tbody>
            @forelse($importRuns as $run)
                @php($runCounts=$run->counts_json ? (json_decode((string)$run->counts_json,true) ?: []) : [])
                @php($runErrors=$run->errors_json ? (json_decode((string)$run->errors_json,true) ?: []) : [])
                <tr><td>{{ $run->created_at }}</td><td>{{ __('catalog_completion.import.statuses.'.$run->status) }}</td><td>{{ array_sum(array_map('intval',$runCounts)) }}</td><td>{{ count($runErrors) }}</td></tr>
            @empty
                <tr><td colspan="4">{{ __('catalog_completion.common.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $importRuns->links() }}
</section>
@endif

<style>
.catalog-completion-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
.catalog-completion-heading h2{margin-top:0}
.catalog-completion-lines{display:grid;gap:8px}
.catalog-completion-line{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:10px;text-decoration:none;color:var(--foodex-ink);background:var(--foodex-surface)}
.catalog-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));gap:10px}
.catalog-report-card{display:grid;gap:5px;padding:13px;border:1px solid var(--foodex-border);border-radius:12px;text-decoration:none;color:var(--foodex-ink)}
.catalog-report-card span{color:var(--foodex-muted);font-size:.85rem}
.catalog-360{display:grid;gap:16px;margin-top:16px}
.catalog-360-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:12px}
.catalog-360-grid>article{border:1px solid var(--foodex-border);border-radius:12px;padding:14px;min-width:0}
.catalog-360-grid h3{margin-top:0}
.catalog-360-facts{display:grid;gap:8px;margin:0}
.catalog-360-facts div{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--foodex-border);padding-bottom:7px}
.catalog-360-facts dt{color:var(--foodex-muted)}.catalog-360-facts dd{margin:0;font-weight:700;text-align:end}
.catalog-attribute-menu{width:min(440px,calc(100vw - 40px));max-height:70vh;overflow:auto}
.catalog-attribute-menu .form{padding:8px}
.catalog-completion-attributes{align-items:start}
.catalog-import-history{margin-top:16px}
@media(max-width:520px){.catalog-completion-heading>.btn{width:100%;text-align:center}.catalog-360-facts div{display:grid;gap:3px}.catalog-360-facts dd{text-align:start}}
</style>

<script>
document.addEventListener('submit', (event) => {
    const form = event.target.closest('.catalog-completion-mutation');
    if (!form || form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type="submit"],button:not([type])').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    });
});
</script>
