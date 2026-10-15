<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('smart_ordering.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .so-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:var(--foodex-space-4)}
        .so-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .so-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
        textarea.so-json{min-height:260px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])</aside> {{-- localization-gate: allow Blade include expression --}}
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
        <header class="foodex-page-header">
            <div><h1>{{ __('smart_ordering.title') }}</h1><p>{{ __('smart_ordering.description') }}</p></div>
            <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.operations.index') }}">{{ __('smart_ordering.operations_tab') }}</a>
        </header>
        <section class="panel">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div><h2>{{ __('smart_ordering_menu.nav_title') }}</h2><p>{{ __('smart_ordering_menu.description') }}</p></div>
                <a class="primary" href="{{ route('admin.smart-ordering.menu.index',['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('smart_ordering_menu.nav_title') }}</a>
            </div>
        </section>

        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="panel">
            <form class="foodex-form" method="get" action="{{ route('admin.smart-ordering.index') }}">
                <label for="store">{{ __('smart_ordering.store') }}</label>
                <select class="foodex-control" id="store" name="store_id" onchange="this.form.submit()">
                    @foreach($stores as $store)
                    <option value="{{ $store->id }}" @selected((int)$site->store_id === (int)$store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
            </form>
        </section>

        <div class="so-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering.runtime') }}</h2>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.runtime', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label><input type="checkbox" name="master_enabled" value="1" @checked($site->master_enabled)> {{ __('smart_ordering.master_enabled') }}</label>
                    <label for="runtime-state">{{ __('smart_ordering.runtime_state') }}</label>
                    <select class="foodex-control" id="runtime-state" name="runtime_state">
                        @foreach($states as $state)<option value="{{ $state }}" @selected($site->runtime_state === $state)>{{ __('smart_ordering.states.'.$state) }}</option>@endforeach
                    </select>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering.identity') }}</h2>
                <p class="so-code">{{ $site->public_id }}</p>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.slug', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label for="site-slug">{{ __('smart_ordering.slug') }}</label>
                    <input class="foodex-control so-code" id="site-slug" name="slug" value="{{ $site->slug }}" required>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
                <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.custom-domain', $site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label for="custom-domain">{{ __('smart_ordering.custom_domain') }}</label>
                    <input class="foodex-control so-code" id="custom-domain" name="custom_domain" value="{{ $site->custom_domain }}">
                    <p>{{ $site->custom_domain_verified_at ? __('smart_ordering.domain_verified') : __('smart_ordering.domain_pending') }}</p>
                    <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
                </form>
            </section>
        </div>

        <section class="panel">
            <h2>{{ __('smart_ordering.entitlements') }}</h2>
            <form class="foodex-form" method="post" action="{{ route('admin.smart-ordering.entitlements', $site) }}">
                @csrf @method('PUT')
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <div class="so-list">
                    @foreach($modules as $module)
                    <label><input type="checkbox" name="modules[]" value="{{ $module }}" @checked($entitlements[$module] ?? false)> {{ __('smart_ordering.modules.'.$module) }}</label>
                    @endforeach
                </div>
                <button class="primary" type="submit">{{ __('smart_ordering.save') }}</button>
            </form>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering.publishing') }}</h2>
            <p>{{ __('smart_ordering.published_checksum') }} <span class="so-code">{{ $published->checksum }}</span></p>
            @if(!$draft)
            <form method="post" action="{{ route('admin.smart-ordering.draft', $site) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <button class="primary" type="submit">{{ __('smart_ordering.create_draft') }}</button>
            </form>
            @else
            <p>{{ __('smart_ordering.draft_ready') }} <span class="so-code">{{ $draft->checksum }}</span></p>
            <form method="post" action="{{ route('admin.smart-ordering.revisions.publish', $draft) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <button class="primary" type="submit">{{ __('smart_ordering.publish') }}</button>
            </form>
            @endif
        </section>
    </main>
</div>
</body>
</html>
