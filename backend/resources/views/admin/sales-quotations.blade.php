@php $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr'; @endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('sales_quotations.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
        .sq-layout{display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;direction:ltr}
        .sq-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface)}
        .sq-layout>main{grid-column:1;grid-row:1;min-width:0;padding:18px;direction:rtl}
        html[dir=ltr] .sq-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .sq-layout>.sidebar{grid-column:1;direction:ltr}
        html[dir=ltr] .sq-layout>main{grid-column:2;direction:ltr}
        .sq-card{background:var(--foodex-surface);padding:16px;border:1px solid var(--foodex-border);border-radius:16px;margin-bottom:14px;min-width:0}
        .sq-row{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
        .sq-row>*{min-width:0}
        .sq-card h1,.sq-card h2{margin:0 0 10px}
        .sq-card label{display:grid;gap:5px;font-weight:700}
        .sq-card input,.sq-card select{border:1px solid var(--foodex-border);border-radius:9px;padding:10px;background:var(--foodex-surface);color:var(--foodex-ink);max-width:100%;box-sizing:border-box}
        .sq-card input:focus-visible,.sq-card select:focus-visible,.sq-card button:focus-visible,.sq-card a:focus-visible{outline:3px solid var(--foodex-action-green);outline-offset:2px}
        .sq-primary{background:var(--foodex-action-green);color:#fff;border:0;border-radius:9px;padding:11px 18px;font-weight:800;cursor:pointer;min-height:44px}
        .sq-secondary{display:inline-block;background:var(--foodex-surface);border:1px solid var(--foodex-border);color:var(--foodex-ink);border-radius:9px;padding:10px 14px;text-decoration:none;cursor:pointer;min-height:44px}
        .sq-muted{color:var(--foodex-muted)}.sq-error{color:var(--foodex-danger-text,firebrick)}
        .sq-table{width:100%;border-collapse:collapse}
        .sq-table th,.sq-table td{padding:10px;text-align:start;border-bottom:1px solid var(--foodex-border);vertical-align:middle}
        .sq-table th{font-size:.86rem}.sq-table td{overflow-wrap:anywhere}
        .sq-table .sq-reference{white-space:nowrap;font-variant-numeric:tabular-nums}
        .sq-actions{position:relative}.sq-actions summary{cursor:pointer;min-width:44px;min-height:44px;display:grid;place-items:center;color:var(--foodex-action-green);font-weight:900}
        .sq-actions a{display:block;min-height:40px;padding:8px;color:var(--foodex-action-green)}
        .sq-lookup{min-width:min(100%,220px)}.sq-quantity{max-width:130px}
        .sq-lines{display:grid;gap:8px;margin:12px 0}
        .sq-line{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:10px;background:var(--foodex-background);border-radius:10px}
        .sq-line label:first-child{flex:1 1 220px}
        .sq-actions-group{display:flex;gap:8px;flex-wrap:wrap}
        @media(max-width:900px){.sq-layout>main{padding:12px}}
        @media(max-width:620px){.sq-table,.sq-table tbody,.sq-table tr,.sq-table td{display:block;width:100%;box-sizing:border-box}.sq-table thead{display:none}.sq-table tr{border:1px solid var(--foodex-border);border-radius:12px;margin:9px 0;padding:6px}.sq-table td{border:0;display:flex;gap:8px;justify-content:space-between;align-items:center}.sq-table td::before{content:attr(data-label);font-weight:700}.sq-card{padding:12px}.sq-row>*{flex:1 1 100%}}
    </style>
</head>
<body>
<div class="foodex-admin-layout sq-layout" data-sales-quotations>
    <aside class="sidebar">@include('admin._sidebar', ['user'=>$user,'navGroups'=>$navGroups,'navContext'=>$navContext])</aside>
    <main class="foodex-admin-main foodex-admin-page">
        @include('admin._global-header')
        <div class="foodex-page-toolbar sq-card">
            <h1>{{ __('sales_quotations.title') }}</h1>
            <p class="sq-muted">{{ __('sales_quotations.description') }}</p>
            <a href="{{ route('admin.operations.orders.index') }}" class="sq-secondary">{{ __('sales_quotations.back_to_orders') }}</a>
            <div class="foodex-header-actions">
        @if($canCreate)
        <details data-foodex-operational-modal data-modal-button-class="sq-primary" class="sq-create-workflow">
            <summary>{{ __('sales_quotations.create') }}</summary>
            <div class="sq-card">
            <p class="sq-muted">{{ __('sales_quotations.pricing_help') }}</p>
            <form method="post" action="{{ route('admin.sales-quotations.store') }}">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                <div class="sq-row">
                    <label>{{ __('sales_quotations.customer') }}
                        <select class="sq-lookup" name="customer_id" required>
                            <option value="">{{ __('sales_quotations.choose_customer') }}</option>
                            @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected((int)old('customer_id') === (int)$customer->id)>{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('sales_quotations.expires_at') }}
                        <input type="date" name="expires_at" required min="{{ today()->addDay()->toDateString() }}" max="{{ today()->addDays(90)->toDateString() }}" value="{{ old('expires_at', today()->addDays(7)->toDateString()) }}">
                    </label>
                </div>
                <div class="sq-lines" id="sq-lines">
                    @foreach(old('items', [['product_id' => null, 'quantity' => 1]]) as $itemIndex => $item)
                    <div class="sq-line">
                        <label>{{ __('sales_quotations.product') }}
                            <select name="items[{{ $itemIndex }}][product_id]" class="sq-lookup" required>
                                <option value="">{{ __('sales_quotations.choose_product') }}</option>
                                @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected((int)($item['product_id'] ?? 0) === (int)$product->id)>{{ $product->name }} · {{ $product->sku }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('sales_quotations.quantity') }}
                            <input class="sq-quantity" name="items[{{ $itemIndex }}][quantity]" type="number" min=".001" step=".001" value="{{ $item['quantity'] ?? 1 }}" required>
                        </label>
                    </div>
                    @endforeach
                </div>
                <div class="sq-actions-group">
                    <button type="button" id="sq-add-line" class="sq-secondary">{{ __('sales_quotations.add_line') }}</button>
                    <button type="submit" class="sq-primary">{{ __('sales_quotations.create') }}</button>
                </div>
            </form>
            </div>
        </details>
        @endif
            </div>
        </div>
        @if(session('status'))<div class="sq-card" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="sq-card sq-error" role="alert">{{ $errors->first() }}</div>@endif
        <section class="sq-card" aria-label="{{ __('sales_quotations.store') }}">
            <form action="{{ route('admin.sales-quotations.index') }}" method="get" class="sq-row">
                <label>{{ __('sales_quotations.store') }}
                    <select name="store_id" required>
                        @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)$store->id === $storeId)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="sq-primary">{{ __('sales_quotations.switch_store') }}</button>
            </form>
        </section>
        @if($selected)
        <section class="sq-card" id="quotation-details" aria-labelledby="sq-detail-title">
            <h2 id="sq-detail-title">{{ __('sales_quotations.details') }} · SQ-{{ strtoupper(substr($selected->reference, 0, 8)) }}</h2>
            <p><strong>{{ __('sales_quotations.customer') }}:</strong> {{ $selectedCustomerName }}</p>
            <p class="sq-muted">{{ __('sales_quotations.status') }}: {{ __('sales_quotations.statuses.'.$selected->status) }}
                · {{ __('sales_quotations.expires_at') }}: {{ $selected->expires_at }}</p>
            <table class="sq-table">
                <thead><tr><th>{{ __('sales_quotations.product') }}</th><th>{{ __('sales_quotations.quantity') }}</th><th>{{ __('sales_quotations.unit_price') }}</th><th>{{ __('sales_quotations.line_total') }}</th></tr></thead>
                <tbody>
                @foreach($selectedLines as $line)
                    <tr>
                        <td data-label="{{ __('sales_quotations.product') }}">{{ $line['name'] ?? '' }}</td>
                        <td data-label="{{ __('sales_quotations.quantity') }}">{{ number_format((float)($line['quantity'] ?? 0), 3) }}</td>
                        <td data-label="{{ __('sales_quotations.unit_price') }}">{{ number_format((float)($line['unit_price'] ?? 0), 3) }}</td>
                        <td data-label="{{ __('sales_quotations.line_total') }}">{{ number_format((float)($line['line_total'] ?? 0), 3) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p><strong>{{ __('sales_quotations.total') }}: {{ number_format((float)$selected->grand_total, 3) }} {{ $selected->currency }}</strong></p>
            @if($canManage && $selected->status === 'draft')
            <div class="sq-actions-group">
                <form method="post" action="{{ route('admin.sales-quotations.transition', ['quotation'=>$selected->id,'action'=>'issue']) }}">
                    @csrf
                    <button type="submit" class="sq-primary">{{ __('sales_quotations.issue') }}</button>
                </form>
                <form method="post" action="{{ route('admin.sales-quotations.transition', ['quotation'=>$selected->id,'action'=>'cancel']) }}" onsubmit="return window.confirm(@js(__('sales_quotations.cancel_confirm')));">
                    @csrf
                    <button type="submit" class="sq-secondary">{{ __('sales_quotations.cancel') }}</button>
                </form>
            </div>
            @endif
            <p><a href="{{ route('admin.sales-quotations.index', ['store_id'=>$storeId]) }}">{{ __('sales_quotations.back_to_list') }}</a></p>
        </section>
        @endif
        <section class="sq-card" aria-labelledby="sq-history-title">
            <h2 id="sq-history-title">{{ __('sales_quotations.history') }}</h2>
            <table class="sq-table">
                <thead><tr><th>{{ __('sales_quotations.reference') }}</th><th>{{ __('sales_quotations.status') }}</th><th>{{ __('sales_quotations.expires_at') }}</th><th>{{ __('sales_quotations.total') }}</th><th>{{ __('sales_quotations.actions') }}</th></tr></thead>
                <tbody>
                @forelse($quotations as $quotation)
                    <tr>
                        <td data-label="{{ __('sales_quotations.reference') }}" class="sq-reference">SQ-{{ strtoupper(substr($quotation->reference, 0, 8)) }}</td>
                        <td data-label="{{ __('sales_quotations.status') }}">{{ __('sales_quotations.statuses.'.$quotation->status) }}</td>
                        <td data-label="{{ __('sales_quotations.expires_at') }}">{{ $quotation->expires_at }}</td>
                        <td data-label="{{ __('sales_quotations.total') }}">{{ number_format((float)$quotation->grand_total, 3) }} {{ $quotation->currency }}</td>
                        <td data-label="{{ __('sales_quotations.actions') }}">
                            <details class="sq-actions"><summary aria-label="{{ __('sales_quotations.actions') }}">⋯</summary>
                                <a href="{{ route('admin.sales-quotations.show', ['quotation'=>$quotation->id]) }}">{{ __('sales_quotations.view') }}</a>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('sales_quotations.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
            {{ $quotations->withQueryString()->links() }}
        </section>
    </main>
</div>
<script>
    (() => {
        const add = document.getElementById('sq-add-line');
        const lines = document.getElementById('sq-lines');
        if (!add || !lines) return;
        @if($errors->any())
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelector('.sq-create-workflow [data-foodex-dialog-open]')?.click();
        }, { once: true });
        @endif
        add.addEventListener('click', () => {
            const next = lines.children.length;
            if (next >= 20) { add.disabled = true; return; }
            const row = lines.firstElementChild.cloneNode(true);
            row.querySelectorAll('select,input').forEach(input => {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + next + ']');
                input.value = input.tagName === 'SELECT' ? '' : '1';
            });
            lines.appendChild(row);
            row.querySelector('select')?.focus();
            if (next === 19) add.disabled = true;
        });
    })();
</script>
</body>
</html>
