<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('whatsapp_supervisor.distribution.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        .p202-shell{display:grid;gap:16px;min-width:0}
        .p202-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .p202-toolbar{display:flex;align-items:end;flex-wrap:wrap;gap:10px}
        .p202-toolbar label,.p202-form label{display:grid;gap:5px;font-weight:700}
        .p202-form{display:grid;gap:12px}
        .p202-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .p202-fields>*{min-width:0}
        .p202-fields select,.p202-fields input,.p202-toolbar select{width:100%}
        .p202-rule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:12px}
        .p202-card{padding:16px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);min-width:0}
        .p202-card-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}
        .p202-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin:12px 0}
        .p202-meta div{min-width:0;overflow-wrap:anywhere}
        .p202-meta small{display:block;color:var(--foodex-muted)}
        .p202-card details{margin-top:12px}
        .p202-card summary{cursor:pointer;font-weight:800;color:var(--foodex-green-dark)}
        .p202-msg{padding:12px;border-radius:10px;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .p202-error{padding:12px;border-radius:10px;background:var(--foodex-orange-soft);color:var(--foodex-danger-text)}
        .p202-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .p202-muted{color:var(--foodex-muted)}
        .p202-card select[multiple]{min-height:130px}
        @media(max-width:767px){.p202-fields,.p202-meta{grid-template-columns:1fr}.p202-rule-grid{grid-template-columns:1fr}.p202-toolbar{align-items:stretch;flex-direction:column}.p202-toolbar>*{width:100%}}
    </style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])</aside>
    <main class="foodex-admin-main foodex-admin-page">
        @include('admin._global-header')
        <div class="p202-shell" data-v2-distribution-rules>
            <div class="foodex-page-toolbar p202-head">
                <div>
                    <span class="foodex-subtitle">{{ __('whatsapp_supervisor.subtitle') }}</span>
                    <h1>{{ __('whatsapp_supervisor.distribution.title') }}</h1>
                    <p class="p202-muted">{{ __('whatsapp_supervisor.distribution.help') }}</p>
                </div>
                <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-supervisor.index') }}">{{ __('whatsapp_supervisor.distribution.back') }}</a>
            </div>
            @if(session('status'))<p role="status" class="p202-msg">{{ session('status') }}</p>@endif
            @if($errors->any())
                <div role="alert" class="p202-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <section class="p202-card">
                <form class="p202-toolbar" method="get" action="{{ route('admin.whatsapp-supervisor.rules.index') }}">
                    <label>{{ __('whatsapp_supervisor.distribution.store') }}
                        <select name="store_id">
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" @selected((int)$store->id === $storeId)>{{ $store->name }} · {{ $store->code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="foodex-action-primary" type="submit">{{ __('whatsapp_supervisor.apply') }}</button>
                </form>
            </section>
            <section class="p202-card">
                <details data-foodex-operational-modal>
                    <summary>{{ __('whatsapp_supervisor.distribution.create') }}</summary>
                    <form class="p202-form" method="post" action="{{ route('admin.whatsapp-supervisor.rules.store') }}">
                        @csrf
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        <div class="p202-fields">
                            <label>{{ __('whatsapp_supervisor.distribution.name') }}<input required maxlength="120" name="name" value="{{ old('name') }}"></label>
                            <label>{{ __('whatsapp_supervisor.distribution.priority') }}<input required type="number" name="priority" min="1" max="10000" value="{{ old('priority',100) }}"></label>
                            <label>{{ __('whatsapp_supervisor.distribution.strategy') }}
                                <select name="strategy">
                                    @foreach(['round_robin','least_loaded'] as $strategy)
                                        <option value="{{ $strategy }}" @selected(old('strategy','round_robin') === $strategy)>{{ __('whatsapp_supervisor.distribution.strategies.'.$strategy) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>{{ __('whatsapp_supervisor.distribution.agents') }}
                                <select name="agent_ids[]" multiple size="5" aria-describedby="p202-agent-help">
                                    @foreach($agents as $agent)<option value="{{ $agent->id }}" @selected(in_array($agent->id, old('agent_ids',[])))>{{ $agent->name }}</option>@endforeach
                                </select>
                            </label>
                        </div>
                        <p class="p202-muted" id="p202-agent-help">{{ __('whatsapp_supervisor.distribution.any_agent') }}</p>
                        <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active','1')==='1')> {{ __('whatsapp_supervisor.distribution.enabled') }}</label>
                        <div><button class="foodex-action-primary" type="submit">{{ __('whatsapp_supervisor.distribution.save') }}</button></div>
                    </form>
                </details>
            </section>
            <section aria-label="{{ __('whatsapp_supervisor.distribution.rules') }}">
                <h2>{{ __('whatsapp_supervisor.distribution.rules') }}</h2>
                <div class="p202-rule-grid">
                    @forelse($rules as $rule)
                        <article class="p202-card" data-distribution-rule="{{ $rule->id }}">
                            <div class="p202-card-head">
                                <h3>{{ $rule->name }}</h3>
                                <span>{{ $rule->is_active ? __('whatsapp_supervisor.distribution.enabled') : __('whatsapp_supervisor.distribution.disabled') }}</span>
                            </div>
                            <div class="p202-meta">
                                <div><small>{{ __('whatsapp_supervisor.distribution.priority') }}</small><strong>{{ $rule->priority }}</strong></div>
                                <div><small>{{ __('whatsapp_supervisor.distribution.strategy') }}</small><strong>{{ __('whatsapp_supervisor.distribution.strategies.'.$rule->strategy) }}</strong></div>
                                <div><small>{{ __('whatsapp_supervisor.distribution.agents') }}</small><strong>{{ implode(' · ', $rule->agent_names) ?: __('whatsapp_supervisor.distribution.any_agent') }}</strong></div>
                            </div>
                            @if($rule->editable)
                                <details data-foodex-operational-modal>
                                    <summary>{{ __('whatsapp_supervisor.distribution.edit') }}</summary>
                                    <form class="p202-form" method="post" action="{{ route('admin.whatsapp-supervisor.rules.update', ['rule' => $rule->id]) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                                        <input type="hidden" name="revision" value="{{ $rule->revision }}">
                                        <input type="hidden" name="is_active" value="{{ $rule->is_active ? 1 : 0 }}">
                                        <div class="p202-fields">
                                            <label>{{ __('whatsapp_supervisor.distribution.name') }}<input required name="name" maxlength="120" value="{{ $rule->name }}"></label>
                                            <label>{{ __('whatsapp_supervisor.distribution.priority') }}<input required name="priority" type="number" min="1" max="10000" value="{{ $rule->priority }}"></label>
                                            <label>{{ __('whatsapp_supervisor.distribution.strategy') }}
                                                <select name="strategy">
                                                    @foreach(['round_robin','least_loaded'] as $strategy)
                                                        <option value="{{ $strategy }}" @selected($rule->strategy === $strategy)>{{ __('whatsapp_supervisor.distribution.strategies.'.$strategy) }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label>{{ __('whatsapp_supervisor.distribution.agents') }}
                                                <select name="agent_ids[]" multiple size="5">
                                                    @foreach($agents as $agent)
                                                        <option value="{{ $agent->id }}" @selected(in_array((int)$agent->id, $rule->agent_ids, true))>{{ $agent->name }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        </div>
                                        <button class="foodex-action-primary" type="submit">{{ __('whatsapp_supervisor.distribution.save') }}</button>
                                    </form>
                                </details>
                                <form method="post" action="{{ route('admin.whatsapp-supervisor.rules.status', ['rule' => $rule->id]) }}" class="p202-actions">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                                    <input type="hidden" name="revision" value="{{ $rule->revision }}">
                                    <input type="hidden" name="is_active" value="{{ $rule->is_active ? 0 : 1 }}">
                                    <button class="foodex-action-secondary" type="submit">{{ $rule->is_active ? __('whatsapp_supervisor.distribution.disable') : __('whatsapp_supervisor.distribution.enable') }}</button>
                                </form>
                            @else
                                <p class="p202-muted">{{ __('whatsapp_supervisor.distribution.legacy_readonly') }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="p202-card p202-muted">{{ __('whatsapp_supervisor.distribution.empty') }}</p>
                    @endforelse
                </div>
                {{ $rules->links() }}
            </section>
        </div>
    </main>
</div>
</body>
</html>
