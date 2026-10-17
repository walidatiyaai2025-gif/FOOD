<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('reports.title') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#172033}.layout{display:grid;grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr);min-height:100vh;width:100%}.sidebar{background:#111827;color:#fff;padding:20px}.main{min-width:0;width:100%;padding:clamp(16px,1.8vw,32px);overflow:hidden}.hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:20px}.hero h1{margin:0;font-size:30px}.muted{color:#667085}.families{display:flex;gap:9px;flex-wrap:wrap;margin:16px 0}.pill{display:inline-flex;padding:9px 13px;border-radius:999px;background:#fff;border:1px solid #dce2ea;text-decoration:none;color:#344054;font-weight:650}.pill.active{background:#101828;color:#fff;border-color:#101828}.panel{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(16,24,40,.04);margin-bottom:18px}.filters{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}.filters label{font-size:12px;font-weight:700;display:block;margin-bottom:5px}.filters input,.filters select{width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:9px;background:#fff}.actions{display:flex;gap:8px;align-items:flex-end}.button{border:0;border-radius:10px;background:#101828;color:#fff;padding:10px 14px;text-decoration:none;display:inline-block;cursor:pointer}.button.secondary{background:#eef2f6;color:#344054}.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}.kpi{background:linear-gradient(145deg,#fff,#f8fafc);border:1px solid #e4e7ec;border-radius:16px;padding:16px}.kpi strong{display:block;font-size:24px;margin-top:5px}.export{display:flex;gap:8px;flex-wrap:wrap}.table-wrap{overflow:auto;border-radius:12px;border:1px solid #e4e7ec}table{width:100%;border-collapse:collapse;min-width:780px}th,td{padding:11px 12px;border-bottom:1px solid #eef2f6;text-align:start;white-space:nowrap}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.03em}.empty{text-align:center;padding:42px;color:#667085}.meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.warning{background:#fff7ed;color:#9a3412;border-radius:10px;padding:10px}.breakdowns{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.mini{border:1px solid #e4e7ec;border-radius:14px;padding:14px}.mini h3{margin-top:0}@media(max-width:1050px){.filters{grid-template-columns:repeat(2,1fr)}}@media(max-width:1023px){.layout{grid-template-columns:1fr}.main{padding:18px}.filters{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}}
    </style>
    @include('admin._brand-components')
</head>
<body>
@php
$reportLabel = static function ($value): string {
    $key = strtolower(trim((string) ($value ?? '')));
    if ($key === '') {
        return '—';
    }

    $translated = __('reports.business_labels.'.$key);

    return $translated !== 'reports.business_labels.'.$key
        ? $translated
        : ucwords(str_replace(['_', '-'], ' ', $key));
};
$breakdownHeader = static function (string $key): string {
    $translated = __('reports.breakdown_headers.'.$key);

    return $translated !== 'reports.breakdown_headers.'.$key
        ? $translated
        : ucwords(str_replace(['_', '-'], ' ', $key));
};
$moneyFields = [
    'total', 'revenue', 'value', 'amount', 'gross_value',
    'recognized_revenue', 'average_order_value', 'product_revenue',
    'customer_value', 'invoice_total', 'paid', 'balance',
    'opening_balance', 'period_debits', 'period_credits',
    'closing_balance', 'current_balance', 'debit', 'credit',
    'running_balance',
];
$quantityFields = [
    'quantity', 'quantity_sold', 'inventory_quantity', 'reserved_quantity',
];
$reportValue = static function ($value, string $key) use ($data, $moneyFields, $quantityFields) {
    if (! is_numeric($value)) {
        return $value === null || $value === '' ? '—' : $value;
    }

    if (in_array($key, $moneyFields, true)) {
        return \App\Support\DisplayNumber::money((float) $value, (string) ($data['currency'] ?? ''));
    }

    if (in_array($key, $quantityFields, true)) {
        return \App\Support\DisplayNumber::quantity((float) $value);
    }

    return is_float($value) ? number_format($value, 3) : number_format((int) $value);
};
@endphp
<div class="layout foodex-admin-layout" data-foodex-utility="reports">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'reports_center', 'user' => auth()->user()])</aside>
    <main class="main foodex-admin-main">
