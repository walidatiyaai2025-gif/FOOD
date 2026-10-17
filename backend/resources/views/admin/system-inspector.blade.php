<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.system_inspector') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.inspector-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}
.inspector-stat{padding:var(--foodex-space-5)}.inspector-stat strong{display:block;color:var(--foodex-muted);font-size:var(--foodex-text-xs)}.inspector-stat b{display:block;margin-top:6px;font-size:1.8rem}
.inspector-toolbar{display:flex;flex-wrap:wrap;gap:var(--foodex-space-3);align-items:end;padding:var(--foodex-space-4);margin-bottom:var(--foodex-space-4)}
.inspector-toolbar label{display:grid;gap:6px;font-weight:700}.inspector-toolbar input,.inspector-toolbar select{min-width:170px}
.inspector-health{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3);padding:var(--foodex-space-5);margin-bottom:var(--foodex-space-5)}
.inspector-health-row{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:#fbfcfd}
.inspector-health-row code{font-family:var(--foodex-font-en);font-size:.78rem;overflow-wrap:anywhere;text-align:end}
.inspector-table-wrap{overflow:auto}.inspector-table{min-width:1180px}.severity-error{color:var(--foodex-red);font-weight:700}.severity-warning{color:#9a4b0b;font-weight:700}
.inspector-message{max-width:420px;white-space:normal;overflow-wrap:anywhere}.inspector-context{max-width:560px;white-space:pre-wrap;overflow-wrap:anywhere;font:12px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}
.inspector-actions{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap}
.inspector-trend{padding:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}.inspector-trend-row{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:10px;align-items:center;margin-block:9px}
.inspector-enterprise{padding:var(--foodex-space-5);margin-bottom:var(--foodex-space-5)}
.inspector-enterprise-heading{display:flex;justify-content:space-between;gap:var(--foodex-space-3);align-items:flex-start;flex-wrap:wrap}
.inspector-enterprise-section{margin-top:var(--foodex-space-5)}
.inspector-enterprise-statuses{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap;margin:var(--foodex-space-3) 0}
.inspector-enterprise-table{min-width:760px}
.restaurant-operator-heading{display:flex;justify-content:space-between;gap:var(--foodex-space-3);align-items:flex-start;flex-wrap:wrap}
.restaurant-operator-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--foodex-space-3);margin-top:var(--foodex-space-4)}
.restaurant-operator-form label{display:grid;gap:6px;font-weight:700}.restaurant-operator-form input,.restaurant-operator-form select{width:100%}
.restaurant-operator-actions{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap;align-items:end;margin-top:var(--foodex-space-4)}
.restaurant-operator-actions form{margin:0}
@media(max-width:900px){.inspector-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.inspector-health{grid-template-columns:1fr}.restaurant-operator-form{grid-template-columns:1fr}}
@media(max-width:560px){.inspector-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
        @include('admin._admin-center-switcher',['active'=>'administration'])
@php($ar=app()->getLocale()==='ar')
        <div class="foodex-page-toolbar">
            <div>
                <span class="foodex-subtitle">FOODEX · Runtime Diagnostics</span>
                <h1>{{ __('admin.system_inspector') }}</h1>
                <p>{{ $ar?'يجمع أخطاء الخادم والراوت وJavaScript وطلبات Fetch، ويصدر تقريرًا واحدًا قابلًا للتنزيل والمشاركة.':'Captures server, route, JavaScript and Fetch failures and exports one shareable diagnostic report.' }}</p>
            </div>
            <div class="inspector-actions">
                
                <a class="foodex-action-primary" href="{{ route('admin.inspector.export') }}">⇩ {{ $ar?'تنزيل التقرير':'Download report' }}</a>
                <form method="post" action="{{ route('admin.inspector.storage-link') }}">@csrf
                    <button class="foodex-action-secondary button secondary" type="submit">↻ {{ $ar?'فحص/إصلاح رابط الصور':'Check / repair image link' }}</button>
                </form>
            </div>
        </div>

        @if(session('status'))<div class="foodex-alert success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="inspector-grid">
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'إجمالي الأحداث':'Total events' }}</strong><b class="foodex-number">{{ $stats['total'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'أخطاء آخر 24 ساعة':'Errors · 24h' }}</strong><b class="foodex-number">{{ $stats['errors_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'JavaScript / Fetch · 24 ساعة':'JavaScript / Fetch · 24h' }}</strong><b class="foodex-number">{{ $stats['javascript_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'تطبيقات الموبايل · 24 ساعة':'Mobile apps · 24h' }}</strong><b class="foodex-number">{{ $stats['mobile_24h'] }}</b></article>
            <article class="foodex-card inspector-stat"><strong>{{ $ar?'أخطاء الراوت · 24 ساعة':'Route errors · 24h' }}</strong><b class="foodex-number">{{ $stats['routes_24h'] }}</b></article>
        </section>

        <section class="foodex-card inspector-health">
            <div class="inspector-health-row"><strong>{{ $ar?'الإصدار':'Version' }}</strong><code>{{ $diagnostics['version'] }}</code></div>
            <div class="inspector-health-row"><strong>APP_URL</strong><code>{{ $diagnostics['app_url'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'مجلد الصور قابل للكتابة':'Public image storage writable' }}</strong><span class="badge {{ $diagnostics['public_disk_writable']?'active':'' }}">{{ $diagnostics['public_disk_writable']?($ar?'نعم':'Yes'):($ar?'لا':'No') }}</span></div>
            <div class="inspector-health-row"><strong>{{ $ar?'رابط public/storage':'public/storage link' }}</strong><span class="badge {{ $diagnostics['public_link_exists']?'active':'' }}">{{ $diagnostics['public_link_exists']?($ar?'سليم':'Ready'):($ar?'مفقود':'Missing') }}</span></div>
            <div class="inspector-health-row"><strong>{{ $ar?'مسار التخزين':'Storage root' }}</strong><code>{{ $diagnostics['public_disk_root'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'الرابط العام للصور':'Public media URL' }}</strong><code>{{ $diagnostics['public_disk_url'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'المسارات المسماة':'Named routes' }}</strong><code>{{ $diagnostics['named_routes'] }}</code></div>
            <div class="inspector-health-row"><strong>{{ $ar?'البيئة':'Environment' }}</strong><code>{{ $diagnostics['environment'] }}</code></div>
        </section>

        <section id="system-runtime" class="foodex-module-overview" data-foodex-module-overview data-system-runtime-health>
            <div class="inspector-enterprise-heading">
                <div>
                    <span class="foodex-subtitle">FOODEX · {{ __('admin.system_update_surface.dashboard_title') }}</span>
                    <h2>{{ __('admin.system_update_surface.dashboard_title') }}</h2>
                    <p>{{ __('admin.system_update_surface.dashboard_description') }}</p>
                </div>
                <div class="inspector-actions">
                    <span class="foodex-status-badge" data-state="{{ $runtimeBadgeState }}">{{ $runtimeStateLabel }}</span>
                    <a class="foodex-action-secondary button secondary" href="{{ route('admin.system-update.index') }}">{{ __('admin.system_update_surface.backup_center') }}</a>
                </div>
            </div>
            <div class="foodex-module-kpis inspector-grid">
                @foreach($runtimeMetrics as $metric)
                    <article class="foodex-viz-card foodex-module-kpi" data-foodex-module-kpi data-interactive="false">
                        <span class="foodex-module-kpi-label">{{ $metric['label'] }}</span>
                        <strong class="foodex-module-kpi-value foodex-number">{{ $metric['value'] }}</strong>
                    </article>
                @endforeach
            </div>
            @if(($runtime['configuration_warnings'] ?? []) !== [])
                <div class="foodex-alert warning" role="status">
                    <strong>{{ __('admin.system_update_surface.config_warnings') }}</strong>
                    <ul>
                        @foreach($runtime['configuration_warnings'] as $warning)
                            <li><code>{{ $warning }}</code></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @php($incidentMax = max(1, ...array_map(static fn(array $row): int => (int)$row['count'], $incidentTrend)))
            <article class="foodex-viz-card foodex-module-chart inspector-trend" data-foodex-module-chart>
                <div class="foodex-viz-header">
                    <div>
                        <h3 class="foodex-viz-title">{{ __('admin.system_update_surface.incident_trend_title') }}</h3>
                        <p class="foodex-viz-subtitle">{{ __('admin.system_update_surface.incident_trend_description') }}</p>
                    </div>
                    <span class="badge">{{ __('admin.system_update_surface.failed_jobs_24h') }}: <span class="foodex-number">{{ $runtime['queue']['failed_jobs_24h'] ?? '—' }}</span></span>
                </div>
                @foreach($incidentTrend as $point)
                    @php($incidentPercent = max(2, (int)round(((int)$point['count'] / $incidentMax) * 100)))
                    <div class="inspector-trend-row">
                        <time datetime="{{ $point['day'] }}">{{ $point['label'] }}</time>
                        <div class="foodex-viz-progress" role="img" aria-label="{{ $point['day'] }} · {{ $point['count'] }}"><span style="width:{{ $incidentPercent }}%"></span></div>
                        <strong class="foodex-number">{{ $point['count'] }}</strong>
                    </div>
                @endforeach
                <div class="foodex-module-chart-table-wrap">
                    <table class="foodex-module-chart-table" data-pagination-exempt="fixed-7-day-runtime-summary">
                        <thead><tr><th>{{ __('admin.system_update_surface.day') }}</th><th>{{ __('admin.system_update_surface.incidents') }}</th></tr></thead>
                        <tbody>@foreach($incidentTrend as $point)<tr><td>{{ $point['day'] }}</td><td class="foodex-number">{{ $point['count'] }}</td></tr>@endforeach</tbody>
                    </table>
                </div>
            </article>
        </section>

        @php($enterpriseLabels = __('admin.system_inspector_enterprise.states'))
        @php($enterpriseData = $enterprise['data'] ?? $enterprise)
        <section class="foodex-card inspector-enterprise" data-enterprise-inspector>
            <div class="inspector-enterprise-heading">
                <div>
                    <span class="foodex-subtitle">{{ __('admin.system_inspector_enterprise.eyebrow') }}</span>
                    <h2>{{ __('admin.system_inspector_enterprise.title') }}</h2>
                    <p>{{ __('admin.system_inspector_enterprise.description') }}</p>
                </div>
                <span class="badge {{ $enterprise['available']?'active':'' }}">{{ $enterprise['available'] ? __('admin.system_inspector_enterprise.available') : __('admin.system_inspector_enterprise.unavailable') }}</span>
            </div>

            @if(! $enterprise['available'])
                <div class="foodex-empty-state">
                    {{ $enterprise['migration_pending']
                        ? __('admin.system_inspector_enterprise.migration_pending')
                        : __('admin.system_inspector_enterprise.load_failed') }}
                </div>
            @else
                <div class="inspector-enterprise-section">
                    <h3>{{ __('admin.system_inspector_enterprise.module_registry_health') }}</h3>
                    <div class="inspector-table-wrap">
                        <table class="foodex-table inspector-enterprise-table">
                            <thead><tr>
                                <th>{{ __('admin.system_inspector_enterprise.module') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.category') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.manifest_version') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.lifecycle') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.health') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.checks_run') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse($enterpriseData['modules'] as $module)
                                <tr>
                                    <td><code>{{ $module['code'] }}</code></td>
                                    <td>{{ $module['category']==='platform' ? __('admin.system_inspector_enterprise.platform') : $module['category'] }}</td>
                                    <td class="foodex-number">{{ $module['manifest_version'] }}</td>
                                    <td><span class="badge">{{ $enterpriseLabels[$module['lifecycle_state']] ?? __('admin.system_inspector_enterprise.unknown_state') }}</span></td>
                                    <td><span class="badge {{ $module['health_status']==='HEALTHY'?'active':'' }}">{{ $enterpriseLabels[$module['health_status']] ?? __('admin.system_inspector_enterprise.unknown_state') }}</span></td>
                                    <td class="foodex-number">{{ $module['check_count'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><div class="foodex-empty-state">{{ __('admin.system_inspector_enterprise.no_modules') }}</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="inspector-enterprise-section">
                    <h3>{{ __('admin.system_inspector_enterprise.entitlements') }}</h3>
                    <div class="inspector-enterprise-statuses">
                        <span class="badge">{{ __('admin.system_inspector_enterprise.total') }}: <span class="foodex-number">{{ $enterpriseData['entitlements']['total'] }}</span></span>
                        <span class="badge active">{{ __('admin.system_inspector_enterprise.enabled') }}: <span class="foodex-number">{{ $enterpriseData['entitlements']['enabled'] }}</span></span>
                        @foreach($enterpriseData['entitlements']['by_module'] as $entitlement)
                            <span class="badge"><code>{{ $entitlement['module_code'] }}</code> · {{ __('admin.system_inspector_enterprise.enabled') }} {{ $entitlement['enabled'] }}/{{ $entitlement['total'] }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="inspector-enterprise-section">
                    <h3>{{ __('admin.system_inspector_enterprise.outbox_title') }}</h3>
                    <div class="inspector-enterprise-statuses">
                        @forelse($enterpriseData['outbox_status_counts'] as $status => $count)
                            <span class="badge">{{ $enterpriseLabels[$status] ?? __('admin.system_inspector_enterprise.unknown_state') }}: <span class="foodex-number">{{ $count }}</span></span>
                        @empty
                            <span class="foodex-subtitle">{{ __('admin.system_inspector_enterprise.no_outbox') }}</span>
                        @endforelse
                    </div>
                    <div class="inspector-table-wrap">
                        <table class="foodex-table inspector-enterprise-table">
                            <thead><tr>
                                <th>{{ __('admin.system_inspector_enterprise.event_type') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.status') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.attempts') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.available_at') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.last_failure') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse($enterpriseData['outbox_messages'] as $message)
                                <tr>
                                    <td><code>{{ $message->event_type }}</code></td>
                                    <td><span class="badge">{{ $enterpriseLabels[$message->status] ?? __('admin.system_inspector_enterprise.unknown_state') }}</span></td>
                                    <td class="foodex-number">{{ $message->attempt_count }}</td>
                                    <td class="foodex-number">{{ $message->available_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="foodex-number">{{ $message->failed_at?->format('Y-m-d H:i:s') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="foodex-empty-state">{{ __('admin.system_inspector_enterprise.no_outbox') }}</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $enterpriseData['outbox_messages']->links() }}
                </div>

                <div class="inspector-enterprise-section">
                    <h3>{{ __('admin.system_inspector_enterprise.workflow_requests') }}</h3>
                    <div class="inspector-enterprise-statuses">
                        @forelse($enterpriseData['workflow_status_counts'] as $status => $count)
                            <span class="badge">{{ $enterpriseLabels[$status] ?? __('admin.system_inspector_enterprise.unknown_state') }}: <span class="foodex-number">{{ $count }}</span></span>
                        @empty
                            <span class="foodex-subtitle">{{ __('admin.system_inspector_enterprise.no_workflows') }}</span>
                        @endforelse
                    </div>
                    <div class="inspector-table-wrap">
                        <table class="foodex-table inspector-enterprise-table">
                            <thead><tr>
                                <th>{{ __('admin.system_inspector_enterprise.workflow') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.entity_type') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.status') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.created_at') }}</th>
                                <th>{{ __('admin.system_inspector_enterprise.completed_at') }}</th>
                            </tr></thead>
                            <tbody>
                            @forelse($enterpriseData['workflow_requests'] as $workflow)
                                @php($workflowStatus=(string)$workflow->getRawOriginal('status'))
                                <tr>
                                    <td><code>{{ $workflow->workflow_key }}</code></td>
                                    <td><code>{{ $workflow->entity_type }}</code></td>
                                    <td><span class="badge">{{ $enterpriseLabels[$workflowStatus] ?? __('admin.system_inspector_enterprise.unknown_state') }}</span></td>
                                    <td class="foodex-number">{{ $workflow->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="foodex-number">{{ $workflow->completed_at?->format('Y-m-d H:i:s') ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><div class="foodex-empty-state">{{ __('admin.system_inspector_enterprise.no_workflows') }}</div></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $enterpriseData['workflow_requests']->links() }}
                </div>
            @endif
        </section>

        <section class="foodex-card inspector-enterprise" data-restaurant-operator-console>
            <div class="restaurant-operator-heading">
                <div>
                    <span class="foodex-subtitle">{{ __('restaurant_operator.eyebrow') }}</span>
                    <h2>{{ __('restaurant_operator.title') }}</h2>
                    <p>{{ __('restaurant_operator.description') }}</p>
                </div>
                @if($restaurantOperator)
                    @php($operatorHealth = $restaurantOperator['health'])
                    <span class="badge {{ ($operatorHealth['overall'] ?? '')==='HEALTHY'?'active':'' }}">
                        {{ __('restaurant_operator.health_states.'.($operatorHealth['overall'] ?? 'UNAVAILABLE')) }}
                    </span>
                @endif
            </div>

            <form class="inspector-toolbar" method="get" action="{{ route('admin.inspector.index') }}">
                <label>{{ __('restaurant_operator.select_store') }}
                    <select name="restaurant_store_id" required>
                        <option value="">{{ __('restaurant_operator.select_store_placeholder') }}</option>
                        @foreach($restaurantStores as $restaurantStore)
                            <option value="{{ $restaurantStore->id }}" @selected($restaurantStoreId===(int)$restaurantStore->id)>
                                {{ $restaurantStore->name }} · {{ $restaurantStore->code }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('restaurant_operator.load') }}</button>
            </form>

            @if(!$restaurantOperator)
                <div class="foodex-empty-state">{{ __('restaurant_operator.not_selected') }}</div>
            @else
                @php($operatorControl = $restaurantOperator['control_plane'])
                @php($operatorLocation = $operatorControl['location'])
                @php($operatorState = (string)($operatorLocation['lifecycle_state'] ?? 'DRAFT'))
                @php($operatorSupport = $restaurantOperator['support_access'])
                @php($operatorSubscription = $restaurantOperator['subscription'] ?? null)
                <div class="inspector-grid">
                    <article class="foodex-card inspector-stat">
                        <strong>{{ __('restaurant_operator.lifecycle') }}</strong>
                        <b>{{ __('restaurant_operator.states.'.$operatorState) }}</b>
                    </article>
                    <article class="foodex-card inspector-stat">
                        <strong>{{ __('restaurant_operator.health') }}</strong>
                        <b>{{ __('restaurant_operator.health_states.'.($restaurantOperator['health']['overall'] ?? 'UNAVAILABLE')) }}</b>
                    </article>
                    <article class="foodex-card inspector-stat">
                        <strong>{{ __('restaurant_operator.support_access') }}</strong>
                        <b>{{ $operatorSupport ? __('restaurant_operator.active') : __('restaurant_operator.inactive') }}</b>
                    </article>
                    <article class="foodex-card inspector-stat">
                        <strong>{{ __('restaurant_operator.support_sessions') }}</strong>
                        <b class="foodex-number">{{ $restaurantOperator['support_sessions']['active'] }}</b>
                    </article>
                </div>

                <div class="inspector-health">
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.account') }}</strong>
                        <span>{{ $operatorControl['organization']['name'] ?? '—' }}</span>
                    </div>
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.brand') }}</strong>
                        <span>{{ $operatorControl['brand']['name'] ?? '—' }}</span>
                    </div>
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.entitlements') }}</strong>
                        <span class="foodex-number">{{ $restaurantOperator['entitlements']['enabled'] }} / {{ $restaurantOperator['entitlements']['total'] }} {{ __('restaurant_operator.enabled') }}</span>
                    </div>
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.subscription') }}</strong>
                        <span>{{ $operatorSubscription ? __('restaurant_operator.states.'.(string)$operatorSubscription['state']) : __('restaurant_operator.not_subscribed') }}</span>
                    </div>
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.plan') }}</strong>
                        <span>{{ $operatorSubscription['plan_name'] ?? '—' }}</span>
                    </div>
                    <div class="inspector-health-row">
                        <strong>{{ __('restaurant_operator.data_retention') }}</strong>
                        <span class="badge active">{{ __('restaurant_operator.retained') }}</span>
                    </div>
                </div>

                @if($operatorSupport)
                    <div class="inspector-health">
                        <div class="inspector-health-row">
                            <strong>{{ __('restaurant_operator.case_reference') }}</strong>
                            <code>{{ $operatorSupport['case_reference'] }}</code>
                        </div>
                        <div class="inspector-health-row">
                            <strong>{{ __('restaurant_operator.reason') }}</strong>
                            <span>{{ __('restaurant_operator.reasons.'.$operatorSupport['reason_code']) }}</span>
                        </div>
                        <div class="inspector-health-row">
                            <strong>{{ __('restaurant_operator.ttl') }}</strong>
                            <span class="foodex-number">{{ \Illuminate\Support\Carbon::parse($operatorSupport['expires_at'])->format('Y-m-d H:i') }}</span>
                        </div>
                    </div>
                @else
                    @if($operatorControl['provisioned'])
                    <form class="restaurant-operator-form" method="post" action="{{ route('admin.inspector.restaurant-support.grant',['store'=>$restaurantStoreId]) }}">
                        @csrf
                        <label>{{ __('restaurant_operator.case_reference') }}
                            <input name="case_reference" required maxlength="120" pattern="[A-Za-z0-9._:/-]{3,120}" autocomplete="off">
                        </label>
                        <label>{{ __('restaurant_operator.reason') }}
                            <select name="reason_code" required>
                                @foreach(\App\Domain\RestaurantSaaS\RestaurantOperatorService::SUPPORT_REASON_CODES as $reasonCode)
                                    <option value="{{ $reasonCode }}">{{ __('restaurant_operator.reasons.'.$reasonCode) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('restaurant_operator.ttl') }}
                            <select name="ttl_minutes" required>
                                @foreach(\App\Domain\RestaurantSaaS\RestaurantOperatorService::SUPPORT_TTL_MINUTES as $ttl)
                                    <option value="{{ $ttl }}">{{ $ttl }} {{ __('restaurant_operator.minutes') }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div><button class="foodex-action-primary" type="submit">{{ __('restaurant_operator.grant') }}</button></div>
                    </form>
                    @endif
                @endif

                @if($operatorControl['provisioned'])
                <div class="restaurant-operator-actions">
                    @if($operatorSupport)
                    <form method="post" action="{{ route('admin.inspector.restaurant-support.revoke',['store'=>$restaurantStoreId]) }}" onsubmit="return confirm(@js(__('restaurant_operator.confirm_revoke')))">
                        @csrf @method('DELETE')
                        <button class="foodex-action-secondary" type="submit">{{ __('restaurant_operator.revoke') }}</button>
                    </form>
                    @endif
                    <form method="post" action="{{ route('admin.inspector.restaurant-support.sessions.revoke',['store'=>$restaurantStoreId]) }}" onsubmit="return confirm(@js(__('restaurant_operator.confirm_sessions')))">
                        @csrf
                        <button class="foodex-action-secondary" type="submit">{{ __('restaurant_operator.revoke_sessions') }}</button>
                    </form>
                    @if(!in_array($operatorState,['CANCELED','OFFBOARDED'],true))
                    <form method="post" action="{{ route('admin.inspector.restaurant-lifecycle.update',['store'=>$restaurantStoreId]) }}" onsubmit="return confirm(@js(__('restaurant_operator.confirm_cancel')))">
                        @csrf @method('PATCH')
                        <input type="hidden" name="target_state" value="CANCELED">
                        <button class="foodex-action-secondary" type="submit">{{ __('restaurant_operator.cancel') }}</button>
                    </form>
                    @elseif($operatorState==='CANCELED')
                    <form method="post" action="{{ route('admin.inspector.restaurant-lifecycle.update',['store'=>$restaurantStoreId]) }}" onsubmit="return confirm(@js(__('restaurant_operator.confirm_offboard')))">
                        @csrf @method('PATCH')
                        <input type="hidden" name="target_state" value="OFFBOARDED">
                        <button class="foodex-action-secondary" type="submit">{{ __('restaurant_operator.offboard') }}</button>
                    </form>
                    @endif
                </div>
                @endif
            @endif
        </section>

        <form class="foodex-card inspector-toolbar" method="get" action="{{ route('admin.inspector.index') }}">
            <label>{{ $ar?'المصدر':'Source' }}
                <select name="source"><option value="">{{ $ar?'الكل':'All' }}</option>
                    @foreach(['dashboard','server','api','route','javascript','fetch','customer_app','driver_app'] as $item)<option value="{{ $item }}" @selected($source===$item)>{{ $item }}</option>@endforeach
                </select>
            </label>
            <label>{{ $ar?'الخطورة':'Severity' }}
                <select name="severity"><option value="">{{ $ar?'الكل':'All' }}</option><option value="error" @selected($severity==='error')>error</option><option value="warning" @selected($severity==='warning')>warning</option></select>
            </label>
            <label>{{ $ar?'إصدار التطبيق':'App version' }}<input name="app_version" value="{{ $appVersion }}" placeholder="1.0.53"></label>
            <label>Build<input name="app_build" value="{{ $appBuild }}" placeholder="53"></label>
            <label>{{ $ar?'القناة':'Channel' }}
                <select name="channel"><option value="">{{ $ar?'الكل':'All' }}</option><option value="b2b" @selected($channel==='b2b')>B2B</option><option value="b2c" @selected($channel==='b2c')>B2C</option></select>
            </label>
            <label>{{ __('admin.system_inspector_surface.store') }}
                <select name="store_id"><option value="">{{ __('admin.system_inspector_surface.all_stores') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected($storeId===(int)$store->id)>{{ $store->name }} · {{ $store->code }}</option>@endforeach</select>
            </label>
            <label>{{ $ar?'بحث':'Search' }}<input name="q" value="{{ $search }}" placeholder="{{ $ar?'الرسالة أو الراوت أو Correlation ID':'Message, route or correlation ID' }}"></label>
            <button class="foodex-action-primary foodex-filter-action" type="submit">{{ $ar?'تصفية':'Filter' }}</button>
        </form>

        <section class="foodex-card inspector-table-wrap">
            <table class="foodex-table inspector-table">
                <thead><tr>
                    <th>{{ $ar?'الوقت':'Time' }}</th><th>{{ $ar?'المصدر':'Source' }}</th><th>{{ $ar?'الخطورة':'Severity' }}</th><th>HTTP</th>
                    <th>{{ $ar?'الراوت / الرابط':'Route / URL' }}</th><th>{{ $ar?'الرسالة':'Message' }}</th><th>{{ $ar?'المستخدم / المتجر':'User / Store' }}</th><th>{{ $ar?'التفاصيل':'Context' }}</th>
                </tr></thead>
                <tbody>
                @forelse($events as $event)
                    <tr>
                        <td class="foodex-number">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                        <td><span class="badge">{{ $event->source }}</span></td>
                        <td class="severity-{{ $event->severity }}">{{ $event->severity }}</td>
                        <td class="foodex-number">{{ $event->status_code ?: '—' }} @if($event->method)<small>{{ $event->method }}</small>@endif</td>
                        <td><strong>{{ $event->route_name ?: '—' }}</strong><div class="foodex-subtitle">{{ $event->url }}</div>@if($event->correlation_id)<div class="foodex-subtitle">CID: {{ $event->correlation_id }}</div>@endif</td>
                        <td class="inspector-message">{{ $event->message }}@if($event->exception_class)<div class="foodex-subtitle">{{ $event->exception_class }}</div>@endif</td>
                        <td>@if($event->user){{ $event->user->name }}<div class="foodex-subtitle">{{ $event->user->email }}</div>@else—@endif @if($event->store)<div class="foodex-subtitle">{{ $event->store->name }} · {{ $event->store->code }}</div>@endif</td>
                        <td>@if($event->context)<details><summary>{{ $ar?'عرض':'View' }}</summary><pre class="inspector-context">{{ json_encode($event->context,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details>@else—@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="foodex-empty-state">{{ $ar?'لا توجد أخطاء مسجلة بهذه الفلاتر.':'No recorded errors match these filters.' }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        {{ $events->links() }}
    </main>
</div>
</body></html>
