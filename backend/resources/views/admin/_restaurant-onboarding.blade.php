@php
    $profile = $onboardingStatus['profile'] ?? null;
    $completion = (int) ($onboardingStatus['completion_percent'] ?? 0);
    $supportParams = $supportAccess ? ['support_access' => 1] : [];
    $onboardingCompact = (bool) ($restaurantOnboardingCompact ?? false);
    $onboardingGates = collect($onboardingStatus['gates'] ?? []);
    $readyGateCount = $onboardingGates->where('state', 'ready')->count();
    $blockedGateCount = $onboardingGates->where('state', 'blocked')->count();
    $attentionGateCount = $onboardingGates->where('state', 'attention')->count();
    $setupSteps = collect($onboardingStatus['setup_flow'] ?? [])->keyBy('key');
    $currentSetupKey = (string) ($onboardingStatus['current_step'] ?? 'identity');
    $currentSetupStep = $setupSteps->get($currentSetupKey);
    $restaurantChannelsStepAvailable = (bool) ($setupSteps->get('channels')['available'] ?? false);
    $restaurantOperationsStepAvailable = (bool) ($setupSteps->get('operations')['available'] ?? false);
    $restaurantMenuStepAvailable = (bool) ($setupSteps->get('menu')['available'] ?? false);
    $restaurantEntryStepAvailable = (bool) ($setupSteps->get('entry')['available'] ?? false);
    $hardBlockerKeys = collect($onboardingStatus['hard_blockers'] ?? [])->pluck('key');
