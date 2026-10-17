<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('foodex_reset.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0}
        .launch-reset-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:var(--foodex-space-4);margin-block:var(--foodex-space-4)}
        .launch-reset-summary .foodex-card{padding:var(--foodex-space-4)}
        .launch-reset-summary strong{display:block;font-size:var(--foodex-text-xl)}
        .launch-reset-form{max-width:720px;grid-template-columns:minmax(0,1fr)}
        .launch-reset-form input:not([type=checkbox]){width:100%;min-width:0}
        .launch-reset-form .launch-reset-accept{display:flex;align-items:start;gap:var(--foodex-space-3)}
        .launch-reset-form button:disabled{opacity:.6;cursor:not-allowed}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="launch-reset">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page" data-foodex-reset="true">
@include('admin._global-header')
        @include('admin._admin-center-switcher',['active'=>'settings'])
        <div class="foodex-page-toolbar">
            <div><h1>{{ __('foodex_reset.title') }}</h1><p>{{ __('foodex_reset.description') }}</p></div>
            <div class="foodex-header-actions"><a class="foodex-action-secondary" href="{{ route('admin.settings.index') }}">{{ __('foodex_reset.back') }}</a></div>
        </div>
        @if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="foodex-alert" role="note">{{ __('foodex_reset.warning') }}</div>
        <section aria-labelledby="reset-impact">
            <h2 id="reset-impact">{{ __('foodex_reset.impact') }}</h2>
            <p>{{ __('foodex_reset.preserved') }}</p>
            @php
                $commerceTables = ['orders', 'order_items', 'invoices', 'invoice_items', 'payments'];
                $cartTables = ['carts', 'cart_items'];
                $catalogTables = ['products', 'categories', 'brands', 'units', 'product_images', 'store_products', 'product_selling_units', 'inventories', 'stock_movements', 'banners'];
                $sum = fn(array $tables) => array_sum(array_intersect_key($preview['counts'], array_flip($tables)));
            @endphp
            <div class="launch-reset-summary">
                <div class="foodex-card"><span>{{ __('foodex_reset.total') }}</span><strong class="foodex-number">{{ $preview['total'] }}</strong></div>
                <div class="foodex-card"><span>{{ __('foodex_reset.commerce') }}</span><strong class="foodex-number">{{ $sum($commerceTables) }}</strong></div>
                <div class="foodex-card"><span>{{ __('foodex_reset.catalog') }}</span><strong class="foodex-number">{{ $sum($catalogTables) }}</strong></div>
                <div class="foodex-card"><span>{{ __('foodex_reset.carts') }}</span><strong class="foodex-number">{{ $sum($cartTables) }}</strong></div>
                <div class="foodex-card"><span>{{ __('foodex_reset.operations') }}</span><strong class="foodex-number">{{ $preview['total'] - $sum(array_merge($commerceTables, $catalogTables, $cartTables)) }}</strong></div>
            </div>
        </section>
        @if(!$preview['ready'])
            <div class="foodex-state" role="alert" data-reset-blocked="true">
                @if(!$preview['catalog_ready'])<p>{{ __('foodex_reset.catalog_blocked') }}</p>@endif
                @if(!$preview['references_ready'])<p>{{ __('foodex_reset.references_blocked') }}</p>@endif
                @if(!$preview['production_ready'])<p>{{ __('foodex_reset.production_blocked') }}</p>@endif
            </div>
        @else
            <div class="foodex-state" role="status">{{ __('foodex_reset.ready') }}</div>
        @endif
        <form class="foodex-form launch-reset-form" method="post" action="{{ route('admin.foodex-reset.store') }}" data-reset-form="true">
            @csrf
            <h2>{{ __('foodex_reset.confirm_title') }}</h2>
            <p>{{ __('foodex_reset.expires') }}</p>
            <label for="reset-confirmation">{{ __('foodex_reset.confirmation') }}: <strong>{{ __('foodex_reset.phrase') }}</strong></label>
            <input id="reset-confirmation" name="confirmation" placeholder="{{ __('foodex_reset.confirmation') }}" autocomplete="off" required @disabled(!$preview['ready'])>
            @if($production)
                <div class="foodex-alert" role="alert">{{ __('foodex_reset.production_secondary_warning') }}</div>
                <label for="reset-production-confirmation">{{ __('foodex_reset.production_confirmation') }}: <strong>{{ \App\Services\FoodexResetService::PRODUCTION_CONFIRMATION }}</strong></label>
                <input id="reset-production-confirmation" name="production_confirmation" placeholder="{{ __('foodex_reset.production_confirmation') }}" autocomplete="off" required @disabled(!$preview['ready'])>
            @endif
            <label for="reset-password">{{ __('foodex_reset.password') }}</label>
            <input id="reset-password" type="password" name="current_password" placeholder="{{ __('foodex_reset.password') }}" autocomplete="current-password" required @disabled(!$preview['ready'])>
            <label class="launch-reset-accept"><input type="checkbox" name="understood" value="1" required @disabled(!$preview['ready'])><span>{{ __('foodex_reset.understood') }}</span></label>
            <button class="foodex-primary" type="submit" @disabled(!$preview['ready'])>{{ __('foodex_reset.submit') }}</button>
            <a href="{{ route('admin.foodex-reset.index') }}">{{ __('foodex_reset.refresh') }}</a>
        </form>
    </main>
</div>
<script>
const resetForm = document.querySelector('[data-reset-form]');
resetForm.addEventListener('submit', event => {
    if (resetForm.dataset.busy === 'true') { event.preventDefault(); return; }
    resetForm.dataset.busy = 'true';
    resetForm.setAttribute('aria-busy', 'true');
    const button = resetForm.querySelector('button[type=submit]');
    button.disabled = true;
    button.textContent = @json(__('foodex_reset.submitting'));
});
</script>
</body>
</html>
