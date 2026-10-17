@php
    $groups = collect($navGroups ?? [])->filter(fn ($group) => ! empty($group['children']));
    $groupIndex = $groups->keyBy('key');
    $allNavigationItems = $groups->flatMap(fn ($group) => collect($group['children'] ?? []));
    $availableModules = $groups
        ->reject(fn ($group) => in_array($group['key'] ?? '', ['overview', 'administration'], true))
        ->values();

    $actionCenterItem = $allNavigationItems->firstWhere('key', 'enterprise_action_center');
    $administrationItem = $allNavigationItems->firstWhere('key', 'administration_hub');
    $restaurantSaasItem = $allNavigationItems->firstWhere('key', 'smart_ordering');

    $actionCenterUrl = $actionCenterItem
        ? route($actionCenterItem['route'], $actionCenterItem['params'] ?? [])
        : null;
    $administrationUrl = $administrationItem
        ? route($administrationItem['route'], $administrationItem['params'] ?? [])
        : null;
    $systemHealthUrl = \Illuminate\Support\Facades\Route::has('admin.enterprise-system-health.index')
        && \Illuminate\Support\Facades\Gate::forUser($user)->allows('platform.manage')
            ? route('admin.enterprise-system-health.index')
            : null;
@endphp

<section class="foodex-module-overview fx-executive-overview" aria-labelledby="fx-command-title" data-foodex-executive-overview>
    <header class="fx-command foodex-card">
        <div class="fx-command-copy">
            <span class="fx-kicker">{{ __('admin_overview.kicker') }}</span>
            <h2 id="fx-command-title">{{ __('admin_overview.title', ['name' => $user->name]) }}</h2>
            <p>{{ __('admin_overview.subtitle') }}</p>
            <div class="foodex-header-actions fx-command-actions">
                @if($actionCenterUrl)
                    <a class="foodex-action-primary" href="{{ $actionCenterUrl }}">{{ __('admin_overview.open_action_center') }}</a>
                @endif
                @if($systemHealthUrl)
                    <a class="foodex-action-secondary" href="{{ $systemHealthUrl }}">{{ __('admin_overview.open_system_health') }}</a>
                @endif
            </div>
        </div>
        <div class="fx-scope foodex-state" role="status">
            <strong>{{ __('admin_overview.access_scope') }}</strong>
            <span>{{ __('admin_overview.access_scope_help') }}</span>
        </div>
    </header>

    <section class="fx-section foodex-card" aria-labelledby="fx-modules-title">
        <div class="fx-section-head">
            <div>
                <span class="fx-kicker">{{ __('admin_overview.platform_pulse') }}</span>
                <h2 id="fx-modules-title">{{ __('admin_overview.modules_title') }}</h2>
            </div>
            <span class="fx-count foodex-number">{{ trans_choice('admin_overview.visible_modules', $availableModules->count(), ['count' => $availableModules->count()]) }}</span>
        </div>

        <div class="fx-module-grid">
            @forelse($availableModules as $group)
                @php
                    $children = collect($group['children'] ?? []);
                    $first = $children->first();
                    $target = $first ? route($first['route'], $first['params'] ?? []) : null;
                @endphp
                <article class="fx-module-card">
                    <div class="fx-module-top">
                        <span class="fx-module-icon" aria-hidden="true">{{ $group['icon'] ?? '•' }}</span>
                        <span class="fx-state">{{ __('admin_overview.available') }}</span>
                    </div>
                    <h3>{{ __($group['label']) }}</h3>
                    <p>{{ trans_choice('admin_overview.capabilities', $children->count(), ['count' => $children->count()]) }}</p>
                    @if($target)
                        <a href="{{ $target }}">{{ __('admin_overview.open_module') }}</a>
                    @endif
                </article>
            @empty
                <div class="foodex-module-state" data-foodex-module-state="empty" role="status">
                    <strong>{{ __('admin_overview.no_modules_title') }}</strong>
                    <p>{{ __('admin_overview.no_modules_help') }}</p>
                </div>
            @endforelse
        </div>
    </section>

    <div class="fx-lower-grid">
        <section class="fx-section foodex-card" aria-labelledby="fx-priority-title">
            <div class="fx-section-head">
                <div>
                    <span class="fx-kicker">{{ __('admin_overview.decisions') }}</span>
                    <h2 id="fx-priority-title">{{ __('admin_overview.priority_title') }}</h2>
                </div>
            </div>

            <div class="fx-priority-list">
                @if($actionCenterUrl)
                    <a class="fx-priority-row" href="{{ $actionCenterUrl }}">
                        <span class="fx-priority-icon" aria-hidden="true">!</span>
                        <span><strong>{{ __('admin_overview.approvals_exceptions') }}</strong><small>{{ __('admin_overview.open_authoritative_queue') }}</small></span>
                    </a>
                @else
                    <div class="fx-priority-row fx-priority-row--state">
                        <span class="fx-priority-icon" aria-hidden="true">!</span>
                        <span><strong>{{ __('admin_overview.approvals_exceptions') }}</strong><small>{{ __('admin_overview.unavailable') }}</small></span>
                    </div>
                @endif

                @if($systemHealthUrl)
                    <a class="fx-priority-row" href="{{ $systemHealthUrl }}">
                        <span class="fx-priority-icon" aria-hidden="true">✓</span>
                        <span><strong>{{ __('admin_overview.system_integrations') }}</strong><small>{{ __('admin_overview.open_system_health_help') }}</small></span>
                    </a>
                @else
                    <div class="fx-priority-row fx-priority-row--state">
                        <span class="fx-priority-icon" aria-hidden="true">–</span>
                        <span><strong>{{ __('admin_overview.system_integrations') }}</strong><small>{{ __('admin_overview.permission_restricted') }}</small></span>
                    </div>
                @endif

                @if($administrationUrl)
                    <a class="fx-priority-row" href="{{ $administrationUrl }}">
                        <span class="fx-priority-icon" aria-hidden="true">⚙</span>
                        <span><strong>{{ __('admin_overview.administration') }}</strong><small>{{ __('admin_overview.open_admin_hub') }}</small></span>
                    </a>
                @endif
            </div>
        </section>

        <section class="fx-section foodex-card" aria-labelledby="fx-saas-title">
            <div class="fx-section-head">
                <div>
                    <span class="fx-kicker">{{ __('admin_overview.customer_services') }}</span>
                    <h2 id="fx-saas-title">{{ __('admin_overview.restaurant_saas') }}</h2>
                </div>
                <span class="fx-state">{{ $restaurantSaasItem ? __('admin_overview.available') : __('admin_overview.unavailable') }}</span>
            </div>
            <p class="fx-saas-copy">{{ __('admin_overview.restaurant_saas_help') }}</p>
            <dl class="fx-saas-facts">
                <div><dt>{{ __('admin_overview.scope') }}</dt><dd>{{ __('admin_overview.client_tenants') }}</dd></div>
                <div><dt>{{ __('admin_overview.surface') }}</dt><dd>{{ __('admin_overview.control_plane') }}</dd></div>
                <div><dt>{{ __('admin_overview.data_policy') }}</dt><dd>{{ __('admin_overview.no_synthetic_metrics') }}</dd></div>
            </dl>
            @if($restaurantSaasItem)
                <a class="foodex-action-primary" href="{{ route($restaurantSaasItem['route'], $restaurantSaasItem['params'] ?? []) }}">{{ __('admin_overview.open_restaurant_service') }}</a>
            @endif
        </section>
    </div>
