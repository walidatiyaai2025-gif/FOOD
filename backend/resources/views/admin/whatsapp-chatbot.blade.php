<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('chatbot.page_title') }}</title>
    @include('admin._brand-components')
    <style id="foodex-chatbot-admin-ui">
        .cb-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
        .cb-tabs{display:flex;gap:var(--foodex-space-2);overflow:auto;padding-bottom:2px}
        .cb-tabs a{white-space:nowrap;text-decoration:none;padding:10px 14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);color:var(--foodex-ink);background:var(--foodex-surface)}
        .cb-tabs a[aria-current="page"]{background:var(--foodex-green-soft);border-color:var(--foodex-green);color:var(--foodex-green-dark);font-weight:700}
        .cb-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:var(--foodex-space-4)}
        .cb-col-12{grid-column:span 12}.cb-col-8{grid-column:span 8}.cb-col-6{grid-column:span 6}.cb-col-4{grid-column:span 4}.cb-col-3{grid-column:span 3}
        .cb-card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-4);min-width:0}
        .cb-card h2,.cb-card h3{margin-top:0}.cb-muted{color:var(--foodex-muted)}
        .cb-state{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);padding:var(--foodex-space-3);border-radius:var(--foodex-radius-md);background:var(--foodex-background)}
        .cb-kpi{font-size:1.65rem;font-weight:700;margin-top:var(--foodex-space-1)}
        .cb-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-3)}
        .cb-field{display:grid;gap:6px}.cb-field.full{grid-column:1/-1}.cb-field label,.cb-field legend{font-weight:700}
        .cb-switch-row{display:flex;align-items:center;gap:var(--foodex-space-2);min-height:44px}
        .cb-feature-list,.cb-flow-list,.cb-segment-list{display:grid;gap:var(--foodex-space-3)}
        .cb-feature{display:grid;grid-template-columns:minmax(180px,2fr) repeat(4,minmax(110px,1fr)) auto;gap:var(--foodex-space-2);align-items:end;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);padding:var(--foodex-space-3)}
        .cb-badge{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:var(--foodex-text-xs);font-weight:700}
        .cb-badge.off{background:var(--foodex-background);color:var(--foodex-muted)}
        .cb-actions{display:flex;gap:var(--foodex-space-2);flex-wrap:wrap;align-items:center}
        .cb-actions form{margin:0}.cb-definition{display:grid;gap:6px;margin-top:var(--foodex-space-2)}
        .cb-flow-palette{display:grid;gap:8px;margin-top:6px}.cb-flow-node{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-background);cursor:grab}.cb-flow-node[draggable="true"]:active{cursor:grabbing}.cb-flow-handle{font-weight:800;color:var(--foodex-muted);letter-spacing:1px}.cb-flow-node.dragging{opacity:.5}
        .cb-analytics-table{width:100%;border-collapse:collapse}.cb-analytics-table th,.cb-analytics-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start}
        .cb-preview-links{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-2)}
        .cb-preview-links a{text-decoration:none;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:12px;color:var(--foodex-ink);background:var(--foodex-surface)}
        @media(max-width:1023px){.cb-col-8,.cb-col-6,.cb-col-4,.cb-col-3{grid-column:span 12}.cb-feature{grid-template-columns:repeat(2,minmax(0,1fr))}.cb-feature>div:first-child{grid-column:1/-1}.cb-preview-links{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:767px){.cb-grid,.cb-form-grid{grid-template-columns:1fr}.cb-col-12,.cb-col-8,.cb-col-6,.cb-col-4,.cb-col-3{grid-column:1}.cb-feature{grid-template-columns:1fr}.cb-feature>*{grid-column:1!important}.cb-preview-links{grid-template-columns:1fr}.cb-card{padding:var(--foodex-space-3)}}
    </style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="whatsapp-chatbot-{{ $section }}">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
        <div class="cb-shell">
            <header class="foodex-page-toolbar">
                <div>
                    <span class="foodex-subtitle">{{ __('chatbot.subtitle') }}</span>
                    <h1>{{ __('chatbot.page_title') }}</h1>
                    <p>{{ __('chatbot.description') }}</p>
                </div>
                <div class="foodex-header-actions">
                    <a class="foodex-action-primary" href="{{ route('admin.whatsapp-chatbot.preview', ['screen' => 'home']) }}">{{ __('chatbot.preview_title') }}</a>
                    
                </div>
            </header>

            @if(session('status'))<div class="foodex-alert success" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="foodex-alert error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

            <nav class="cb-tabs" aria-label="{{ __('chatbot.page_title') }}">
                @foreach(['control','features','flows','ai','segments','analytics'] as $tab)
                    @php
                        $permission = match($tab) {
                            'features' => 'chatbot.features.view',
                            'flows' => 'chatbot.flows.view',
                            'ai' => 'chatbot.ai.view',
                            'segments' => 'chatbot.segments.view',
                            'analytics' => 'chatbot.analytics.view',
                            default => 'chatbot.view',
                        };
                    @endphp
                    @if(auth()->user()->hasPermission($permission))
                        <a href="{{ route('admin.whatsapp-chatbot.index', ['section' => $tab]) }}" @if($section === $tab) aria-current="page" @endif>{{ __('chatbot.tabs.'.$tab) }}</a>
                    @endif
                @endforeach
            </nav>

            @if($section === 'control')
                <section class="cb-grid">
                    <div class="cb-card cb-col-8">
                        <h2>{{ __('chatbot.admin.bot_status') }}</h2>
                        <div class="cb-state">
                            <div>
                                <span class="cb-badge {{ $settings['enabled'] && !$settings['emergency_stop'] ? '' : 'off' }}">
                                    {{ $settings['enabled'] && !$settings['emergency_stop'] ? __('chatbot.admin.enabled') : __('chatbot.admin.disabled') }}
                                </span>
                                <p class="cb-muted">{{ __('chatbot.admin.runtime_guard') }}</p>
                            </div>
                            <div>
                                <strong>{{ __('chatbot.admin.lifecycle_'.strtolower($settings['lifecycle'] ?? 'DISABLED')) }}</strong>
                                <div class="cb-muted">{{ __('chatbot.admin.mode_'.($settings['mode'] ?? 'hybrid')) }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="cb-card cb-col-4">
                        <h2>{{ __('chatbot.admin.emergency_stop') }}</h2>
                        <p class="cb-muted">{{ __('chatbot.admin.emergency_hint') }}</p>
                        <span class="cb-badge {{ $settings['emergency_stop'] ? 'off' : '' }}">{{ $settings['emergency_stop'] ? __('chatbot.admin.enabled') : __('chatbot.admin.disabled') }}</span>
                    </div>
                    <div class="cb-card cb-col-12">
                        <form class="foodex-form cb-form-grid" method="post" action="{{ route('admin.whatsapp-chatbot.runtime.update') }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="scope" value="runtime">
                            <input type="hidden" name="revision" value="{{ $settings['revision'] }}">
                            <input type="hidden" name="enabled" value="0">
                            <div class="cb-field"><label class="cb-switch-row"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled']) @disabled(!$canManageRuntime)> {{ __('chatbot.admin.enabled') }}</label></div>
                            <div class="cb-field">
                                <label for="cb-lifecycle">{{ __('chatbot.admin.lifecycle') }}</label>
                                <select class="foodex-control" id="cb-lifecycle" name="lifecycle" @disabled(!$canManageRuntime)>
                                    @foreach(['DISABLED','INTERNAL_TEST','PILOT','ENABLED'] as $lifecycle)
                                        <option value="{{ $lifecycle }}" @selected(($settings['lifecycle'] ?? 'DISABLED') === $lifecycle)>{{ __('chatbot.admin.lifecycle_'.strtolower($lifecycle)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="cb-field full">
                                <label for="cb-pilot-segments">{{ __('chatbot.admin.pilot_segments') }}</label>
                                <select class="foodex-control" id="cb-pilot-segments" name="pilot_segment_codes[]" multiple @disabled(!$canManageRuntime)>
                                    @foreach($segments as $segment)
                                        <option value="{{ $segment->code }}" @selected(in_array($segment->code, $settings['pilot_segment_codes'] ?? [], true))>{{ app()->getLocale()==='ar' ? $segment->name_ar : $segment->name_en }}</option>
                                    @endforeach
                                </select>
                                <small class="cb-muted">{{ __('chatbot.admin.rollout_guard') }}</small>
                            </div>
                            <input type="hidden" name="emergency_stop" value="0">
                            <div class="cb-field"><label class="cb-switch-row"><input type="checkbox" name="emergency_stop" value="1" @checked($settings['emergency_stop']) @disabled(!$canManageRuntime)> {{ __('chatbot.admin.emergency_stop') }}</label></div>
                            <div class="cb-field">
                                <label for="cb-mode">{{ __('chatbot.admin.mode') }}</label>
                                <select class="foodex-control" id="cb-mode" name="mode" @disabled(!$canManageRuntime)>
                                    @foreach(['menu','ai','hybrid'] as $mode)<option value="{{ $mode }}" @selected($settings['mode']===$mode)>{{ __('chatbot.admin.mode_'.$mode) }}</option>@endforeach
                                </select>
                            </div>
                            <div class="cb-field">
                                <label for="cb-locale">{{ __('chatbot.admin.default_locale') }}</label>
                                <select class="foodex-control" id="cb-locale" name="default_locale" @disabled(!$canManageRuntime)>
                                    <option value="ar" @selected($settings['default_locale']==='ar')>{{ __('chatbot.admin.arabic') }}</option>
                                    <option value="en" @selected($settings['default_locale']==='en')>{{ __('chatbot.admin.english') }}</option>
                                </select>
                            </div>
                            <div class="cb-field"><label for="cb-from">{{ __('chatbot.admin.from') }}</label><input class="foodex-control" id="cb-from" type="time" name="working_from" value="{{ $settings['working_from'] }}" @disabled(!$canManageRuntime)></div>
                            <div class="cb-field"><label for="cb-to">{{ __('chatbot.admin.to') }}</label><input class="foodex-control" id="cb-to" type="time" name="working_to" value="{{ $settings['working_to'] }}" @disabled(!$canManageRuntime)></div>
                            <input type="hidden" name="after_hours_handoff" value="0">
                            <div class="cb-field full"><label class="cb-switch-row"><input type="checkbox" name="after_hours_handoff" value="1" @checked($settings['after_hours_handoff']) @disabled(!$canManageRuntime)> {{ __('chatbot.admin.after_hours_handoff') }}</label></div>
                            <div class="cb-field full"><label for="cb-welcome-ar">{{ __('chatbot.admin.welcome_ar') }}</label><textarea class="foodex-control" id="cb-welcome-ar" name="welcome_ar" rows="2" @disabled(!$canManageRuntime)>{{ $settings['welcome_ar'] }}</textarea></div>
                            <div class="cb-field full"><label for="cb-welcome-en">{{ __('chatbot.admin.welcome_en') }}</label><textarea class="foodex-control" id="cb-welcome-en" name="welcome_en" rows="2" dir="ltr" @disabled(!$canManageRuntime)>{{ $settings['welcome_en'] }}</textarea></div>
                            <div class="cb-field full"><label for="cb-fallback-ar">{{ __('chatbot.admin.fallback_ar') }}</label><textarea class="foodex-control" id="cb-fallback-ar" name="fallback_ar" rows="2" @disabled(!$canManageRuntime)>{{ $settings['fallback_ar'] }}</textarea></div>
                            <div class="cb-field full"><label for="cb-fallback-en">{{ __('chatbot.admin.fallback_en') }}</label><textarea class="foodex-control" id="cb-fallback-en" name="fallback_en" rows="2" dir="ltr" @disabled(!$canManageRuntime)>{{ $settings['fallback_en'] }}</textarea></div>
                            @if($canManageRuntime)<div class="cb-field full"><button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.save') }}</button></div>@endif
                        </form>
                    </div>
                    <div class="cb-card cb-col-12">
                        <h2>{{ __('chatbot.preview_title') }}</h2>
                        <p class="cb-muted">{{ __('chatbot.admin.customer_preview_hint') }}</p>
                        <div class="cb-preview-links">
                            @foreach(['home','new-order','reorder','products','cart','orders','finance','support'] as $screen)
                                @if($screen !== 'finance' || auth()->user()->hasPermission('finance.view'))
                                    <a href="{{ route('admin.whatsapp-chatbot.preview', ['screen' => $screen]) }}">{{ __('chatbot.preview_screens.'.$screen) }}</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </section>
            @elseif($section === 'features')
                <section class="cb-card">
                    <h2>{{ __('chatbot.tabs.features') }}</h2>
                    <div class="cb-feature-list">
                        @forelse($features as $feature)
                            @php($featureSegments = is_string($feature->segment_codes) ? (json_decode($feature->segment_codes, true) ?: []) : [])
                            <form class="cb-feature" method="post" action="{{ route('admin.whatsapp-chatbot.features.update', ['feature' => $feature->id]) }}">
                                @csrf @method('PATCH')
                                <div><strong>{{ app()->getLocale()==='ar' ? $feature->name_ar : $feature->name_en }}</strong><div class="cb-muted">{{ $feature->code }}</div></div>
                                <div><label>{{ __('chatbot.admin.status') }}</label><input type="hidden" name="enabled" value="0"><label class="cb-switch-row"><input type="checkbox" name="enabled" value="1" @checked($feature->enabled) @disabled(!$canManageFeatures)> {{ __('chatbot.admin.enabled') }}</label></div>
                                <div><label>{{ __('chatbot.admin.visibility') }}</label><input type="hidden" name="visible" value="0"><label class="cb-switch-row"><input type="checkbox" name="visible" value="1" @checked($feature->visible) @disabled(!$canManageFeatures)> {{ __('chatbot.admin.visible') }}</label></div>
                                <div><label>{{ __('chatbot.admin.ai_allowed') }}</label><input type="hidden" name="ai_allowed" value="0"><label class="cb-switch-row"><input type="checkbox" name="ai_allowed" value="1" @checked($feature->ai_allowed) @disabled(!$canManageFeatures)> {{ __('chatbot.admin.ai_allowed') }}</label></div>
                                <div><label>{{ __('chatbot.admin.sort_order') }}</label><input class="foodex-control" type="number" min="1" max="9999" name="sort_order" value="{{ $feature->sort_order }}" @disabled(!$canManageFeatures)></div>
                                <div>
                                    <label>{{ __('chatbot.admin.segments') }}</label>
                                    <select class="foodex-control" name="segment_codes[]" multiple @disabled(!$canManageFeatures)>
                                        @foreach($segments as $segment)<option value="{{ $segment->code }}" @selected(in_array($segment->code, $featureSegments, true))>{{ app()->getLocale()==='ar' ? $segment->name_ar : $segment->name_en }}</option>@endforeach
                                    </select>
                                </div>
                                @if($canManageFeatures)<button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.update') }}</button>@endif
                            </form>
                        @empty
                            <div class="foodex-empty-state">{{ __('chatbot.admin.no_data') }}</div>
                        @endforelse
                    </div>
                </section>
            @elseif($section === 'flows')
                <section class="cb-grid">
                    <div class="cb-card cb-col-4">
                        <h2>{{ __('chatbot.admin.flow_builder') }}</h2>
                        @if($canManageFlows)
                        <form class="foodex-form" method="post" action="{{ route('admin.whatsapp-chatbot.flows.store') }}">
                            @csrf
                            <div class="cb-field"><label>{{ __('chatbot.admin.flow_code') }}</label><input class="foodex-control" name="code" required maxlength="64"></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.name_ar') }}</label><input class="foodex-control" name="name_ar" required maxlength="191"></div> {{-- localization-gate: allow — bilingual admin create form field key is technical; visible label is translated --}}
                            <div class="cb-field"><label>{{ __('chatbot.admin.name_en') }}</label><input class="foodex-control" name="name_en" required maxlength="191" dir="ltr"></div> {{-- localization-gate: allow — bilingual admin create form field key is technical; visible label is translated --}}
                            <div class="cb-field"><label>{{ __('chatbot.admin.message_ar') }}</label><textarea class="foodex-control" name="message_ar" required rows="3"></textarea></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.message_en') }}</label><textarea class="foodex-control" name="message_en" required rows="3" dir="ltr"></textarea></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.primary_action') }}</label>
                                <select class="foodex-control" name="primary_action">
                                    @foreach(['new_order','repeat_order','product_search','order_tracking','complaints','human_handoff'] as $action)<option value="{{ $action }}">{{ __('chatbot.customer.'.$action) }}</option>@endforeach
                                </select>
                            </div>
                            <div class="cb-field">
                                <label>{{ __('chatbot.admin.menu_actions') }}</label>
                                <small class="cb-muted">{{ __('chatbot.admin.drag_hint') }}</small>
                                <div class="cb-flow-palette" data-flow-sort>
                                    @foreach(['new_order','repeat_order','product_search','order_tracking','complaints','human_handoff'] as $index => $action)
                                        <label class="cb-flow-node" draggable="true">
                                            <span class="cb-flow-handle" aria-hidden="true">⋮⋮</span>
                                            <input type="checkbox" name="menu_actions[]" value="{{ $action }}" @checked($index < 3)>
                                            <span>{{ __('chatbot.customer.'.$action) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.create_flow') }}</button>
                        </form>
                        @endif
                    </div>
                    <div class="cb-card cb-col-8">
                        <h2>{{ __('chatbot.tabs.flows') }}</h2>
                        <div class="cb-flow-list">
                            @forelse($flows as $flow)
                                @php($definition = is_string($flow->definition) ? (json_decode($flow->definition, true) ?: []) : [])
                                @php($welcomeNode = collect($definition['nodes'] ?? [])->firstWhere('id','welcome'))
                                @php($actionNodes = collect($definition['nodes'] ?? [])->filter(fn($node) => is_array($node) && data_get($node,'type') === 'action')->values())
                                @php($actionNode = $actionNodes->first())
                                @php($flowMenuActions = $actionNodes->pluck('action')->filter()->values()->all())
                                <article class="cb-card">
                                    <div class="cb-state">
                                        <div><strong>{{ app()->getLocale()==='ar' ? $flow->name_ar : $flow->name_en }}</strong><div class="cb-muted">{{ $flow->code }} · {{ __('chatbot.admin.version') }} {{ $flow->version }}</div></div>
                                        <span class="cb-badge {{ $flow->status==='published' ? '' : 'off' }}">{{ __('chatbot.admin.'.$flow->status) }}</span>
                                    </div>
                                    @if($canManageFlows)
                                    <form class="foodex-form cb-definition" method="post" action="{{ route('admin.whatsapp-chatbot.flows.update', ['flow' => $flow->id]) }}">
                                        @csrf @method('PATCH')
                                        <div class="cb-form-grid">
                                            <div class="cb-field"><label>{{ __('chatbot.admin.name_ar') }}</label><input class="foodex-control" name="name_ar" value="{{ $flow->name_ar }}" required></div> {{-- localization-gate: allow — bilingual admin editor intentionally exposes both localized source fields --}}
                                            <div class="cb-field"><label>{{ __('chatbot.admin.name_en') }}</label><input class="foodex-control" name="name_en" value="{{ $flow->name_en }}" required dir="ltr"></div> {{-- localization-gate: allow — bilingual admin editor intentionally exposes both localized source fields --}}
                                            <div class="cb-field full"><label>{{ __('chatbot.admin.message_ar') }}</label><textarea class="foodex-control" name="message_ar" rows="2" required>{{ data_get($welcomeNode,'content.ar','') }}</textarea></div>
                                            <div class="cb-field full"><label>{{ __('chatbot.admin.message_en') }}</label><textarea class="foodex-control" name="message_en" rows="2" required dir="ltr">{{ data_get($welcomeNode,'content.en','') }}</textarea></div>
                                            <div class="cb-field full"><label>{{ __('chatbot.admin.primary_action') }}</label><select class="foodex-control" name="primary_action">@foreach(['new_order','repeat_order','product_search','order_tracking','complaints','human_handoff'] as $action)<option value="{{ $action }}" @selected(data_get($actionNode,'action')===$action)>{{ __('chatbot.customer.'.$action) }}</option>@endforeach</select></div>
                                            <div class="cb-field full">
                                                <label>{{ __('chatbot.admin.menu_actions') }}</label>
                                                <small class="cb-muted">{{ __('chatbot.admin.drag_hint') }}</small>
                                                <div class="cb-flow-palette" data-flow-sort>
                                                    @foreach(['new_order','repeat_order','product_search','order_tracking','complaints','human_handoff'] as $action)
                                                        <label class="cb-flow-node" draggable="true">
                                                            <span class="cb-flow-handle" aria-hidden="true">⋮⋮</span>
                                                            <input type="checkbox" name="menu_actions[]" value="{{ $action }}" @checked(in_array($action,$flowMenuActions,true))>
                                                            <span>{{ __('chatbot.customer.'.$action) }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                        <button class="foodex-action-secondary" type="submit">{{ __('chatbot.admin.edit_flow') }}</button>
                                    </form>
                                    @endif
                                    <div class="cb-actions">
                                        @if($canPublishFlows && $flow->status !== 'published')<form method="post" action="{{ route('admin.whatsapp-chatbot.flows.publish', ['flow'=>$flow->id]) }}">@csrf<button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.publish') }}</button></form>@endif {{-- localization-gate: allow — workflow status comparison is control logic; visible action text is translated --}}
                                        @if($canPublishFlows && (int)$flow->version > 1)<form method="post" action="{{ route('admin.whatsapp-chatbot.flows.rollback', ['flow'=>$flow->id]) }}">@csrf<button class="foodex-action-secondary" type="submit">{{ __('chatbot.admin.rollback') }}</button></form>@endif
                                    </div>
                                </article>
                            @empty
                                <div class="foodex-empty-state">{{ __('chatbot.admin.no_flows') }}</div>
                            @endforelse
                        </div>
                    </div>
                </section>
            @elseif($section === 'ai')
                <section class="cb-grid">
                    <div class="cb-card cb-col-4"><h2>{{ __('chatbot.tabs.ai') }}</h2><p class="cb-muted">{{ __('chatbot.admin.ai_guardrail') }}</p></div>
                    <div class="cb-card cb-col-8">
                        <form class="foodex-form cb-form-grid" method="post" action="{{ route('admin.whatsapp-chatbot.runtime.update') }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="scope" value="ai"><input type="hidden" name="revision" value="{{ $settings['revision'] }}">
                            <input type="hidden" name="ai_enabled" value="0">
                            <div class="cb-field full"><label class="cb-switch-row"><input type="checkbox" name="ai_enabled" value="1" @checked($settings['ai_enabled']) @disabled(!$canManageAi)> {{ __('chatbot.admin.ai_enabled') }}</label></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.confidence_threshold') }}</label><input class="foodex-control" type="number" name="confidence_threshold" min="0" max="1" step="0.05" value="{{ $settings['confidence_threshold'] }}" @disabled(!$canManageAi)></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.max_autonomous_steps') }}</label><input class="foodex-control" type="number" name="max_autonomous_steps" min="1" max="10" value="{{ $settings['max_autonomous_steps'] }}" @disabled(!$canManageAi)></div>
                            <input type="hidden" name="require_order_confirmation" value="0">
                            <div class="cb-field full"><label class="cb-switch-row"><input type="checkbox" name="require_order_confirmation" value="1" @checked($settings['require_order_confirmation']) @disabled(!$canManageAi)> {{ __('chatbot.admin.require_order_confirmation') }}</label></div>
                            <input type="hidden" name="human_handoff_enabled" value="0">
                            <div class="cb-field full"><label class="cb-switch-row"><input type="checkbox" name="human_handoff_enabled" value="1" @checked($settings['human_handoff_enabled']) @disabled(!$canManageAi)> {{ __('chatbot.admin.human_handoff_enabled') }}</label></div>
                            @if($canManageAi)<div class="cb-field full"><button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.save') }}</button></div>@endif
                        </form>
                    </div>
                    <div class="cb-card cb-col-12">
                        <h2>{{ __('chatbot.admin.ai_test_console') }}</h2>
                        <p class="cb-muted">{{ __('chatbot.admin.ai_test_hint') }}</p>
                        <form class="foodex-form cb-form-grid" method="post" action="{{ route('admin.whatsapp-chatbot.ai.test') }}">
                            @csrf
                            <div class="cb-field full"><label for="cb-ai-test">{{ __('chatbot.admin.test_message') }}</label><textarea class="foodex-control" id="cb-ai-test" name="message" rows="3" required maxlength="1000">{{ old('message', session('chatbot_ai_test.message')) }}</textarea></div>
                            <div class="cb-field full"><button class="foodex-action-secondary" type="submit">{{ __('chatbot.admin.run_test') }}</button></div>
                        </form>
                        @if(session('chatbot_ai_test.action'))
                            @php($testAction = session('chatbot_ai_test.action'))
                            <div class="cb-state" style="margin-top:var(--foodex-space-3)">
                                <div><strong>{{ __('chatbot.admin.detected_intent') }}</strong><div>{{ $testAction['intent'] ?? '—' }}</div></div>
                                <div><strong>{{ __('chatbot.admin.requested_action') }}</strong><div>{{ $testAction['requested_action'] ?? '—' }}</div></div>
                                <div><strong>{{ __('chatbot.admin.confidence') }}</strong><div>{{ number_format((float)($testAction['confidence'] ?? 0) * 100, 1) }}%</div></div>
                                <div><strong>{{ __('chatbot.admin.confirmation') }}</strong><div>{{ !empty($testAction['requires_confirmation']) ? __('chatbot.admin.required') : __('chatbot.admin.not_required') }}</div></div>
                            </div>
                        @endif
                    </div>
                </section>
            @elseif($section === 'segments')
                <section class="cb-grid">
                    <div class="cb-card cb-col-4">
                        <h2>{{ __('chatbot.admin.segment_builder') }}</h2>
                        @if($canManageSegments)
                        <form class="foodex-form" method="post" action="{{ route('admin.whatsapp-chatbot.segments.store') }}">
                            @csrf
                            <div class="cb-field"><label>{{ __('chatbot.admin.segment_code') }}</label><input class="foodex-control" name="code" required></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.name_ar') }}</label><input class="foodex-control" name="name_ar" required></div> {{-- localization-gate: allow — bilingual admin create form field key is technical; visible label is translated --}}
                            <div class="cb-field"><label>{{ __('chatbot.admin.name_en') }}</label><input class="foodex-control" name="name_en" required dir="ltr"></div> {{-- localization-gate: allow — bilingual admin create form field key is technical; visible label is translated --}}
                            <input type="hidden" name="enabled" value="0"><label class="cb-switch-row"><input type="checkbox" name="enabled" value="1" checked> {{ __('chatbot.admin.enabled') }}</label>
                            <div class="cb-field"><label>{{ __('chatbot.admin.priority') }}</label><input class="foodex-control" type="number" name="priority" value="100" min="1" max="9999"></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.customer_type') }}</label><select class="foodex-control" name="customer_type">@foreach(['any','b2b','b2c'] as $type)<option value="{{ $type }}">{{ __('chatbot.admin.'.$type) }}</option>@endforeach</select></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.min_orders') }}</label><input class="foodex-control" type="number" name="min_orders" value="0" min="0"></div>
                            <div class="cb-field"><label>{{ __('chatbot.admin.dormant_days') }}</label><input class="foodex-control" type="number" name="dormant_days" value="0" min="0"></div>
                            <button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.create_segment') }}</button>
                        </form>
                        @endif
                    </div>
                    <div class="cb-card cb-col-8">
                        <h2>{{ __('chatbot.tabs.segments') }}</h2>
                        <div class="cb-segment-list">
                            @forelse($segments as $segment)
                                @php($rules = is_string($segment->rules) ? (json_decode($segment->rules,true) ?: []) : [])
                                <form class="cb-card foodex-form" method="post" action="{{ route('admin.whatsapp-chatbot.segments.update',['segment'=>$segment->id]) }}">
                                    @csrf @method('PATCH')
                                    <div class="cb-form-grid">
                                        <div class="cb-field"><label>{{ __('chatbot.admin.name_ar') }}</label><input class="foodex-control" name="name_ar" value="{{ $segment->name_ar }}" required @disabled(!$canManageSegments)></div> {{-- localization-gate: allow — bilingual admin editor intentionally exposes both localized source fields --}}
                                        <div class="cb-field"><label>{{ __('chatbot.admin.name_en') }}</label><input class="foodex-control" name="name_en" value="{{ $segment->name_en }}" required dir="ltr" @disabled(!$canManageSegments)></div> {{-- localization-gate: allow — bilingual admin editor intentionally exposes both localized source fields --}}
                                        <input type="hidden" name="enabled" value="0"><div class="cb-field"><label class="cb-switch-row"><input type="checkbox" name="enabled" value="1" @checked($segment->enabled) @disabled(!$canManageSegments)> {{ __('chatbot.admin.enabled') }}</label></div>
                                        <div class="cb-field"><label>{{ __('chatbot.admin.priority') }}</label><input class="foodex-control" type="number" name="priority" value="{{ $segment->priority }}" min="1" max="9999" @disabled(!$canManageSegments)></div>
                                        <div class="cb-field"><label>{{ __('chatbot.admin.customer_type') }}</label><select class="foodex-control" name="customer_type" @disabled(!$canManageSegments)>@foreach(['any','b2b','b2c'] as $type)<option value="{{ $type }}" @selected(($rules['customer_type']??'any')===$type)>{{ __('chatbot.admin.'.$type) }}</option>@endforeach</select></div>
                                        <div class="cb-field"><label>{{ __('chatbot.admin.min_orders') }}</label><input class="foodex-control" type="number" name="min_orders" value="{{ $rules['min_orders']??0 }}" min="0" @disabled(!$canManageSegments)></div>
                                        <div class="cb-field"><label>{{ __('chatbot.admin.dormant_days') }}</label><input class="foodex-control" type="number" name="dormant_days" value="{{ $rules['dormant_days']??0 }}" min="0" @disabled(!$canManageSegments)></div>
                                    </div>
                                    @if($canManageSegments)<button class="foodex-action-secondary" type="submit">{{ __('chatbot.admin.update') }}</button>@endif
                                </form>
                            @empty<div class="foodex-empty-state">{{ __('chatbot.admin.no_segments') }}</div>@endforelse
                        </div>
                    </div>
                </section>
            @elseif($section === 'analytics')
                <section class="cb-grid">
                    <div class="cb-card cb-col-12">
                        <form method="get" class="cb-actions">
                            <input type="hidden" name="section" value="analytics">
                            <label>{{ __('chatbot.admin.analytics_period') }} <select class="foodex-control" name="days">@foreach([7,30,90,365] as $option)<option value="{{ $option }}" @selected($days===$option)>{{ $option }} {{ __('chatbot.admin.days') }}</option>@endforeach</select></label>
                            <button class="foodex-action-primary" type="submit">{{ __('chatbot.admin.update') }}</button>
                        </form>
                    </div>
                    @foreach(['conversations','customers','bot_replies','handoffs','fallbacks','draft_actions','support_cases','campaign_entries'] as $metric)
                        <div class="cb-card cb-col-3"><div class="cb-muted">{{ __('chatbot.admin.'.$metric) }}</div><div class="cb-kpi">{{ $analytics[$metric] ?? 0 }}</div></div>
                    @endforeach
                    <div class="cb-card cb-col-3"><div class="cb-muted">{{ __('chatbot.admin.draft_value') }}</div><div class="cb-kpi">{{ number_format((float)($analytics['draft_value'] ?? 0), 3) }} KWD</div></div>
                    <div class="cb-card cb-col-6">
                        <h2>{{ __('chatbot.admin.feature_usage') }}</h2>
                        @if(($analytics['features'] ?? collect())->isEmpty())<div class="foodex-empty-state">{{ __('chatbot.admin.no_data') }}</div>@else
                        <table class="cb-analytics-table" data-pagination-exempt="bounded-analytics-summary"><thead><tr><th>{{ __('chatbot.admin.feature') }}</th><th>{{ __('chatbot.admin.events') }}</th></tr></thead><tbody>@foreach($analytics['features'] as $row)<tr><td>{{ $row->feature_code }}</td><td>{{ $row->uses }}</td></tr>@endforeach</tbody></table>@endif
                    </div>
                    <div class="cb-card cb-col-6">
                        <h2>{{ __('chatbot.admin.daily_events') }}</h2>
                        @if(($analytics['daily'] ?? collect())->isEmpty())<div class="foodex-empty-state">{{ __('chatbot.admin.no_data') }}</div>@else
                        <table class="cb-analytics-table" data-pagination-exempt="bounded-analytics-summary"><thead><tr><th>{{ __('chatbot.admin.analytics_period') }}</th><th>{{ __('chatbot.admin.events') }}</th></tr></thead><tbody>@foreach($analytics['daily'] as $row)<tr><td>{{ $row->day }}</td><td>{{ $row->events }}</td></tr>@endforeach</tbody></table>@endif
                    </div>
                </section>
            @endif
        </div>
    </main>
</div>
<script>
(() => {
    document.querySelectorAll('[data-flow-sort]').forEach((list) => {
        let dragging = null;
        list.querySelectorAll('.cb-flow-node').forEach((node) => {
            node.addEventListener('dragstart', () => {
                dragging = node;
                node.classList.add('dragging');
            });
            node.addEventListener('dragend', () => {
                node.classList.remove('dragging');
                dragging = null;
            });
        });
        list.addEventListener('dragover', (event) => {
            event.preventDefault();
            if (!dragging) return;
            const siblings = [...list.querySelectorAll('.cb-flow-node:not(.dragging)')];
            const next = siblings.find((node) => event.clientY <= node.getBoundingClientRect().top + node.offsetHeight / 2);
            list.insertBefore(dragging, next || null);
        });
    });
})();
</script>
</body>
</html>
