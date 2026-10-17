<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('chatbot.preview_screens.'.$screen) }} — {{ __('chatbot.page_title') }}</title>
    @include('admin._brand-components')
    <style id="foodex-chatbot-preview-ui">
        .cbp-shell{display:grid;gap:var(--foodex-space-4)}
        .cbp-toolbar{display:flex;align-items:end;justify-content:space-between;gap:var(--foodex-space-3);flex-wrap:wrap}
        .cbp-screen-tabs{display:flex;gap:var(--foodex-space-2);overflow:auto;padding-bottom:2px}
        .cbp-screen-tabs a{white-space:nowrap;text-decoration:none;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink)}
        .cbp-screen-tabs a[aria-current="page"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:var(--foodex-green);font-weight:700}
        .cbp-stage{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,430px) minmax(0,1fr);gap:var(--foodex-space-4);align-items:start}
        .cbp-phone{grid-column:2;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:32px;padding:10px;box-shadow:var(--foodex-shadow-raised);min-height:680px}
        .cbp-phone-inner{border:1px solid var(--foodex-border);border-radius:24px;overflow:hidden;min-height:658px;background:var(--foodex-background);display:flex;flex-direction:column}
        .cbp-phone-head{padding:14px;background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border);display:flex;align-items:center;justify-content:space-between;gap:10px}
        .cbp-brand{display:flex;align-items:center;gap:10px}.cbp-brand-mark{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);font-weight:800;color:var(--foodex-green-dark)}
        .cbp-content{padding:14px;display:grid;gap:12px;flex:1;align-content:start}
        .cbp-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:12px}
        .cbp-hero{background:var(--foodex-green-soft);border-color:var(--foodex-green)}
        .cbp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .cbp-action{display:grid;gap:5px;text-decoration:none;color:var(--foodex-ink);background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:12px;min-height:78px}
        .cbp-action strong{color:var(--foodex-green-dark)}
        .cbp-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid var(--foodex-border)}.cbp-row:last-child{border-bottom:0}
        .cbp-muted{color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
        .cbp-price{font-weight:800;color:var(--foodex-green-dark)}
        .cbp-pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);font-weight:700}
        .cbp-search{width:100%}.cbp-bottom{padding:12px;background:var(--foodex-surface);border-top:1px solid var(--foodex-border);display:flex;gap:8px;align-items:center}.cbp-bottom .foodex-control{flex:1}
        .cbp-warning{padding:10px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-surface);color:var(--foodex-muted)}
        .cbp-summary{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.cbp-stat{padding:10px;border-radius:var(--foodex-radius-md);background:var(--foodex-surface);border:1px solid var(--foodex-border)}
        @media(max-width:1100px){.cbp-stage{grid-template-columns:1fr}.cbp-phone{grid-column:1;max-width:430px;width:100%;margin-inline:auto}}
        @media(max-width:560px){.cbp-phone{border-radius:0;padding:0;box-shadow:none;min-height:calc(100vh - 190px)}.cbp-phone-inner{border-radius:0;min-height:inherit}.cbp-grid{grid-template-columns:1fr}.cbp-toolbar{align-items:stretch}.cbp-toolbar form{width:100%}}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="whatsapp-chatbot-preview-{{ $screen }}">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
        <div class="cbp-shell">
            <header class="foodex-page-toolbar">
                <div>
                    <span class="foodex-subtitle">{{ __('chatbot.preview_title') }}</span>
                    <h1>{{ __('chatbot.preview_screens.'.$screen) }}</h1>
                    <p>{{ __('chatbot.admin.customer_preview_hint') }}</p>
                </div>
                <div class="foodex-header-actions"><a class="foodex-action-primary" href="{{ route('admin.whatsapp-chatbot.index') }}">{{ __('chatbot.page_title') }}</a></div>
            </header>

            <div class="cbp-toolbar">
                <nav class="cbp-screen-tabs" aria-label="{{ __('chatbot.preview_title') }}">
                    @foreach(['home','new-order','reorder','products','cart','orders','finance','support'] as $candidate)
                        @if($candidate !== 'finance' || auth()->user()->hasPermission('finance.view'))
                            <a href="{{ route('admin.whatsapp-chatbot.preview',['screen'=>$candidate,'customer_id'=>$customer?->id]) }}" @if($screen===$candidate) aria-current="page" @endif>{{ __('chatbot.preview_screens.'.$candidate) }}</a>
                        @endif
                    @endforeach
                </nav>
                <form method="get">
                    <label for="preview-customer">{{ __('chatbot.admin.select_customer') }}</label>
                    <select class="foodex-control" id="preview-customer" name="customer_id" onchange="this.form.submit()">
                        @forelse($customers as $candidate)
                            <option value="{{ $candidate->id }}" @selected($customer?->id===$candidate->id)>{{ $candidate->name }}{{ $candidate->phone ? ' · '.$candidate->phone : '' }}</option>
                        @empty
                            <option value="">{{ __('chatbot.preview.no_customer') }}</option>
                        @endforelse
                    </select>
                </form>
            </div>

            <div class="foodex-alert" role="note">{{ __('chatbot.preview.draft_notice') }}</div>

            <section class="cbp-stage">
                <div class="cbp-phone" aria-label="{{ __('chatbot.preview_screens.'.$screen) }}">
                    <div class="cbp-phone-inner">
                        <div class="cbp-phone-head">
                            <div class="cbp-brand"><div class="cbp-brand-mark">F</div><div><strong>FOODEX</strong><div class="cbp-muted">{{ __('chatbot.preview.live_data') }}</div></div></div> {{-- localization-gate: allow — FOODEX brand monogram is intentionally not translated --}}
                            <span class="cbp-pill">{{ $settings['mode'] === 'hybrid' ? __('chatbot.admin.mode_hybrid') : __('chatbot.admin.mode_'.$settings['mode']) }}</span>
                        </div>
                        <div class="cbp-content">
                            @if($customer === null)
                                <div class="foodex-empty-state">{{ __('chatbot.preview.no_customer') }}</div>
                            @elseif($screen === 'home')
                                <div class="cbp-card cbp-hero">
                                    <strong>{{ $customer->name }}</strong>
                                    <p>{{ $settings[app()->getLocale()==='ar' ? 'welcome_ar' : 'welcome_en'] ?: __('chatbot.customer.welcome_default') }}</p>
                                </div>
                                <div class="cbp-grid">
                                    @forelse($features as $feature)
                                        @if(in_array($feature->code,['new_order','repeat_order','product_search','prices_offers','order_tracking','finance','complaints','human_handoff'],true))
                                            <a class="cbp-action" href="{{ match($feature->code) {
                                                'new_order' => route('admin.whatsapp-chatbot.preview',['screen'=>'new-order','customer_id'=>$customer->id]),
                                                'repeat_order' => route('admin.whatsapp-chatbot.preview',['screen'=>'reorder','customer_id'=>$customer->id]),
                                                'product_search','prices_offers' => route('admin.whatsapp-chatbot.preview',['screen'=>'products','customer_id'=>$customer->id]),
                                                'order_tracking' => route('admin.whatsapp-chatbot.preview',['screen'=>'orders','customer_id'=>$customer->id]),
                                                'finance' => auth()->user()->hasPermission('finance.view') ? route('admin.whatsapp-chatbot.preview',['screen'=>'finance','customer_id'=>$customer->id]) : '#',
                                                'complaints','human_handoff' => route('admin.whatsapp-chatbot.preview',['screen'=>'support','customer_id'=>$customer->id]),
                                                default => '#'
                                            } }}"><strong>{{ app()->getLocale()==='ar' ? $feature->name_ar : $feature->name_en }}</strong><span class="cbp-muted">{{ __('chatbot.admin.visible') }}</span></a>
                                        @endif
                                    @empty
                                        <div class="foodex-empty-state">{{ __('chatbot.admin.no_data') }}</div>
                                    @endforelse
                                </div>
                            @elseif($screen === 'new-order')
                                <input class="foodex-control cbp-search" type="search" placeholder="{{ __('chatbot.preview.search_placeholder') }}" aria-label="{{ __('chatbot.preview.search_placeholder') }}">
                                <div class="cbp-card">
                                    @forelse($products as $product)
                                        <div class="cbp-row"><div><strong>{{ $product->name }}</strong><div class="cbp-muted">{{ $product->sku }}</div></div><button class="foodex-action-secondary" type="button" disabled>+</button></div>
                                    @empty<div class="foodex-empty-state">{{ __('chatbot.preview.no_products') }}</div>@endforelse
                                </div>
                            @elseif($screen === 'reorder')
                                <div class="cbp-card cbp-hero"><strong>{{ __('chatbot.preview.smart_reorder') }}</strong><p>{{ __('chatbot.preview.smart_reorder_hint') }}</p></div>
                                @forelse($orders->take(4) as $order)
                                    <div class="cbp-card"><div class="cbp-row"><strong>#{{ $order->order_number }}</strong><span class="cbp-price">{{ number_format((float)$order->grand_total,3) }} {{ $order->currency }}</span></div><button class="foodex-action-primary" type="button" disabled>{{ __('chatbot.customer.repeat_order') }}</button></div>
                                @empty<div class="foodex-empty-state">{{ __('chatbot.preview.no_orders') }}</div>@endforelse
                            @elseif($screen === 'products')
                                <input class="foodex-control cbp-search" type="search" placeholder="{{ __('chatbot.preview.search_placeholder') }}" aria-label="{{ __('chatbot.preview.search_placeholder') }}">
                                @forelse($products as $product)
                                    <article class="cbp-card"><div class="cbp-row"><div><strong>{{ $product->name }}</strong><div class="cbp-muted">{{ $product->sku }}</div></div><span class="cbp-pill">{{ __('chatbot.customer.product_search') }}</span></div>@if($product->description)<p class="cbp-muted">{{ \Illuminate\Support\Str::limit($product->description,120) }}</p>@endif</article> {{-- localization-gate: allow — description is source data; the Blade condition is not user-facing text --}}
                                @empty<div class="foodex-empty-state">{{ __('chatbot.preview.no_products') }}</div>@endforelse
                            @elseif($screen === 'cart')
                                <h2>{{ __('chatbot.preview.cart_title') }}</h2>
                                <div class="cbp-card">
                                    @forelse($cartItems as $item)
                                        <div class="cbp-row"><div><strong>{{ $item->name }}</strong><div class="cbp-muted">{{ $item->sku }}</div></div><div><strong>× {{ rtrim(rtrim(number_format((float)$item->quantity,3),'0'),'.') }}</strong><div class="cbp-muted">{{ number_format((float)($item->unit_price_snapshot ?? 0),3) }}</div></div></div>
                                    @empty<div class="foodex-empty-state">{{ __('chatbot.preview.empty_cart') }}</div>@endforelse
                                </div>
                                <div class="cbp-warning">{{ __('chatbot.customer.draft_only') }}</div>
                            @elseif($screen === 'orders')
                                <h2>{{ __('chatbot.preview.tracking_title') }}</h2>
                                @forelse($orders as $order)
                                    @php($statusKey='chatbot.customer.statuses.'.strtolower((string)$order->status))
                                    <article class="cbp-card"><div class="cbp-row"><strong>#{{ $order->order_number }}</strong><span class="cbp-pill">{{ \Illuminate\Support\Facades\Lang::has($statusKey) ? __($statusKey) : __('chatbot.admin.status') }}</span></div><div class="cbp-row"><span class="cbp-muted">{{ $order->created_at }}</span><span class="cbp-price">{{ number_format((float)$order->grand_total,3) }} {{ $order->currency }}</span></div></article>
                                @empty<div class="foodex-empty-state">{{ __('chatbot.preview.no_orders') }}</div>@endforelse
                            @elseif($screen === 'finance')
                                <h2>{{ __('chatbot.preview.finance_title') }}</h2>
                                <div class="cbp-warning">{{ __('chatbot.customer.finance_secure') }}</div>
                                @forelse($invoices as $invoice)
                                    <article class="cbp-card"><div class="cbp-row"><strong>{{ $invoice->invoice_number }}</strong><span class="cbp-price">{{ number_format((float)$invoice->total,3) }} {{ $invoice->currency }}</span></div><div class="cbp-muted">{{ $invoice->issued_at }}</div></article>
                                @empty<div class="foodex-empty-state">{{ __('chatbot.preview.no_invoices') }}</div>@endforelse
                            @elseif($screen === 'support')
                                <h2>{{ __('chatbot.preview.support_title') }}</h2>
                                <div class="cbp-card cbp-hero">{{ __('chatbot.preview.support_hint') }}</div>
                                <div class="cbp-grid">
                                    @foreach(['missing_item','damaged_item','incorrect_quantity','delivery_problem','invoice_problem','other_problem'] as $issue)<button class="cbp-action" type="button" disabled><strong>{{ __('chatbot.customer.'.$issue) }}</strong></button>@endforeach
                                    <button class="cbp-action" type="button" disabled><strong>{{ __('chatbot.customer.human_handoff') }}</strong></button>
                                </div>
                            @endif
                        </div>
                        <div class="cbp-bottom"><input class="foodex-control" type="text" placeholder="{{ __('chatbot.customer.welcome_default') }}" disabled><button class="foodex-action-primary" type="button" disabled>➤</button></div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
