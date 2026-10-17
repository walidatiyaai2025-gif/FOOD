<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('whatsapp.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="whatsapp-foundation">
    <aside class="sidebar">
        @include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])
    </aside>
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        @include('admin._admin-center-switcher', ['active'=>'administration'])
        <header class="foodex-page-toolbar">
            <div><h1>{{ __('whatsapp.title') }}</h1><p>{{ __('whatsapp.description') }}</p></div>
            <div class="foodex-header-actions"><a class="action" href="{{ route('admin.whatsapp.connections.index') }}">{{ __('whatsapp.connections.title') }}</a><a class="primary" href="{{ route('admin.administration.index') }}">{{ __('whatsapp.back') }}</a></div>
        </header>
        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif
        @if(!$state->available)
        <p class="foodex-alert error" role="alert">{{ __('whatsapp.unavailable') }}</p>
        @endif
        <section class="panel" aria-labelledby="module-status">
            <h2 id="module-status">{{ __('whatsapp.status') }}</h2>
            <p data-whatsapp-lifecycle="{{ $state->lifecycle->value }}">{{ __('whatsapp.states.'.$state->lifecycle->value) }}</p>
            <h3>{{ __('whatsapp.readiness') }}</h3><p>{{ __('whatsapp.'.($certified ? 'certified' : 'uncertified')) }}</p>
        </section>
        @if($state->available)
        @if(auth()->user()->hasRole('SUPER_ADMIN'))
        <section class="panel">
            <h2>{{ __('whatsapp.emergency') }}</h2><p>{{ __('whatsapp.emergency_hint') }}</p>
            <p role="status">{{ __('whatsapp.'.($state->emergencyStop ? 'stop_on' : 'stop_off')) }}</p>
            <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.update') }}">
                @csrf @method('PUT')
                <input type="hidden" name="action" value="emergency"><input type="hidden" name="revision" value="{{ $state->revision }}">
                <input type="hidden" name="emergency_stop" value="{{ $state->emergencyStop ? '0' : '1' }}">
                @if($state->emergencyStop)
                <label><input type="checkbox" name="confirm" value="1" required> {{ __('whatsapp.clear_confirm') }}</label>
                @endif
                <button class="primary" type="submit">{{ __('whatsapp.'.($state->emergencyStop ? 'clear_stop' : 'engage_stop')) }}</button>
            </form>
        </section>
        <section class="panel">
            <h2>{{ __('whatsapp.lifecycle') }}</h2>
            <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.update') }}">
                @csrf @method('PUT')
                <input type="hidden" name="action" value="lifecycle"><input type="hidden" name="revision" value="{{ $state->revision }}">
                <label for="lifecycle">{{ __('whatsapp.lifecycle') }}</label>
                <select class="foodex-control" id="lifecycle" name="lifecycle">
                    @foreach(['DISABLED', 'INTERNAL_TEST'] as $lifecycle)
                    <option value="{{ $lifecycle }}" @selected($state->lifecycle->value === $lifecycle)>{{ __('whatsapp.states.'.$lifecycle) }}</option>
                    @endforeach
                </select>
                <label><input type="checkbox" name="confirm" value="1" required> {{ __('whatsapp.confirm') }}</label>
                <button class="primary" type="submit">{{ __('whatsapp.save') }}</button>
            </form>
        </section>
        @endif
        <section class="panel">
            <h2>{{ __('whatsapp.capabilities') }}</h2><p>{{ __('whatsapp.capabilities_hint') }}</p>
            <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.update') }}">
                @csrf @method('PUT')
                <input type="hidden" name="action" value="configure"><input type="hidden" name="revision" value="{{ $state->revision }}">
                <fieldset @disabled(!auth()->user()->hasPermission('whatsapp.settings'))>
                    <legend>{{ __('whatsapp.capabilities') }}</legend>
                    @foreach($capabilities as $capability)
                    <input type="hidden" name="capabilities[{{ $capability->value }}]" value="0">
                    <label><input type="checkbox" name="capabilities[{{ $capability->value }}]" value="1" @checked($state->has($capability))> {{ __('whatsapp.features.'.$capability->value) }}</label>
                    @endforeach
                    <label for="test-numbers">{{ __('whatsapp.test_numbers') }}</label>
                    <p id="test-numbers-help">{{ __('whatsapp.test_numbers_hint') }}</p>
                    <textarea class="foodex-control" id="test-numbers" name="test_numbers" rows="4" dir="ltr" aria-describedby="test-numbers-help">{{ old('test_numbers', implode("\n", $state->testNumbers)) }}</textarea>
                    <button class="primary" type="submit">{{ __('whatsapp.save') }}</button>
                </fieldset>
            </form>
        </section>
        @if($canActivate)
        <section class="panel">
            <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.update') }}">
                @csrf @method('PUT')<input type="hidden" name="action" value="activate"><input type="hidden" name="revision" value="{{ $state->revision }}">
                <label><input type="checkbox" name="confirm" value="1" required> {{ __('whatsapp.activate_confirm') }}</label>
                <button class="primary" type="submit">{{ __('whatsapp.activate') }}</button>
            </form>
        </section>
        @endif
        @endif
    </main>
</div>
<script>
document.querySelectorAll('.foodex-form').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.saving) { event.preventDefault(); return; }
        form.dataset.saving = '1';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach(button => {
            button.disabled = true;
            button.textContent = @json(__('whatsapp.saving'));
        });
    });
});
</script>
</body>
</html>