@include('admin._global-header')
        <div class="hero foodex-page-toolbar">
            <div><h1>{{ __('reports.title') }}</h1><p class="muted">{{ __('reports.description') }}</p></div>
            <div class="foodex-header-actions">
                
                @if(auth()->user()->hasPermission('reports.export') || request('store_id') && auth()->user()->hasPermission('reports.export', (int) request('store_id')))
                <div class="export">
                    @foreach(['xlsx','docx','pdf'] as $format)
                        <a class="button secondary" href="{{ route('admin.reports.export', array_merge(request()->query(), ['report'=>$report,'format'=>$format])) }}">{{ strtoupper($format) }}</a>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <div class="families">
            @foreach($catalog as $key => $label)
                <a class="pill {{ $report === $key ? 'active' : '' }}" href="{{ route('admin.reports.index', array_merge(request()->except('report'), ['report'=>$key])) }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <section class="panel">
            <form method="get" action="{{ route('admin.reports.index') }}" data-report-filters>
                <input type="hidden" name="report" value="{{ $report }}">
                <div class="filters">
                    <div><label>{{ __('reports.filters.from') }}</label><input type="date" name="from" value="{{ $data['filters']['from'] }}"></div>
                    <div><label>{{ __('reports.filters.to') }}</label><input type="date" name="to" value="{{ $data['filters']['to'] }}"></div>
                    <div><label for="report-store">{{ __('reports.filters.store') }}</label><select id="report-store" name="store_id" data-report-store><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)request('store_id') === (string)$store->id)>{{ $store->name }}</option>@endforeach</select></div>
                    <div><label for="report-channel">{{ __('reports.filters.channel') }}</label><select id="report-channel" name="channel" data-report-channel><option value="">{{ __('reports.filters.all') }}</option><option value="b2c" @selected(request('channel')==='b2c')>{{ __('reports.business_labels.b2c') }}</option><option value="b2b" @selected(request('channel')==='b2b')>{{ __('reports.business_labels.b2b') }}</option></select></div>
                    <div><label>{{ __('reports.filters.status') }}</label><select name="status"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['statuses'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $reportLabel($status) }}</option>@endforeach</select></div>
                    <div><label for="report-category">{{ __('reports.filters.category') }}</label><select id="report-category" name="category_id" data-report-category><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['categories'] as $category)<option value="{{ $category->id }}" data-store-id="{{ $category->store_id }}" data-channel="{{ $category->channel }}" @selected((string)request('category_id') === (string)$category->id)>{{ $category->name }}</option>@endforeach</select></div>
                    @php
                        $selectedProduct = $options['selected_product'];
                        $selectedProductLabel = $selectedProduct
                            ? trim((string) $selectedProduct->name.(filled($selectedProduct->sku) ? ' · '.$selectedProduct->sku : ''))
                            : '';
                    @endphp
                    <div data-report-product-picker
                         data-foodex-entity-picker
                         data-report-lookup-base="{{ route('admin.reports.lookups', ['entity'=>'products']) }}"
                         data-entity-search-url="{{ route('admin.reports.lookups', ['entity'=>'products']) }}"
                         data-entity-result-key="products"
                         data-entity-selection-mode="value"
                         data-entity-meta-fields="sku,barcode"
                         data-entity-selected-label="{{ $selectedProductLabel }}"
                         data-entity-selected-id="{{ request('product_id') }}"
                         data-entity-state-class="muted"
                         data-entity-loading="{{ __('reports.lookup.loading') }}"
                         data-entity-no-results="{{ __('reports.lookup.empty') }}"
                         data-entity-error="{{ __('reports.lookup.error') }}"
                         data-entity-retry="{{ __('reports.lookup.retry') }}"
                         data-entity-selected="{{ __('reports.lookup.selected') }}"
                         data-entity-cleared="{{ __('reports.lookup.cleared') }}"
                         data-entity-inactive="{{ __('reports.lookup.inactive') }}"
                         data-entity-load-more="{{ __('reports.lookup.load_more') }}">
                        <label for="report-product-search">{{ __('reports.lookup.product_label') }}</label>
                        <input id="report-product-search" type="search" autocomplete="off"
                               data-foodex-entity-search value="{{ $selectedProductLabel }}"
                               placeholder="{{ __('reports.lookup.product_placeholder') }}">
                        <input type="hidden" name="product_id" data-foodex-entity-id value="{{ request('product_id') }}">
                        <div id="report-product-results" data-foodex-entity-results hidden></div>
                        <div class="muted">{{ __('reports.lookup.product_hint') }}</div>
                        <button class="button secondary" type="button" data-foodex-entity-clear>{{ __('reports.lookup.clear') }}</button>
                    </div>
                    @php
                        $selectedCustomer = $options['selected_customer'];
                        $selectedCustomerLabel = $selectedCustomer
                            ? trim((string) $selectedCustomer->name.(filled($selectedCustomer->phone) ? ' · '.$selectedCustomer->phone : ''))
                            : '';
                    @endphp
                    <div data-report-customer-picker
                         data-foodex-entity-picker
                         data-report-lookup-base="{{ route('admin.reports.lookups', ['entity'=>'customers']) }}"
                         data-entity-search-url="{{ route('admin.reports.lookups', ['entity'=>'customers']) }}"
                         data-entity-result-key="customers"
                         data-entity-selection-mode="value"
                         data-entity-meta-fields="phone,email"
                         data-entity-selected-label="{{ $selectedCustomerLabel }}"
                         data-entity-selected-id="{{ request('customer_id') }}"
                         data-entity-state-class="muted"
                         data-entity-loading="{{ __('reports.lookup.loading') }}"
                         data-entity-no-results="{{ __('reports.lookup.empty') }}"
                         data-entity-error="{{ __('reports.lookup.error') }}"
                         data-entity-retry="{{ __('reports.lookup.retry') }}"
                         data-entity-selected="{{ __('reports.lookup.selected') }}"
                         data-entity-cleared="{{ __('reports.lookup.cleared') }}"
                         data-entity-inactive="{{ __('reports.lookup.inactive') }}"
                         data-entity-load-more="{{ __('reports.lookup.load_more') }}">
                        <label for="report-customer-search">{{ __('reports.lookup.customer_label') }}</label>
                        <input id="report-customer-search" type="search" autocomplete="off"
                               data-foodex-entity-search value="{{ $selectedCustomerLabel }}"
                               placeholder="{{ __('reports.lookup.customer_placeholder') }}"
                               @disabled(!in_array(request('channel'), ['b2b','b2c'], true))>
                        <input type="hidden" name="customer_id" data-foodex-entity-id value="{{ request('customer_id') }}">
                        <div id="report-customer-results" data-foodex-entity-results hidden></div>
                        <div class="muted">{{ __('reports.lookup.customer_channel_hint') }}</div>
                        <button class="button secondary" type="button" data-foodex-entity-clear>{{ __('reports.lookup.clear') }}</button>
                    </div>
                    <div><label>{{ __('reports.filters.payment') }}</label><select name="payment_provider"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['payment_providers'] as $provider)<option value="{{ $provider }}" @selected(request('payment_provider')===$provider)>{{ $reportLabel($provider) }}</option>@endforeach</select></div>
                    <div class="actions"><button class="button foodex-filter-action">{{ __('reports.apply') }}</button><a class="button secondary" href="{{ route('admin.reports.index', ['report'=>$report]) }}">{{ __('reports.reset') }}</a></div>
                </div>
            </form>
        </section>

        <div class="kpis">
            @foreach($data['kpis'] as $key => $value)
                <div class="kpi"><span class="muted">{{ __("reports.kpis.$key") }}</span><strong>{{ $reportValue($value, (string) $key) }}</strong></div>
            @endforeach
        </div>

        <section class="panel">
            <div class="meta">
                <strong>{{ __('reports.detail') }}</strong>
                <span class="muted">{{ __('reports.generated_at') }}: {{ $data['generated_at'] }} · {{ $data['timezone'] }} · {{ $data['currency'] }}</span>
            </div>
            @if($data['meta']['truncated'])<div class="warning">{{ __('reports.truncated', ['limit'=>$data['meta']['row_limit']]) }}</div>@endif
            @if(count($data['rows']) === 0)
                <div class="empty">{{ __('reports.empty') }}</div>
            @else
                <div class="table-wrap"><table data-pagination-required><thead><tr>@foreach($data['columns'] as $column)<th>{{ __("reports.columns.$column") }}</th>@endforeach</tr></thead><tbody>
                @foreach($data['rows'] as $row)<tr>@foreach($data['columns'] as $column)<td>{{ in_array((string)$column,['channel','status','exception'],true) ? $reportLabel($row[$column] ?? null) : $reportValue($row[$column] ?? null, (string) $column) }}</td>@endforeach</tr>@endforeach
                </tbody></table></div>
                {{ $rowsPaginator->links() }}
            @endif
        </section>

        <div class="breakdowns" data-report-breakdowns>
            @foreach(['status_breakdown','payment_breakdown','category_performance','zero_sales','delivery_breakdown'] as $extra)
                @if(!empty($data[$extra]))
                @php($breakdownRows = collect($data[$extra])->filter(fn ($row) => is_array($row))->values())
                @php($breakdownColumns = $breakdownRows->isNotEmpty() ? array_keys($breakdownRows->first()) : [])
                <section class="mini" data-report-breakdown="{{ $extra }}">
                    <h3>{{ __("reports.breakdowns.$extra") }}</h3>
                    @if($breakdownRows->isEmpty())
                        <div class="empty">{{ __('reports.empty') }}</div>
                    @else
                        <div class="table-wrap">
                            <table class="foodex-table" data-pagination-exempt="summary-breakdown">
                                <thead><tr>@foreach($breakdownColumns as $column)<th>{{ $breakdownHeader($column) }}</th>@endforeach</tr></thead>
                                <tbody>
                                @foreach($breakdownRows as $row)
                                    <tr>
                                    @foreach($breakdownColumns as $column)
                                        @php($value = $row[$column] ?? null)
                                        <td>
                                            @if(in_array($column, ['status','provider'], true))
                                                {{ $reportLabel($value) }}
                                            @elseif(is_numeric($value))
                                                {{ $reportValue($value, (string) $column) }}
                                            @else
                                                {{ $value === null || $value === '' ? '—' : $value }}
                                            @endif
                                        </td>
                                    @endforeach
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
                @endif
            @endforeach
        </div>
    </main>
