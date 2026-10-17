<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('whatsapp.connections.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="whatsapp-connections">
    <aside class="sidebar">
        @include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'administration_hub', 'user' => auth()->user()])
    </aside>
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        @include('admin._admin-center-switcher', ['active'=>'administration'])
        <div class="foodex-page-toolbar">
            <div>
                <h1>{{ __('whatsapp.connections.title') }}</h1>
                <p>{{ __('whatsapp.connections.description') }}</p>
            </div>
            <div class="foodex-header-actions">
                <a class="action" href="{{ route('admin.whatsapp.index') }}">{{ __('whatsapp.connections.module_controls') }}</a>
                <a class="primary" href="{{ route('admin.administration.index') }}">{{ __('whatsapp.back') }}</a>
            </div>
        </div>

        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="panel" aria-labelledby="whatsapp-global-state">
            <h2 id="whatsapp-global-state">{{ __('whatsapp.connections.global_state') }}</h2>
            <p><strong>{{ __('whatsapp.states.'.$moduleState->lifecycle->value) }}</strong></p>
            @if($moduleState->lifecycle->value === 'DISABLED')
                <p class="muted">{{ __('whatsapp.connections.disabled_notice') }}</p>
            @endif
            <p class="muted">{{ __('whatsapp.connections.global_policy_wins') }}</p>
        </section>

        @if($transportHealth !== null)
        <section class="panel" aria-labelledby="whatsapp-transport-health">
            <h2 id="whatsapp-transport-health">{{ __('whatsapp.connections.transport_health') }}</h2>
            <p class="muted">{{ __('whatsapp.connections.transport_health_hint') }}</p>
            <dl>
                <dt>{{ __('whatsapp.connections.outbound_retry') }}</dt><dd>{{ $transportHealth['outbound_retry'] }}</dd>
                <dt>{{ __('whatsapp.connections.outbound_failed') }}</dt><dd>{{ $transportHealth['outbound_failed'] }}</dd>
                <dt>{{ __('whatsapp.connections.webhook_retry') }}</dt><dd>{{ $transportHealth['webhook_retry'] }}</dd>
                <dt>{{ __('whatsapp.connections.webhook_failed') }}</dt><dd>{{ $transportHealth['webhook_failed'] }}</dd>
            </dl>
        </section>
        @endif

        @if($canManage)
        <section class="panel" aria-labelledby="new-connection-profile">
            <h2 id="new-connection-profile">{{ __('whatsapp.connections.create') }}</h2>
            <p class="muted">{{ __('whatsapp.connections.create_hint') }}</p>
            <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.connections.store') }}">
                @csrf
                <label>{{ __('whatsapp.connections.name') }}<input class="foodex-control" name="name" required maxlength="120" value="{{ old('name') }}"></label>
                <label>{{ __('whatsapp.connections.display_phone') }}<input class="foodex-control" name="display_phone_number" required maxlength="32" value="{{ old('display_phone_number') }}"></label>
                <label>{{ __('whatsapp.connections.normalized_phone') }}<input class="foodex-control" name="normalized_phone_number" maxlength="24" placeholder="+965..." value="{{ old('normalized_phone_number') }}"></label>
                <label>{{ __('whatsapp.connections.provider') }}<input class="foodex-control" name="provider_type" required maxlength="40" value="{{ old('provider_type', 'meta_cloud') }}"></label>
                <label>{{ __('whatsapp.connections.phone_number_id') }}<input class="foodex-control" name="provider_phone_number_id" maxlength="128" value="{{ old('provider_phone_number_id') }}"></label>
                <label>{{ __('whatsapp.connections.account_id') }}<input class="foodex-control" name="provider_account_id" maxlength="128" value="{{ old('provider_account_id') }}"></label>
                <label>{{ __('whatsapp.connections.app_id') }}<input class="foodex-control" name="provider_app_id" maxlength="128" value="{{ old('provider_app_id') }}"></label>
                <label>{{ __('whatsapp.connections.secret') }}<input class="foodex-control" type="password" name="credential_secret" maxlength="10000" autocomplete="new-password"></label>
                <label>{{ __('whatsapp.connections.webhook_verify_token') }}<input class="foodex-control" type="password" name="webhook_verify_token" maxlength="10000" autocomplete="new-password"></label>
                <label>{{ __('whatsapp.connections.webhook_signing_secret') }}<input class="foodex-control" type="password" name="webhook_signing_secret" maxlength="10000" autocomplete="new-password"></label>
                <label>{{ __('whatsapp.connections.provider_metadata') }}<textarea class="foodex-control" name="provider_metadata" rows="3">{{ old('provider_metadata') }}</textarea></label>
                <label>{{ __('whatsapp.connections.webhook_metadata') }}<textarea class="foodex-control" name="webhook_metadata" rows="3">{{ old('webhook_metadata') }}</textarea></label>
                <button class="primary" type="submit">{{ __('whatsapp.connections.create') }}</button>
            </form>
        </section>
        @endif

        <section class="panel" aria-labelledby="saved-connections">
            <h2 id="saved-connections">{{ __('whatsapp.connections.saved_profiles') }}</h2>
            @forelse($profiles as $profile)
            <article class="card" data-whatsapp-connection="{{ $profile->id }}">
                <div class="section-head">
                    <div>
                        <h3>{{ $profile->name }}</h3>
                        <p>{{ $profile->display_phone_number }} · {{ $profile->provider_type }}</p>
                    </div>
                    <div>
                        @if($profile->is_primary)<strong>{{ __('whatsapp.connections.primary') }}</strong>@endif
                        @if($profile->receive_only)<strong>{{ __('whatsapp.connections.receive_only') }}</strong>@endif
                    </div>
                </div>

                <dl>
                    <dt>{{ __('whatsapp.connections.status') }}</dt><dd>{{ __('whatsapp.connections.states.'.$profile->status->value) }}</dd>
                    <dt>{{ __('whatsapp.connections.health') }}</dt><dd>{{ __('whatsapp.connections.health_states.'.$profile->health_status->value) }}</dd>
                    <dt>{{ __('whatsapp.connections.normalized_phone') }}</dt><dd>{{ $profile->normalized_phone_number }}</dd>
                    <dt>{{ __('whatsapp.connections.phone_number_id') }}</dt><dd>{{ $profile->provider_phone_number_id ?: '—' }}</dd>
                    <dt>{{ __('whatsapp.connections.account_id') }}</dt><dd>{{ $profile->provider_account_id ?: '—' }}</dd>
                    <dt>{{ __('whatsapp.connections.secret') }}</dt><dd>{{ $profile->maskedSecret() ?: __('whatsapp.connections.secret_missing') }}</dd>
                    <dt>{{ __('whatsapp.connections.webhook_secrets') }}</dt><dd>{{ $profile->maskedWebhookSecrets() ?: __('whatsapp.connections.secret_missing') }}</dd>
                    <dt>{{ __('whatsapp.connections.webhook_url') }}</dt><dd>{{ route('webhooks.whatsapp.meta.ingest', $profile) }}</dd>
                    <dt>{{ __('whatsapp.connections.last_checked') }}</dt><dd>{{ $profile->last_checked_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                    <dt>{{ __('whatsapp.connections.last_webhook') }}</dt><dd>{{ $profile->last_webhook_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                    <dt>{{ __('whatsapp.connections.last_send') }}</dt><dd>{{ $profile->last_outbound_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                </dl>
                @if($profile->health_summary)<p class="muted">{{ $profile->health_summary }}</p>@endif
                @if($profile->last_provider_error)<p class="foodex-alert error">{{ $profile->last_provider_error }}</p>@endif

                @if($canManage)
                    @if(!$profile->is_primary)
                    <details>
                        <summary>{{ __('whatsapp.connections.edit') }}</summary>
                        <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.connections.update', $profile) }}">
                            @csrf @method('PATCH')
                            <label>{{ __('whatsapp.connections.name') }}<input class="foodex-control" name="name" required maxlength="120" value="{{ $profile->name }}"></label>
                            <label>{{ __('whatsapp.connections.display_phone') }}<input class="foodex-control" name="display_phone_number" required maxlength="32" value="{{ $profile->display_phone_number }}"></label>
                            <label>{{ __('whatsapp.connections.normalized_phone') }}<input class="foodex-control" name="normalized_phone_number" maxlength="24" value="{{ $profile->normalized_phone_number }}"></label>
                            <label>{{ __('whatsapp.connections.provider') }}<input class="foodex-control" name="provider_type" required maxlength="40" value="{{ $profile->provider_type }}"></label>
                            <label>{{ __('whatsapp.connections.phone_number_id') }}<input class="foodex-control" name="provider_phone_number_id" maxlength="128" value="{{ $profile->provider_phone_number_id }}"></label>
                            <label>{{ __('whatsapp.connections.account_id') }}<input class="foodex-control" name="provider_account_id" maxlength="128" value="{{ $profile->provider_account_id }}"></label>
                            <label>{{ __('whatsapp.connections.app_id') }}<input class="foodex-control" name="provider_app_id" maxlength="128" value="{{ $profile->provider_app_id }}"></label>
                            <label>{{ __('whatsapp.connections.provider_metadata') }}<textarea class="foodex-control" name="provider_metadata" rows="3">{{ json_encode($profile->provider_metadata ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea></label>
                            <label>{{ __('whatsapp.connections.webhook_metadata') }}<textarea class="foodex-control" name="webhook_metadata" rows="3">{{ json_encode($profile->webhook_metadata ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea></label>
                            <button class="primary" type="submit">{{ __('whatsapp.connections.save') }}</button>
                        </form>
                    </details>
                    @endif

                    <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.connections.validate', $profile) }}">
                        @csrf
                        <button class="action" type="submit">{{ __('whatsapp.connections.test') }}</button>
                    </form>

                    @if($canDangerous)
                    <details>
                        <summary>{{ __('whatsapp.connections.replace_secret') }}</summary>
                        <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.connections.credential', $profile) }}">
                            @csrf @method('PUT')
                            <label>{{ __('whatsapp.connections.new_secret') }}<input class="foodex-control" type="password" name="credential_secret" maxlength="10000" autocomplete="new-password"></label>
                            <label>{{ __('whatsapp.connections.webhook_verify_token') }}<input class="foodex-control" type="password" name="webhook_verify_token" maxlength="10000" autocomplete="new-password"></label>
                            <label>{{ __('whatsapp.connections.webhook_signing_secret') }}<input class="foodex-control" type="password" name="webhook_signing_secret" maxlength="10000" autocomplete="new-password"></label>
                            <p class="muted">{{ __('whatsapp.connections.secret_rotation_hint') }}</p>
                            <button class="action" type="submit">{{ __('whatsapp.connections.replace_secret') }}</button>
                        </form>
                    </details>
                    @if(!$profile->is_primary)
                    <form class="foodex-form" method="post" action="{{ route('admin.whatsapp.connections.primary', $profile) }}">
                        @csrf
                        <label>{{ __('whatsapp.connections.old_profile_action') }}
                            <select class="foodex-control" name="old_profile_action">
                                <option value="drain">{{ __('whatsapp.connections.drain_old') }}</option>
                                <option value="inactive">{{ __('whatsapp.connections.deactivate_old') }}</option>
                            </select>
                        </label>
                        <label><input type="checkbox" name="confirm" value="1" required> {{ __('whatsapp.connections.switch_confirm') }}</label>
                        <button class="primary" type="submit">{{ __('whatsapp.connections.make_primary') }}</button>
                    </form>
                    @endif
                    @endif

                    @if(!$profile->is_primary)
                    <div class="foodex-header-actions">
                        <form method="post" action="{{ route('admin.whatsapp.connections.receive-only', $profile) }}">@csrf<button class="action" type="submit">{{ __('whatsapp.connections.move_to_receive_only') }}</button></form>
                        <form method="post" action="{{ route('admin.whatsapp.connections.deactivate', $profile) }}">@csrf<button class="action" type="submit">{{ __('whatsapp.connections.deactivate') }}</button></form>
                    </div>
                    @endif
                @endif
            </article>
            @empty
                <p class="muted">{{ __('whatsapp.connections.none') }}</p>
            @endforelse
        </section>
    </main>
</div>
</body>
</html>