@endphp
<section class="panel so-onboarding {{ $onboardingCompact ? 'is-compact' : '' }}" aria-labelledby="restaurant-onboarding-title">
    <style>
        .so-onboarding{display:grid;gap:var(--foodex-space-4)}
        .so-onboarding-head{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);flex-wrap:wrap}
        .so-onboarding-progress{min-width:min(100%,260px);display:grid;gap:var(--foodex-space-2)}
        .so-onboarding-progress-track{height:var(--foodex-space-2);border-radius:999px;background:var(--foodex-border);overflow:hidden}
        .so-onboarding-progress-value{height:100%;background:var(--foodex-green);border-radius:inherit}
        .so-onboarding-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:var(--foodex-space-4)}
        .so-onboarding-gates{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:var(--foodex-space-2)}
        .so-onboarding-gate{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:var(--foodex-space-3);display:grid;gap:var(--foodex-space-2);background:var(--foodex-surface)}
        .so-onboarding-gate-top{display:flex;justify-content:space-between;align-items:flex-start;gap:var(--foodex-space-2)}
        .so-onboarding-state{display:inline-flex;align-items:center;min-height:var(--foodex-space-8);padding:var(--foodex-space-1) var(--foodex-space-2);border-radius:999px;font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-background);color:var(--foodex-ink)}
        .so-onboarding-state[data-state="ready"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .so-onboarding-state[data-state="blocked"]{background:var(--foodex-danger-soft);color:var(--foodex-danger-text)}
        .so-onboarding-state[data-state="attention"]{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .so-onboarding-current{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--foodex-action-green);border-radius:14px;background:var(--foodex-green-soft)}
        .so-onboarding-current-number{width:44px;height:44px;border-radius:999px;display:grid;place-items:center;background:var(--foodex-action-green);color:#fff;font-weight:900}
        .so-onboarding-current h3{margin:0;font-size:.9rem}.so-onboarding-current p{margin:3px 0 0;color:var(--foodex-muted);font-size:.72rem}
        .so-onboarding-actions{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap}
        .so-onboarding-imports{display:grid;gap:var(--foodex-space-2)}
        .so-onboarding-import{border-top:1px solid var(--foodex-border);padding-top:var(--foodex-space-2);display:grid;gap:var(--foodex-space-1)}
        .so-onboarding-import:first-child{border-top:0;padding-top:0}
        .so-onboarding-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
        .so-onboarding-form-grid .span-2{grid-column:1/-1}
        .so-onboarding.is-compact{gap:8px;padding:12px!important}
        .so-onboarding.is-compact .so-onboarding-head{align-items:center}
        .so-onboarding.is-compact .so-onboarding-progress{min-width:min(100%,220px)}
        .so-onboarding-gates-disclosure{border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);overflow:hidden}
        .so-onboarding-gates-disclosure>summary{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:40px;padding:8px 10px;cursor:pointer;list-style:none;font-size:.72rem;font-weight:900}
        .so-onboarding-gates-disclosure>summary::-webkit-details-marker{display:none}
        .so-onboarding-gate-counts{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
        .so-onboarding-gate-count{padding:3px 7px;border-radius:999px;font-size:.61rem;font-weight:900}
        .so-onboarding-gate-count.ready{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .so-onboarding-gate-count.blocked{background:var(--foodex-danger-soft);color:var(--foodex-danger-text)}
        .so-onboarding-gate-count.attention{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
        .so-onboarding-gates-disclosure .so-onboarding-gates{padding:0 8px 8px}
        .so-onboarding.is-compact .so-onboarding-grid{gap:8px}

        @media (max-width:767px){
            .so-onboarding-form-grid{grid-template-columns:1fr}
            .so-onboarding-form-grid .span-2{grid-column:auto}
            .so-onboarding-actions>*{flex:1 1 100%}
        }
    </style>

    <div class="so-onboarding-head">
        <div>
            <h2 id="restaurant-onboarding-title">{{ __('restaurant_onboarding.title') }}</h2>
            <p>{{ __('restaurant_onboarding.description') }}</p>
        </div>
        <div class="so-onboarding-progress">
            <strong>{{ __('restaurant_onboarding.completion', ['percent' => $completion]) }}</strong>
            <div class="so-onboarding-progress-track"
                 role="progressbar"
                 aria-label="{{ __('restaurant_onboarding.title') }}"
                 aria-valuemin="0"
                 aria-valuemax="100"
                 aria-valuenow="{{ $completion }}">
                <div class="so-onboarding-progress-value" style="width:{{ $completion }}%"></div>
            </div>
        </div>
    </div>

    @if(!($onboardingStatus['launched'] ?? false) && is_array($currentSetupStep))
        <div class="so-onboarding-current" data-restaurant-current-setup="{{ $currentSetupKey }}">
            <span class="so-onboarding-current-number">{{ $currentSetupStep['number'] ?? 1 }}</span>
            <div>
                <h3>{{ __('restaurant_onboarding.flow.steps.'.$currentSetupKey.'.title') }}</h3>
                <p>{{ __('restaurant_onboarding.flow.steps.'.$currentSetupKey.'.description') }}</p>
            </div>
            <strong>{{ __('restaurant_onboarding.flow.current') }}</strong>
        </div>
    @endif

    @if($onboardingCompact)
        <details class="so-onboarding-gates-disclosure">
            <summary>
                <span>{{ __('restaurant_onboarding.title') }}</span>
                <span class="so-onboarding-gate-counts">
                    <span class="so-onboarding-gate-count ready">{{ $readyGateCount }} {{ __('restaurant_onboarding.states.ready') }}</span>
                    @if($blockedGateCount)<span class="so-onboarding-gate-count blocked">{{ $blockedGateCount }} {{ __('restaurant_onboarding.states.blocked') }}</span>@endif
                    @if($attentionGateCount)<span class="so-onboarding-gate-count attention">{{ $attentionGateCount }} {{ __('restaurant_onboarding.states.attention') }}</span>@endif
                </span>
            </summary>
            <div class="so-onboarding-gates">
    @else
            <div class="so-onboarding-gates">
    @endif
        @foreach($onboardingStatus['gates'] ?? [] as $gate)
            <article class="so-onboarding-gate">
                <div class="so-onboarding-gate-top">
                    <strong>{{ __('restaurant_onboarding.gates.'.$gate['key']) }}</strong>
                    <span class="so-onboarding-state" data-state="{{ $gate['state'] }}"> {{-- localization-gate: allow — data-state is a non-user-facing style selector; visible state text is translated below. --}}
                        {{ __('restaurant_onboarding.states.'.$gate['state']) }}
                    </span>
                </div>
                <small>{{ $gate['hard'] ? __('restaurant_onboarding.hard_gate') : __('restaurant_onboarding.soft_gate') }}</small>
            </article>
        @endforeach
            </div>
    @if($onboardingCompact)
        </details>
    @endif

    @if(!($onboardingStatus['launched'] ?? false))
        <div class="so-onboarding-actions" data-restaurant-next-action>
            @if($currentSetupKey === 'channels' && $restaurantChannelsStepAvailable)
                <a class="button primary" href="#settings-modules">{{ __('restaurant_onboarding.flow.open_step') }} · {{ __('restaurant_onboarding.flow.steps.channels.title') }}</a>
            @elseif($currentSetupKey === 'operations' && $restaurantOperationsStepAvailable)
                <a class="button primary" href="{{ route('admin.smart-ordering.operations.index', ['store_id' => $site->store_id, 'tab' => 'branches'] + $supportParams) }}">{{ __('restaurant_onboarding.actions.operations') }}</a>
            @elseif($currentSetupKey === 'menu' && $restaurantMenuStepAvailable)
                <a class="button primary" href="{{ route('admin.smart-ordering.menu.index', ['store_id' => $site->store_id, 'setup' => 'menu'] + $supportParams) }}">{{ __('restaurant_onboarding.actions.menu') }}</a>
            @elseif($currentSetupKey === 'entry' && $restaurantEntryStepAvailable)
                <a class="button primary" href="{{ route('admin.smart-ordering.qr.index', ['store_id' => $site->store_id] + $supportParams) }}">{{ __('restaurant_onboarding.actions.qr') }}</a>
            @elseif($currentSetupKey === 'review' && $hardBlockerKeys->contains('contact_channel'))
                <a class="button primary" href="{{ route('admin.smart-ordering.operations.index', ['store_id' => $site->store_id, 'tab' => 'branches'] + $supportParams) }}">{{ __('restaurant_onboarding.actions.contact_channel') }}</a>
            @endif
        </div>
    @endif

    <div class="so-onboarding-grid">
        <section>
            <h3>{{ __('restaurant_onboarding.profile_title') }}</h3>
            <p>{{ __('restaurant_onboarding.profile_description') }}</p>
            @if($canManageOnboarding)
                <form class="foodex-form js-onboarding-submit-lock" method="post" action="{{ route('admin.smart-ordering.onboarding.profile', [$site] + $supportParams) }}">
                    @csrf @method('PUT')
                    <div class="so-onboarding-form-grid">
                        <label>
                            {{ __('restaurant_onboarding.business_name') }}
                            <input class="foodex-control" name="business_name" maxlength="160" required value="{{ old('business_name', $profile?->business_name ?? $site->store?->name) }}">
                        </label>
                        <label>
                            {{ __('restaurant_onboarding.legal_name') }}
                            <input class="foodex-control" name="legal_name" maxlength="160" required value="{{ old('legal_name', $profile?->legal_name) }}">
                        </label>
                        <label>
                            {{ __('restaurant_onboarding.timezone') }}
                            <select class="foodex-control" name="timezone" required>
                                @foreach($onboardingTimezones as $timezone)
                                    <option value="{{ $timezone }}" @selected(old('timezone', $profile?->timezone ?? 'Asia/Kuwait') === $timezone)>{{ $timezone }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>
                            {{ __('restaurant_onboarding.currency') }}
                            <input class="foodex-control so-code" name="currency" inputmode="text" maxlength="3" pattern="[A-Z]{3}" required value="{{ old('currency', $profile?->currency ?? 'KWD') }}">
                        </label>
                        <label>
                            {{ __('restaurant_onboarding.country_code') }}
                            <input class="foodex-control so-code" name="country_code" inputmode="text" maxlength="2" pattern="[A-Z]{2}" required value="{{ old('country_code', $profile?->country_code ?? 'KW') }}">
                        </label>
                        <label>
                            {{ __('restaurant_onboarding.tax_registration_number') }}
                            <input class="foodex-control" name="tax_registration_number" maxlength="80" value="{{ old('tax_registration_number', $profile?->tax_registration_number) }}">
                        </label>
                    </div>
                    <button class="primary" type="submit" data-busy-label="{{ __('restaurant_onboarding.saving') }}">
                        {{ __('restaurant_onboarding.save_profile') }}
                    </button>
                </form>
            @endif
        </section>

        @if($restaurantMenuStepAvailable)
        <section>
            <h3>{{ __('restaurant_onboarding.import_title') }}</h3>
            <p>{{ __('restaurant_onboarding.import_description') }}</p>
            <div class="so-onboarding-actions">
                <a class="button" href="{{ route('admin.smart-ordering.onboarding.template', [$site] + $supportParams) }}">
                    {{ __('restaurant_onboarding.download_template') }}
                </a>
            </div>
            @if($canManageOnboarding)
                <form class="foodex-form js-onboarding-submit-lock" method="post" enctype="multipart/form-data" action="{{ route('admin.smart-ordering.onboarding.import', [$site] + $supportParams) }}">
                    @csrf
                    <label>
                        {{ __('restaurant_onboarding.import_file') }}
                        <input class="foodex-control" type="file" name="menu_import" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <button class="primary" type="submit" data-busy-label="{{ __('restaurant_onboarding.staging') }}">
                        {{ __('restaurant_onboarding.stage_import') }}
                    </button>
                </form>
            @endif

            <h4>{{ __('restaurant_onboarding.recent_imports') }}</h4>
            <div class="so-onboarding-imports">
                <?php if (($onboardingStatus['imports'] ?? []) === []): ?>
                    <p>{{ __('restaurant_onboarding.no_imports') }}</p>
                <?php else: ?>
                    <?php foreach (($onboardingStatus['imports'] ?? []) as $import): ?>
                        <article class="so-onboarding-import">
                            <strong>{{ $import->original_filename }}</strong>
                            <span class="so-onboarding-state" data-state="attention">{{ __('restaurant_onboarding.review_required') }}</span>
                            <small>
                                {{ __('restaurant_onboarding.rows', ['count' => $import->rows_total]) }}
                                · {{ __('restaurant_onboarding.formula_rows', ['count' => $import->formula_rows]) }}
                            </small>
                            @if(is_array($import->headers) && $import->headers !== [])
                                <small>{{ __('restaurant_onboarding.headers') }}: {{ implode(' · ', $import->headers) }}</small>
                            @endif
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        @endif
    </div>

    <div class="{{ ($onboardingStatus['can_launch'] ?? false) ? 'foodex-alert success' : 'foodex-alert error' }}" role="status">
        {{ ($onboardingStatus['can_launch'] ?? false) ? __('restaurant_onboarding.ready_to_launch') : __('restaurant_onboarding.blocked_from_launch') }}
    </div>

    @if($canLaunchOnboarding)
        <form class="js-onboarding-submit-lock" method="post" action="{{ route('admin.smart-ordering.onboarding.launch', [$site] + $supportParams) }}">
            @csrf
            <button class="primary" type="submit" data-busy-label="{{ __('restaurant_onboarding.launching') }}" @disabled(!($onboardingStatus['can_launch'] ?? false))>
                {{ __('restaurant_onboarding.launch') }}
            </button>
        </form>
    @endif

    <script>
        document.querySelectorAll('.js-onboarding-submit-lock').forEach((form) => {
            form.addEventListener('submit', () => {
                const button = form.querySelector('button[type="submit"]');
                if (!button || button.disabled) return;
                button.disabled = true;
                const busyLabel = button.dataset.busyLabel;
                if (busyLabel) button.textContent = busyLabel;
            }, { once: true });
        });
    </script>
</section>