</div>
<script src="{{ asset('assets/admin/entity-lookup.js') }}"></script>
<script>
(() => {
    const form = document.querySelector('[data-report-filters]');
    if (!form) return;

    const store = form.querySelector('[data-report-store]');
    const channel = form.querySelector('[data-report-channel]');
    const category = form.querySelector('[data-report-category]');
    const productPicker = form.querySelector('[data-report-product-picker]');
    const customerPicker = form.querySelector('[data-report-customer-picker]');
    const customerSearch = customerPicker?.querySelector('[data-foodex-entity-search]');

    const clearPicker = (picker) => {
        const button = picker?.querySelector('[data-foodex-entity-clear]');
        if (button instanceof HTMLButtonElement) button.click();
    };

    const updateLookupUrl = (picker, includeCategory) => {
        if (!(picker instanceof HTMLElement)) return;
        const url = new URL(picker.dataset.reportLookupBase || picker.dataset.entitySearchUrl || window.location.href, window.location.origin);
        if (store?.value) url.searchParams.set('store_id', store.value);
        if (channel?.value) url.searchParams.set('channel', channel.value);
        if (includeCategory && category?.value) url.searchParams.set('category_id', category.value);
        picker.dataset.entitySearchUrl = url.toString();
    };

    const syncCategories = () => {
        if (!(category instanceof HTMLSelectElement)) return;
        const wantedStore = store?.value || '';
        const wantedChannel = channel?.value || '';
        let selectedVisible = category.value === '';
        [...category.options].forEach((option) => {
            if (option.value === '') {
                option.hidden = false;
                return;
            }
            const storeMatches = wantedStore === '' || option.dataset.storeId === wantedStore;
            const channelMatches = wantedChannel === '' || option.dataset.channel === wantedChannel;
            option.hidden = !(storeMatches && channelMatches);
            if (option.selected && !option.hidden) selectedVisible = true;
        });
        if (!selectedVisible) category.value = '';
    };

    const sync = () => {
        syncCategories();
        updateLookupUrl(productPicker, true);
        updateLookupUrl(customerPicker, false);
        if (customerSearch instanceof HTMLInputElement) {
            customerSearch.disabled = !['b2b', 'b2c'].includes(channel?.value || '');
        }
    };

    store?.addEventListener('change', () => {
        clearPicker(productPicker);
        clearPicker(customerPicker);
        if (category instanceof HTMLSelectElement) category.value = '';
        sync();
    });
    channel?.addEventListener('change', () => {
        clearPicker(productPicker);
        clearPicker(customerPicker);
        if (category instanceof HTMLSelectElement) category.value = '';
        sync();
        if (customerSearch instanceof HTMLInputElement && !customerSearch.disabled) customerSearch.focus();
    });
    category?.addEventListener('change', () => {
        clearPicker(productPicker);
        sync();
    });

    sync();
})();
</script>
</body>
</html>
