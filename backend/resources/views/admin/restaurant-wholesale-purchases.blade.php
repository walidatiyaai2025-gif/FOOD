<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('restaurant_wholesale.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:var(--foodex-background);color:var(--foodex-ink);font-family:inherit}
        a{color:inherit;text-decoration:none}
        .rw-shell{max-width:1440px;margin:0 auto;padding:18px clamp(14px,2.4vw,34px) 34px}
        .rw-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
        .rw-title h1{margin:0;font-size:clamp(1.35rem,2.4vw,2rem)}
        .rw-title p{margin:7px 0 0;color:var(--foodex-muted);max-width:780px}
        .rw-boundary{margin-top:10px;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);font-size:.86rem}
        .rw-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .rw-control,.rw-btn{min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);padding:0 12px;color:inherit}
        .rw-btn{display:inline-flex;align-items:center;justify-content:center;font-weight:800;cursor:pointer}
        .rw-btn-primary{border-color:var(--foodex-action-green);background:var(--foodex-action-green);color:#fff}
        .rw-btn-danger{color:#a82828}
        .rw-status{margin:0 0 12px;padding:10px 12px;border-radius:10px;background:var(--foodex-green-soft);font-weight:700}
        .rw-tabs{display:flex;gap:6px;overflow:auto;padding:5px;margin:0 0 14px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface)}
        .rw-tab{min-height:40px;display:inline-flex;align-items:center;padding:0 14px;border-radius:9px;white-space:nowrap;font-weight:800;color:var(--foodex-muted)}
        .rw-tab.active{background:var(--foodex-action-green);color:#fff}
        .rw-card{border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm);overflow:hidden}
        .rw-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--foodex-border)}
        .rw-card-head h2{margin:0;font-size:1.05rem}
        .rw-card-head small{color:var(--foodex-muted)}
        .rw-table-wrap{overflow:auto}
        table{width:100%;border-collapse:collapse;min-width:760px}
        th,td{text-align:start;padding:11px 13px;border-bottom:1px solid var(--foodex-border);vertical-align:middle}
        th{font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;color:var(--foodex-muted);background:var(--foodex-background)}
        tbody tr:last-child td{border-bottom:0}
        .rw-number{font-variant-numeric:tabular-nums;white-space:nowrap}
        .rw-muted{color:var(--foodex-muted)}
        .rw-empty{padding:34px 18px;text-align:center;color:var(--foodex-muted)}
        .rw-inline{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
        .rw-inline input[type=number]{width:104px}
        .rw-pill{display:inline-flex;align-items:center;min-height:28px;padding:0 9px;border-radius:999px;background:var(--foodex-background);border:1px solid var(--foodex-border);font-size:.78rem;font-weight:800}
        .rw-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:14px}
        .rw-summary>div{padding:12px;border-radius:11px;background:var(--foodex-background)}
        .rw-summary strong{display:block;margin-top:5px;font-size:1.05rem}
        .rw-checkout{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px;border-top:1px solid var(--foodex-border)}
        .rw-field{display:grid;gap:6px}
        .rw-field label{font-size:.8rem;font-weight:800;color:var(--foodex-muted)}
        .rw-field select,.rw-field input,.rw-field textarea{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:inherit;padding:9px 11px}
        .rw-field textarea{min-height:78px;resize:vertical}
        .rw-checkout-actions{grid-column:1/-1;display:flex;justify-content:flex-end}
        @media(max-width:800px){
            .rw-header{display:grid}
            .rw-actions{justify-content:flex-start}
            .rw-summary{grid-template-columns:1fr 1fr}
            .rw-checkout{grid-template-columns:1fr}
            .rw-checkout-actions{grid-column:1}
        }
        @media(max-width:480px){.rw-summary{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="foodex-admin-layout">
@php
    $restaurantStoreId = (int) $context['restaurant_store_id'];
    $baseQuery = ['retail_store_id' => $restaurantStoreId];
    $restaurantBack = route('admin.smart-ordering.index', ['store_id' => $restaurantStoreId]);
@endphp
<main class="foodex-admin-main rw-shell" data-restaurant-wholesale-purchases>
    <header class="foodex-page-toolbar rw-header">
        <div class="rw-title">
            <h1>{{ __('restaurant_wholesale.title') }}</h1>
            <p>{{ __('restaurant_wholesale.subtitle', ['restaurant' => $context['restaurant']->name]) }}</p>
            <div class="rw-boundary" data-customer-orders-boundary>
                <strong>{{ __('restaurant_wholesale.boundary.title') }}</strong>
                {{ __('restaurant_wholesale.boundary.text') }}
            </div>
        </div>
        <div class="rw-actions">
            @if($context['restaurant_stores']->count() > 1)
                <form method="get" action="{{ route('admin.restaurant.wholesale-purchases.index') }}">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <select class="rw-control" name="retail_store_id" onchange="this.form.submit()" aria-label="{{ __('restaurant_wholesale.restaurant') }}">
                        @foreach($context['restaurant_stores'] as $restaurantOption)
                            <option value="{{ $restaurantOption->id }}" @selected((int)$restaurantOption->id === $restaurantStoreId)>{{ $restaurantOption->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            <a class="rw-btn" href="{{ $restaurantBack }}">{{ __('restaurant_wholesale.back_to_restaurant') }}</a>
        </div>
    </header>

    @if(session('status'))
        <p class="rw-status" role="status">{{ session('status') }}</p>
    @endif

    <nav class="rw-tabs" aria-label="{{ __('restaurant_wholesale.title') }}">
        @foreach($tabs as $tabCode)
            <a class="rw-tab {{ $tab === $tabCode ? 'active' : '' }}"
               href="{{ route('admin.restaurant.wholesale-purchases.index', [...$baseQuery, 'tab' => $tabCode]) }}"
               data-wholesale-tab="{{ $tabCode }}">
                {{ __('restaurant_wholesale.tabs.'.$tabCode) }}
            </a>
        @endforeach
    </nav>

    @if($tab === 'catalog')
        <section class="rw-card" data-wholesale-catalog>
            <div class="rw-card-head">
                <div><h2>{{ __('restaurant_wholesale.catalog.title') }}</h2><small>{{ __('restaurant_wholesale.catalog.help') }}</small></div>
            </div>
            @if(empty($catalog))
                <div class="rw-empty">{{ __('restaurant_wholesale.empty.catalog') }}</div>
            @else
                <div class="rw-table-wrap">
                    <table>
                        <thead><tr>
                            <th>{{ __('restaurant_wholesale.columns.product') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.price') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.minimum') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.available') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.action') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach($catalog as $product)
                            <tr>
                                <td><strong>{{ $product['name'] ?? '—' }}</strong><div class="rw-muted">{{ $product['sku'] ?? '' }}</div></td>
                                <td class="rw-number">{{ number_format((float)($product['account_price'] ?? $product['unit_price'] ?? 0), 3) }} {{ $product['currency'] ?? '' }}</td>
                                <td class="rw-number">{{ $product['minimum_order_quantity'] ?? $product['minimum_quantity'] ?? '—' }} · +{{ $product['ordering_increment'] ?? '—' }}</td>
                                <td>{{ ($product['is_available'] ?? true) ? __('restaurant_wholesale.available') : __('restaurant_wholesale.unavailable') }}</td>
                                <td>
                                    <form class="rw-inline" method="post" action="{{ route('admin.restaurant.wholesale-purchases.cart.add') }}">
                                        @csrf
                                        <input type="hidden" name="retail_store_id" value="{{ $restaurantStoreId }}">
                                        <input type="hidden" name="product_id" value="{{ $product['id'] }}">
                                        <input class="rw-control" type="number" name="quantity"
                                               min="{{ $product['minimum_order_quantity'] ?? $product['minimum_quantity'] ?? 0.001 }}"
                                               step="{{ $product['ordering_increment'] ?? 0.001 }}"
                                               value="{{ $product['minimum_order_quantity'] ?? $product['minimum_quantity'] ?? 1 }}"
                                               required>
                                        <button class="rw-btn rw-btn-primary" type="submit">{{ __('restaurant_wholesale.catalog.add') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if($tab === 'cart')
        <section class="rw-card" data-purchase-cart>
            <div class="rw-card-head">
                <div><h2>{{ __('restaurant_wholesale.cart.title') }}</h2><small>{{ __('restaurant_wholesale.cart.help') }}</small></div>
            </div>
            @if(empty($cartItems))
                <div class="rw-empty">{{ __('restaurant_wholesale.empty.cart') }}</div>
            @else
                <div class="rw-table-wrap">
                    <table>
                        <thead><tr>
                            <th>{{ __('restaurant_wholesale.columns.product') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.quantity') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.price') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.total') }}</th>
                            <th>{{ __('restaurant_wholesale.columns.action') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach($cartItems as $line)
                            <tr>
                                <td><strong>{{ $line['name'] ?? '—' }}</strong><div class="rw-muted">{{ $line['sku'] ?? '' }}</div></td>
                                <td>
                                    <form class="rw-inline" method="post" action="{{ route('admin.restaurant.wholesale-purchases.cart.update', ['item' => $line['cart_item_id']]) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="retail_store_id" value="{{ $restaurantStoreId }}">
                                        <input class="rw-control" type="number" name="quantity"
                                               min="{{ $line['minimum_order_quantity'] ?? 0.001 }}"
                                               step="{{ $line['ordering_increment'] ?? 0.001 }}"
                                               value="{{ $line['quantity'] }}" required>
                                        <button class="rw-btn" type="submit">{{ __('restaurant_wholesale.cart.update') }}</button>
                                    </form>
                                </td>
                                <td class="rw-number">{{ number_format((float)($line['unit_price'] ?? 0), 3) }} {{ $cart['currency'] ?? '' }}</td>
                                <td class="rw-number">{{ number_format((float)($line['line_total'] ?? 0), 3) }} {{ $cart['currency'] ?? '' }}</td>
                                <td>
                                    <form method="post" action="{{ route('admin.restaurant.wholesale-purchases.cart.remove', ['item' => $line['cart_item_id']]) }}">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="retail_store_id" value="{{ $restaurantStoreId }}">
                                        <button class="rw-btn rw-btn-danger" type="submit">{{ __('restaurant_wholesale.cart.remove') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="rw-summary">
                    <div><span class="rw-muted">{{ __('restaurant_wholesale.summary.subtotal') }}</span><strong class="rw-number">{{ number_format((float)($cart['subtotal'] ?? 0),3) }} {{ $cart['currency'] ?? '' }}</strong></div>
                    <div><span class="rw-muted">{{ __('restaurant_wholesale.summary.discount') }}</span><strong class="rw-number">{{ number_format((float)($cart['discount_total'] ?? 0),3) }} {{ $cart['currency'] ?? '' }}</strong></div>
                    <div><span class="rw-muted">{{ __('restaurant_wholesale.summary.tax') }}</span><strong class="rw-number">{{ number_format((float)($cart['tax_total'] ?? 0),3) }} {{ $cart['currency'] ?? '' }}</strong></div>
                    <div><span class="rw-muted">{{ __('restaurant_wholesale.summary.grand_total') }}</span><strong class="rw-number">{{ number_format((float)($cart['grand_total'] ?? 0),3) }} {{ $cart['currency'] ?? '' }}</strong></div>
                </div>

                <form class="rw-checkout" method="post" action="{{ route('admin.restaurant.wholesale-purchases.checkout') }}">
                    @csrf
                    <input type="hidden" name="retail_store_id" value="{{ $restaurantStoreId }}">
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <div class="rw-field">
                        <label for="rw-address">{{ __('restaurant_wholesale.checkout.address') }}</label>
                        <select id="rw-address" name="address_id" required>
                            <option value="">{{ __('restaurant_wholesale.checkout.choose_address') }}</option>
                            @foreach($addresses as $address)
                                <option value="{{ $address->id }}">{{ $address->label ?: $address->line1 }} · {{ $address->city }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rw-field">
                        <label for="rw-payment">{{ __('restaurant_wholesale.checkout.payment') }}</label>
                        <select id="rw-payment" name="payment_method" required>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method }}">{{ __('restaurant_wholesale.payment_methods.'.$method) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rw-field">
                        <label for="rw-date">{{ __('restaurant_wholesale.checkout.delivery_date') }}</label>
                        <input id="rw-date" type="date" name="requested_delivery_date" min="{{ now()->toDateString() }}">
                    </div>
                    <div class="rw-field">
                        <label for="rw-note">{{ __('restaurant_wholesale.checkout.note') }}</label>
                        <textarea id="rw-note" name="note" maxlength="1000"></textarea>
                    </div>
                    <div class="rw-checkout-actions">
                        <button class="rw-btn rw-btn-primary" type="submit" @disabled($addresses->isEmpty())>{{ __('restaurant_wholesale.checkout.submit') }}</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    @if($tab === 'orders')
        <section class="rw-card" data-purchase-orders>
            <div class="rw-card-head"><div><h2>{{ __('restaurant_wholesale.orders.title') }}</h2><small>{{ __('restaurant_wholesale.orders.help') }}</small></div></div>
            @if($orders->isEmpty())
                <div class="rw-empty">{{ __('restaurant_wholesale.empty.orders') }}</div>
            @else
                <div class="rw-table-wrap"><table>
                    <thead><tr>
                        <th>{{ __('restaurant_wholesale.columns.order') }}</th><th>{{ __('restaurant_wholesale.columns.status') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.items') }}</th><th>{{ __('restaurant_wholesale.columns.total') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.delivery_date') }}</th><th>{{ __('restaurant_wholesale.columns.created') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($orders as $order)
                        <tr>
                            <td class="rw-number">#{{ $order->id }}</td>
                            <td><span class="rw-pill">{{ __('restaurant_wholesale.status.'.$order->status) }}</span></td>
                            <td class="rw-number">{{ $order->items_count }}</td>
                            <td class="rw-number">{{ number_format((float)$order->grand_total,3) }} {{ $order->currency }}</td>
                            <td>{{ $order->requested_delivery_date ?: '—' }}</td>
                            <td>{{ optional($order->created_at)->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif

    @if($tab === 'invoices')
        <section class="rw-card" data-supplier-invoices>
            <div class="rw-card-head"><div><h2>{{ __('restaurant_wholesale.invoices.title') }}</h2><small>{{ __('restaurant_wholesale.invoices.help') }}</small></div></div>
            @if($invoices->isEmpty())
                <div class="rw-empty">{{ __('restaurant_wholesale.empty.invoices') }}</div>
            @else
                <div class="rw-table-wrap"><table>
                    <thead><tr>
                        <th>{{ __('restaurant_wholesale.columns.invoice') }}</th><th>{{ __('restaurant_wholesale.columns.order') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.status') }}</th><th>{{ __('restaurant_wholesale.columns.total') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.outstanding') }}</th><th>{{ __('restaurant_wholesale.columns.issued') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice['invoice_number'] !== '' ? $invoice['invoice_number'] : '#'.$invoice['id'] }}</td>
                            <td class="rw-number">{{ $invoice['order_id'] ? '#'.$invoice['order_id'] : '—' }}</td>
                            <td><span class="rw-pill">{{ $invoice['status'] }}</span></td>
                            <td class="rw-number">{{ number_format($invoice['total'],3) }} {{ $invoice['currency'] }}</td>
                            <td class="rw-number">{{ number_format($invoice['outstanding_amount'],3) }} {{ $invoice['currency'] }}</td>
                            <td>{{ $invoice['issued_at'] ? \Illuminate\Support\Carbon::parse($invoice['issued_at'])->format('Y-m-d') : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif

    @if($tab === 'delivery')
        <section class="rw-card" data-supply-delivery-status>
            <div class="rw-card-head"><div><h2>{{ __('restaurant_wholesale.delivery.title') }}</h2><small>{{ __('restaurant_wholesale.delivery.help') }}</small></div></div>
            @if($supplyStatuses->isEmpty())
                <div class="rw-empty">{{ __('restaurant_wholesale.empty.delivery') }}</div>
            @else
                <div class="rw-table-wrap"><table>
                    <thead><tr>
                        <th>{{ __('restaurant_wholesale.columns.order') }}</th><th>{{ __('restaurant_wholesale.columns.status') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.delivery_date') }}</th><th>{{ __('restaurant_wholesale.columns.receipt') }}</th>
                        <th>{{ __('restaurant_wholesale.columns.total') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($supplyStatuses as $supply)
                        <tr>
                            <td class="rw-number">#{{ $supply['order_id'] }}</td>
                            <td><span class="rw-pill">{{ __('restaurant_wholesale.status.'.$supply['order_status']) }}</span></td>
                            <td>{{ $supply['requested_delivery_date'] ?: '—' }}</td>
                            <td>{{ $supply['received'] ? __('restaurant_wholesale.received') : __('restaurant_wholesale.not_received') }}</td>
                            <td class="rw-number">{{ number_format($supply['grand_total'],3) }} {{ $supply['currency'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @endif
</main>
</div>
</body>
</html>
