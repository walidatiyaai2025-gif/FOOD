<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('customer_service_workspace.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.cs-shell{display:grid;gap:var(--foodex-space-5)}
.cs-search{padding:var(--foodex-space-5)}
.cs-search form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}
.cs-search label{display:grid;gap:6px;font-weight:700}
.cs-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.cs-card{padding:var(--foodex-space-5);display:grid;gap:12px;align-content:start;min-height:190px}
.cs-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.cs-card-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:22px;font-weight:800}
.cs-card h2{margin:0;font-size:1.05rem}.cs-card p{margin:0;color:var(--foodex-muted);line-height:1.7}
.cs-card .foodex-action-secondary{margin-top:auto;justify-self:start}
.cs-state{padding:28px;text-align:center}
@media(max-width:1024px){.cs-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.cs-grid{grid-template-columns:1fr}.cs-search form{grid-template-columns:1fr}.cs-search .foodex-filter-action{width:100%}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · ERP</span>
                <h1>{{ __('customer_service_workspace.title') }}</h1>
                <p>{{ __('customer_service_workspace.subtitle') }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        <div class="cs-shell">
            @if($canCustomers)
            <section class="foodex-card cs-search" aria-labelledby="customer-service-search-title">
                <div>
                    <h2 id="customer-service-search-title">{{ __('customer_service_workspace.search_title') }}</h2>
                    <p class="foodex-subtitle">{{ __('customer_service_workspace.search_help') }}</p>
                </div>
                <form method="get" action="{{ route('admin.customer-360.index') }}">
                    <label>
                        <span>{{ __('customer_service_workspace.search_label') }}</span>
                        <input name="q" maxlength="120" autocomplete="off" placeholder="{{ __('customer_service_workspace.search_placeholder') }}">
                    </label>
                    <button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('customer_service_workspace.open_customer') }}</button>
                </form>
            </section>
            @endif

            <nav class="foodex-tabs" aria-label="{{ __('customer_service_workspace.journey_label') }}">
                @foreach($capabilities as $item)
                    <a class="foodex-tab" href="{{ $item['url'] }}">{{ __('customer_service_workspace.cards.'.$item['key'].'.title') }}</a>
                @endforeach
            </nav>

            @if($capabilities->isEmpty())
                <section class="foodex-card foodex-state cs-state" role="status">{{ __('customer_service_workspace.no_access') }}</section>
            @else
                <section class="cs-grid" aria-label="{{ __('customer_service_workspace.journey_label') }}">
                    @foreach($capabilities as $item)
                        <article class="foodex-card cs-card">
                            <div class="cs-card-head">
                                <h2>{{ __('customer_service_workspace.cards.'.$item['key'].'.title') }}</h2>
                                <span class="cs-card-icon" aria-hidden="true">{{ $item['icon'] }}</span>
                            </div>
                            <p>{{ __('customer_service_workspace.cards.'.$item['key'].'.description') }}</p>
                            <a class="foodex-action-secondary button secondary" href="{{ $item['url'] }}">
                                {{ __('customer_service_workspace.cards.'.$item['key'].'.action') }}
                            </a>
                        </article>
                    @endforeach
                </section>
            @endif

            <section class="foodex-card cs-search" role="note">
                <strong>{{ __('customer_service_workspace.truth_title') }}</strong>
                <p>{{ __('customer_service_workspace.truth_help') }}</p>
            </section>
        </div>
    </main>
</div>
</body>
</html>
