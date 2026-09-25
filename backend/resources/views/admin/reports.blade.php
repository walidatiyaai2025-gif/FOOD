<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('reports.title') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f5f7fb;color:#172033}.layout{display:grid;grid-template-columns:270px minmax(0,1fr);min-height:100vh}.sidebar{background:#111827;color:#fff;padding:20px}.main{padding:28px;overflow:hidden}.hero{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:20px}.hero h1{margin:0;font-size:30px}.muted{color:#667085}.families{display:flex;gap:9px;flex-wrap:wrap;margin:16px 0}.pill{display:inline-flex;padding:9px 13px;border-radius:999px;background:#fff;border:1px solid #dce2ea;text-decoration:none;color:#344054;font-weight:650}.pill.active{background:#101828;color:#fff;border-color:#101828}.panel{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(16,24,40,.04);margin-bottom:18px}.filters{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}.filters label{font-size:12px;font-weight:700;display:block;margin-bottom:5px}.filters input,.filters select{width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:9px;background:#fff}.actions{display:flex;gap:8px;align-items:flex-end}.button{border:0;border-radius:10px;background:#101828;color:#fff;padding:10px 14px;text-decoration:none;display:inline-block;cursor:pointer}.button.secondary{background:#eef2f6;color:#344054}.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}.kpi{background:linear-gradient(145deg,#fff,#f8fafc);border:1px solid #e4e7ec;border-radius:16px;padding:16px}.kpi strong{display:block;font-size:24px;margin-top:5px}.export{display:flex;gap:8px;flex-wrap:wrap}.table-wrap{overflow:auto;border-radius:12px;border:1px solid #e4e7ec}table{width:100%;border-collapse:collapse;min-width:780px}th,td{padding:11px 12px;border-bottom:1px solid #eef2f6;text-align:start;white-space:nowrap}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.03em}.empty{text-align:center;padding:42px;color:#667085}.meta{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.warning{background:#fff7ed;color:#9a3412;border-radius:10px;padding:10px}.breakdowns{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.mini{border:1px solid #e4e7ec;border-radius:14px;padding:14px}.mini h3{margin-top:0}@media(max-width:1050px){.filters{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.layout{grid-template-columns:1fr}.main{padding:18px}.filters{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}}
    </style>
    @include('admin._brand-components')
</head>
<body>
<div class="layout">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'reports_center', 'user' => auth()->user()])</aside>
    <main class="main">
        <div class="hero">
            <div><h1>{{ __('reports.title') }}</h1><p class="muted">{{ __('reports.description') }}</p></div>
            @if(auth()->user()->hasPermission('reports.export') || request('store_id') && auth()->user()->hasPermission('reports.export', (int) request('store_id')))
            <div class="export">
                @foreach(['xlsx','docx','pdf'] as $format)
                    <a class="button secondary" href="{{ route('admin.reports.export', array_merge(request()->query(), ['report'=>$report,'format'=>$format])) }}">{{ strtoupper($format) }}</a>
                @endforeach
            </div>
            @endif
        </div>

        <div class="families">
            @foreach($catalog as $key => $label)
                <a class="pill {{ $report === $key ? 'active' : '' }}" href="{{ route('admin.reports.index', array_merge(request()->except('report'), ['report'=>$key])) }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <section class="panel">
            <form method="get" action="{{ route('admin.reports.index') }}">
                <input type="hidden" name="report" value="{{ $report }}">
                <div class="filters">
                    <div><label>{{ __('reports.filters.from') }}</label><input type="date" name="from" value="{{ $data['filters']['from'] }}"></div>
                    <div><label>{{ __('reports.filters.to') }}</label><input type="date" name="to" value="{{ $data['filters']['to'] }}"></div>
                    <div><label>{{ __('reports.filters.store') }}</label><select name="store_id"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)request('store_id') === (string)$store->id)>{{ $store->name }}</option>@endforeach</select></div>
                    <div><label>{{ __('reports.filters.channel') }}</label><select name="channel"><option value="">{{ __('reports.filters.all') }}</option><option value="b2c" @selected(request('channel')==='b2c')>B2C</option><option value="b2b" @selected(request('channel')==='b2b')>B2B</option></select></div>
                    <div><label>{{ __('reports.filters.status') }}</label><select name="status"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['statuses'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></div>
                    <div><label>{{ __('reports.filters.category') }}</label><select name="category_id"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['categories'] as $category)<option value="{{ $category->id }}" @selected((string)request('category_id') === (string)$category->id)>{{ $category->name }}</option>@endforeach</select></div>
                    <div><label>{{ __('reports.filters.product') }}</label><select name="product_id"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['products'] as $product)<option value="{{ $product->id }}" @selected((string)request('product_id') === (string)$product->id)>{{ $product->sku }} · {{ $product->name }}</option>@endforeach</select></div>
                    <div><label>{{ __('reports.filters.customer') }}</label><select name="customer_id"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['customers'] as $customer)<option value="{{ $customer->id }}" @selected((string)request('customer_id') === (string)$customer->id)>{{ $customer->name }} · {{ strtoupper($customer->type) }}</option>@endforeach</select></div>
                    <div><label>{{ __('reports.filters.payment') }}</label><select name="payment_provider"><option value="">{{ __('reports.filters.all') }}</option>@foreach($options['payment_providers'] as $provider)<option value="{{ $provider }}" @selected(request('payment_provider')===$provider)>{{ $provider }}</option>@endforeach</select></div>
                    <div class="actions"><button class="button">{{ __('reports.apply') }}</button><a class="button secondary" href="{{ route('admin.reports.index', ['report'=>$report]) }}">{{ __('reports.reset') }}</a></div>
                </div>
            </form>
        </section>

        <div class="kpis">
            @foreach($data['kpis'] as $key => $value)
                <div class="kpi"><span class="muted">{{ __("reports.kpis.$key") }}</span><strong>{{ is_float($value) ? number_format($value, 3) : number_format($value) }}</strong></div>
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
                <div class="table-wrap"><table><thead><tr>@foreach($data['columns'] as $column)<th>{{ __("reports.columns.$column") }}</th>@endforeach</tr></thead><tbody>
                @foreach($data['rows'] as $row)<tr>@foreach($data['columns'] as $column)<td>{{ $row[$column] ?? '—' }}</td>@endforeach</tr>@endforeach
                </tbody></table></div>
            @endif
        </section>

        <div class="breakdowns">
            @foreach(['status_breakdown','payment_breakdown','category_performance','zero_sales','delivery_breakdown'] as $extra)
                @if(!empty($data[$extra]))
                <section class="mini"><h3>{{ __("reports.breakdowns.$extra") }}</h3><pre style="white-space:pre-wrap;margin:0">{{ json_encode($data[$extra], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></section>
                @endif
            @endforeach
        </div>
    </main>
</div>
</body>
</html>