</section>

<style id="foodex-executive-overview">
.fx-executive-overview{display:grid;gap:var(--foodex-space-5)}
.fx-command{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,.34fr);gap:var(--foodex-space-6);padding:var(--foodex-space-6);background:var(--foodex-green-soft)}
.fx-command-copy{min-width:0}.fx-kicker{display:block;margin-bottom:var(--foodex-space-1);color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}
.fx-command h2,.fx-section h2{margin:0}.fx-command h2{font-size:clamp(var(--foodex-text-xl),2.5vw,var(--foodex-text-2xl))}.fx-command p{max-width:760px;margin:var(--foodex-space-2) 0 0;color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}
.fx-command-actions{margin-top:var(--foodex-space-4)}.fx-command-actions a{min-height:var(--foodex-touch-target)}
.fx-scope{align-self:end;margin:0;padding:var(--foodex-space-4);background:var(--foodex-surface)}.fx-scope strong,.fx-scope span{display:block}.fx-scope span{margin-top:var(--foodex-space-1);color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
.fx-section{margin:0;padding:var(--foodex-space-5)}.fx-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}.fx-section-head h2{font-size:var(--foodex-text-lg)}
.fx-count,.fx-state{display:inline-flex;align-items:center;min-height:32px;padding:4px 10px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}
.fx-module-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-3)}.fx-module-card{min-width:0;padding:var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface)}
.fx-module-top{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-2)}.fx-module-icon{display:grid;place-items:center;inline-size:44px;block-size:44px;border-radius:var(--foodex-radius-md);background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:var(--foodex-text-lg)}
.fx-module-card h3{margin:var(--foodex-space-3) 0 var(--foodex-space-1);font-size:var(--foodex-text-md)}.fx-module-card p{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-sm)}.fx-module-card a{display:inline-flex;align-items:center;min-height:44px;margin-top:var(--foodex-space-2);color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold);text-decoration:none}
.fx-lower-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr);gap:var(--foodex-space-5)}.fx-priority-list{display:grid;gap:var(--foodex-space-2)}.fx-priority-row{display:grid;grid-template-columns:44px minmax(0,1fr);align-items:center;gap:var(--foodex-space-3);min-height:64px;padding:var(--foodex-space-2) var(--foodex-space-3);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);color:inherit;text-decoration:none}.fx-priority-row--state{background:var(--foodex-background)}
.fx-priority-icon{display:grid;place-items:center;inline-size:44px;block-size:44px;border-radius:var(--foodex-radius-control);background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold)}.fx-priority-row strong,.fx-priority-row small{display:block}.fx-priority-row small{margin-top:var(--foodex-space-1);color:var(--foodex-muted)}
.fx-saas-copy{margin:0;color:var(--foodex-muted);line-height:var(--foodex-leading-normal)}.fx-saas-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--foodex-space-2);margin:var(--foodex-space-4) 0}.fx-saas-facts div{min-width:0;padding:var(--foodex-space-3);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-background)}.fx-saas-facts dt{font-weight:var(--foodex-font-weight-bold)}.fx-saas-facts dd{margin:var(--foodex-space-1) 0 0;color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
@media(max-width:1279px){.fx-module-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:1023px){.fx-command,.fx-lower-grid{grid-template-columns:1fr}.fx-module-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:767px){.fx-executive-overview{gap:var(--foodex-space-4)}.fx-command,.fx-section{padding:var(--foodex-space-4)}.fx-section-head{flex-direction:column}.fx-command-actions{display:grid}.fx-command-actions a{width:100%;justify-content:center}.fx-module-grid{grid-template-columns:1fr}.fx-saas-facts{grid-template-columns:1fr}}
@media(max-width:389px){.fx-command,.fx-section{padding:var(--foodex-space-3)}}
</style>
