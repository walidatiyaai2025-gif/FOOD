<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('whatsapp_supervisor.page_title') }}</title>
@include('admin._brand-components')
<style id="whatsapp-supervisor-ui">
.wa-supervisor-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
.wa-supervisor-shell .foodex-page-toolbar{margin-bottom:0}
.wa-supervisor-shell .row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:var(--foodex-space-3);min-width:0}
.wa-supervisor-shell .g-2{gap:var(--foodex-space-2)}.wa-supervisor-shell .g-3{gap:var(--foodex-space-3)}
.wa-supervisor-shell .col-12{grid-column:span 12}.wa-supervisor-shell .col-8{grid-column:span 8}.wa-supervisor-shell .col-6{grid-column:span 6}.wa-supervisor-shell .col-4{grid-column:span 4}.wa-supervisor-shell .col-3{grid-column:span 3}.wa-supervisor-shell .col-2{grid-column:span 2}
.wa-supervisor-shell .card{min-width:0;overflow:hidden}.wa-supervisor-shell .card-header{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);padding:var(--foodex-space-3) var(--foodex-space-4);border-bottom:1px solid var(--foodex-border);background:var(--foodex-background)}
.wa-supervisor-shell .card-body{padding:var(--foodex-space-4)}.wa-supervisor-shell .h-100{height:100%}
.wa-supervisor-shell .mb-0{margin-bottom:0}.wa-supervisor-shell .mb-1{margin-bottom:var(--foodex-space-1)}.wa-supervisor-shell .mb-2{margin-bottom:var(--foodex-space-2)}.wa-supervisor-shell .mb-3{margin-bottom:var(--foodex-space-3)}
.wa-supervisor-shell .small{font-size:var(--foodex-text-xs)}.wa-supervisor-shell .text-muted{color:var(--foodex-muted)}.wa-supervisor-shell .text-nowrap{white-space:nowrap}
.wa-supervisor-shell .fs-3{font-size:1.65rem}.wa-supervisor-shell .fw-bold{font-weight:var(--foodex-font-weight-bold)}
.wa-supervisor-shell .align-items-end{align-items:end}.wa-supervisor-shell .align-items-center{align-items:center}.wa-supervisor-shell .justify-content-between{justify-content:space-between}
.wa-supervisor-shell .d-flex{display:flex}.wa-supervisor-shell .gap-1{gap:var(--foodex-space-1)}
.wa-supervisor-shell .form-label{display:block;margin-bottom:var(--foodex-space-1);font-weight:var(--foodex-font-weight-medium)}
.wa-supervisor-shell .form-control,.wa-supervisor-shell .form-select{width:100%;max-width:100%}
.wa-supervisor-shell .table-responsive{width:100%;max-width:100%;overflow:auto;-webkit-overflow-scrolling:touch}
.wa-supervisor-shell .foodex-table{border:0;border-radius:0}.wa-supervisor-shell .foodex-table th,.wa-supervisor-shell .foodex-table td{vertical-align:middle}
.wa-supervisor-shell .btn{min-height:38px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-3);display:inline-flex;align-items:center;justify-content:center;gap:var(--foodex-space-1);font:inherit;font-weight:var(--foodex-font-weight-medium);text-decoration:none;cursor:pointer;background:var(--foodex-surface);color:var(--foodex-ink)}
.wa-supervisor-shell .btn-outline-primary{border-color:var(--foodex-green);color:var(--foodex-green-dark)}.wa-supervisor-shell .btn-outline-secondary{color:var(--foodex-muted)}.wa-supervisor-shell .btn-outline-success{border-color:var(--foodex-green);color:var(--foodex-green-dark)}
.wa-supervisor-shell .btn-sm{min-height:34px;padding-inline:var(--foodex-space-2);font-size:var(--foodex-text-xs)}.wa-supervisor-shell .w-100{width:100%}
.wa-supervisor-shell .alert{padding:var(--foodex-space-3) var(--foodex-space-4);border-radius:var(--foodex-radius-md);border:1px solid var(--foodex-border)}.wa-supervisor-shell .alert-success{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-supervisor-shell .alert-danger{background:var(--foodex-orange-soft);color:var(--foodex-orange)}
.wa-supervisor-shell .badge{white-space:nowrap}.wa-supervisor-shell .bg-warning{background:var(--foodex-orange-soft)!important;color:var(--foodex-orange)!important}.wa-supervisor-shell .bg-secondary,.wa-supervisor-shell .bg-light{background:var(--foodex-background)!important;color:var(--foodex-muted)!important}
.wa-kpi-grid{grid-template-columns:repeat(5,minmax(0,1fr))!important}.wa-kpi-grid>div{grid-column:auto!important}
.wa-split-7-5{grid-template-columns:minmax(0,7fr) minmax(0,5fr)!important}.wa-split-7-5>.col-12{grid-column:auto!important}
@media(min-width:768px){
 .wa-supervisor-shell .col-md-3{grid-column:span 3}.wa-supervisor-shell .col-md-2{grid-column:span 2}.wa-supervisor-shell .col-md-1{grid-column:span 1}
}
@media(max-width:1023px){.wa-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.wa-split-7-5{grid-template-columns:1fr!important}}
@media(max-width:767px){
 .wa-supervisor-shell .row{grid-template-columns:1fr}.wa-supervisor-shell [class*="col-"]{grid-column:1!important}.wa-kpi-grid{grid-template-columns:1fr!important}
 .wa-supervisor-shell .card-header{align-items:flex-start;flex-direction:column}.wa-supervisor-shell .foodex-table{min-width:680px}
}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<div class="wa-supervisor-shell">

    <div class="foodex-page-toolbar">
        <div>
            <span class="foodex-subtitle">{{ __('whatsapp_supervisor.subtitle') }}</span>
            <h1>{{ __('whatsapp_supervisor.heading') }}</h1>
            <p>{{ __('whatsapp_supervisor.description') }}</p>
        </div>
        <div class="foodex-header-actions">
            <span class="badge bg-warning">{{ __('whatsapp_supervisor.dark_launch') }}</span>
            
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card foodex-card mb-3">
        <div class="card-header"><strong>{{ __('whatsapp_supervisor.filters') }}</strong></div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label">{{ __('whatsapp.inbox.store') }}</label>
                    <select class="form-select" name="store_id">
                        <option value="">{{ __('whatsapp_supervisor.all_stores') }}</option>
                        @foreach($stores as $store)
                            <option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">{{ __('whatsapp_supervisor.agent') }}</label>
                    <select class="form-select" name="agent_id">
                        <option value="">{{ __('whatsapp_supervisor.all_agents') }}</option>
                        @foreach($dashboard['workload'] as $agent)
                            <option value="{{ $agent->id }}" @selected((string)($filters['agent_id']??'')===(string)$agent->id)>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">{{ __('whatsapp_supervisor.from') }}</label>
                    <input class="form-control" type="date" name="from" value="{{ $filters['from']??$dashboard['period']['from']->toDateString() }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">{{ __('whatsapp_supervisor.to') }}</label>
                    <input class="form-control" type="date" name="to" value="{{ $filters['to']??$dashboard['period']['to']->toDateString() }}">
                </div>
                <div class="col-8 col-md-1">
                    <label class="form-label">{{ __('whatsapp_supervisor.temperature') }}</label>
                    <select class="form-select" name="lead_temperature">
                        <option value="">{{ __('whatsapp_supervisor.all_leads') }}</option>
                        @foreach(['cold','warm','hot'] as $temperature)
                            <option value="{{ $temperature }}" @selected(($filters['lead_temperature']??'')===$temperature)>{{ __('whatsapp_supervisor.'.$temperature) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 col-md-1">
                    <button class="foodex-action-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3 wa-kpi-grid">
        @foreach([
            'total' => $dashboard['summary']['total'],
            'open' => $dashboard['summary']['open'],
            'waiting' => $dashboard['summary']['waiting'],
            'unassigned' => $dashboard['summary']['unassigned'],
            'resolved' => $dashboard['summary']['resolved'],
        ] as $label => $value)
            <div class="col-6 col-md">
                <div class="card foodex-card h-100"><div class="card-body">
                    <div class="text-muted small">{{ __('whatsapp_supervisor.'.$label) }}</div>
                    <div class="fs-3 fw-bold">{{ $value }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card foodex-card mb-3">
        <div class="card-header"><strong>{{ __('whatsapp_supervisor.sla') }}</strong></div>
        <div class="table-responsive">
            <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                <thead><tr>
                    <th></th><th>{{ __('whatsapp_supervisor.average') }}</th><th>{{ __('whatsapp_supervisor.p90') }}</th>
                    <th>{{ __('whatsapp_supervisor.within_sla') }}</th><th>{{ __('whatsapp_supervisor.breaches') }}</th><th>{{ __('whatsapp_supervisor.samples') }}</th>
                </tr></thead>
                <tbody>
                @foreach(['first_response','response','resolution'] as $key)
                    @php($metric=$dashboard['sla'][$key])
                    <tr>
                        <th>{{ __('whatsapp_supervisor.'.$key) }} <small class="text-muted">≤ {{ $dashboard['sla']['thresholds_minutes'][$key] }} {{ __('whatsapp_supervisor.minutes') }}</small></th>
                        <td>{{ $metric['average_minutes']===null?'-':$metric['average_minutes'].' '.__('whatsapp_supervisor.minutes') }}</td>
                        <td>{{ $metric['p90_minutes']===null?'-':$metric['p90_minutes'].' '.__('whatsapp_supervisor.minutes') }}</td>
                        <td>{{ $metric['within_sla_percent']===null?'-':$metric['within_sla_percent'].'%' }}</td>
                        <td>{{ $metric['breaches'] }}</td>
                        <td>{{ $metric['samples'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3 wa-split-7-5">
        <div class="col-12 col-xl-7">
            <div class="card foodex-card h-100">
                <div class="card-header"><strong>{{ __('whatsapp_supervisor.workload') }}</strong></div>
                <div class="table-responsive">
                    <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp_supervisor.agent') }}</th><th>{{ __('whatsapp_supervisor.status') }}</th><th>{{ __('whatsapp_supervisor.load') }}</th><th>{{ __('whatsapp_supervisor.capacity') }}</th><th>{{ __('whatsapp_supervisor.utilization') }}</th></tr></thead>
                        <tbody>
                        @forelse($dashboard['workload'] as $agent)
                            <tr>
                                <td>{{ $agent->name }}</td><td>{{ __('whatsapp_supervisor.agent_statuses.'.strtolower((string) $agent->status)) }}</td><td>{{ $agent->load }}</td><td>{{ $agent->capacity }}</td>
                                <td>{{ number_format($agent->utilization_percent,1) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card foodex-card h-100">
                <div class="card-header"><strong>{{ __('whatsapp_supervisor.outcomes') }}</strong></div>
                <div class="card-body">
                    <table class="foodex-table table table-sm mb-3" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp_supervisor.outcome') }}</th><th>{{ __('whatsapp_supervisor.count') }}</th></tr></thead>
                        <tbody>
                        @forelse($dashboard['outcomes'] as $row)<tr><td>{{ $row->outcome_code }}</td><td>{{ $row->total }}</td></tr>
                        @empty<tr><td colspan="2" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.outcomes.store',['conversation'=>0]) }}" onsubmit="this.action=this.action.replace('/0/outcome','/'+this.elements['conversation_id'].value+'/outcome')">
                        @csrf @method('PUT')
                        <div class="row g-2">
                            <div class="col-4">
                                <select class="form-select" required name="conversation_id" aria-label="{{ __('whatsapp_supervisor.select_conversation') }}">
                                    <option value="">{{ __('whatsapp_supervisor.select_conversation') }}</option>
                                    @foreach($conversationOptions as $conversation)
                                        <option value="{{ $conversation->id }}">{{ $conversation->customer_name ?: __('whatsapp.inbox.unknown_customer') }} · {{ $conversation->store_name ?: '-' }} · {{ __('whatsapp.inbox.conversation_statuses.'.$conversation->status) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-4"><input class="form-control" required name="outcome_code" maxlength="64" placeholder="{{ __('whatsapp_supervisor.outcome') }}"></div>
                            <div class="col-4"><button class="foodex-action-secondary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                            <div class="col-12"><input class="form-control" name="notes" maxlength="4000" placeholder="{{ __('whatsapp_supervisor.detail') }}"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card foodex-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>{{ __('whatsapp_supervisor.campaigns') }}</strong>
            <small class="text-muted">{{ __('whatsapp_supervisor.conversion_window',['days'=>$dashboard['campaigns']['conversion_window_days']]) }}</small>
        </div>
        <div class="table-responsive">
            <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                <thead><tr><th>{{ __('whatsapp_supervisor.campaign') }}</th><th>{{ __('whatsapp_supervisor.sent') }}</th><th>{{ __('whatsapp_supervisor.delivered') }}</th><th>{{ __('whatsapp_supervisor.read') }}</th><th>{{ __('whatsapp_supervisor.replied') }}</th><th>{{ __('whatsapp_supervisor.converted') }}</th><th>{{ __('whatsapp_supervisor.conversion') }}</th></tr></thead>
                <tbody>
                @forelse($dashboard['campaigns']['rows'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td>{{ $row['sent'] }}</td><td>{{ $row['delivered'] }}</td><td>{{ $row['read'] }}</td><td>{{ $row['replied'] }}</td><td>{{ $row['converted'] }}</td><td>{{ $row['conversion_rate'] }}%</td></tr>
                @empty<tr><td colspan="7" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3 wa-split-7-5">
        <div class="col-12 col-xl-7">
            <div class="card foodex-card h-100">
                <div class="card-header d-flex justify-content-between">
                    <strong>{{ __('whatsapp_supervisor.leads') }}</strong>
                    <span class="text-muted small">{{ __('whatsapp_supervisor.cold') }} {{ $dashboard['leads']['cold'] }} · {{ __('whatsapp_supervisor.warm') }} {{ $dashboard['leads']['warm'] }} · {{ __('whatsapp_supervisor.hot') }} {{ $dashboard['leads']['hot'] }}</span>
                </div>
                <div class="table-responsive">
                    <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp_supervisor.customer') }}</th><th>{{ __('whatsapp_supervisor.temperature') }}</th><th>{{ __('whatsapp_supervisor.source') }}</th><th>{{ __('whatsapp_supervisor.owner') }}</th><th>{{ __('whatsapp_supervisor.last_activity') }}</th><th></th></tr></thead>
                        <tbody>
                        @forelse($dashboard['leads']['rows'] as $lead)
                            <tr>
                                <td>{{ $lead->customer_name ?: '#'.$lead->platform_customer_id }}</td>
                                <td>
                                    <form method="post" action="{{ route('admin.whatsapp-supervisor.leads.store') }}" class="d-flex gap-1">
                                        @csrf
                                        <input type="hidden" name="platform_customer_id" value="{{ $lead->platform_customer_id }}">
                                        <input type="hidden" name="store_id" value="{{ $lead->store_id }}">
                                        <input type="hidden" name="conversation_id" value="{{ $lead->conversation_id }}">
                                        <input type="hidden" name="assigned_agent_id" value="{{ $lead->assigned_agent_id }}">
                                        <input type="hidden" name="status" value="{{ $lead->status }}"> {{-- localization-gate: allow form enum code, not user-facing text --}}
                                        <input type="hidden" name="source_type" value="{{ $lead->source_type }}">
                                        <input type="hidden" name="source_id" value="{{ $lead->source_id }}">
                                        <select class="form-select form-select-sm" name="temperature" onchange="this.form.submit()">
                                            @foreach(['cold','warm','hot'] as $temp)<option value="{{ $temp }}" @selected($lead->temperature===$temp)>{{ __('whatsapp_supervisor.'.$temp) }}</option>@endforeach
                                        </select>
                                    </form>
                                </td>
                                <td>{{ __('whatsapp_supervisor.sources.'.strtolower((string) $lead->source_type)) }}{{ $lead->source_id?' #'.$lead->source_id:'' }}</td>
                                <td>{{ $lead->agent_name ?: '-' }}</td>
                                <td>{{ $lead->last_activity_at }}</td>
                                <td><a class="foodex-action-secondary" href="{{ route('admin.whatsapp-supervisor.index',array_merge(request()->except('customer_id'),['customer_id'=>$lead->platform_customer_id])) }}">{{ __('whatsapp_supervisor.view_timeline') }}</a></td>
                            </tr>
                        @empty<tr><td colspan="6" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.leads.store') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-4">
                            <label class="form-label">{{ __('whatsapp_supervisor.customer') }}</label>
                            <select class="form-select" required name="platform_customer_id">
                                <option value="">{{ __('whatsapp_supervisor.select_customer') }}</option>
                                @foreach($customerOptions as $customerOption)
                                    <option value="{{ $customerOption->id }}">{{ $customerOption->name }}{{ $customerOption->phone ? ' · '.$customerOption->phone : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-3"><label class="form-label">{{ __('whatsapp_supervisor.temperature') }}</label><select class="form-select" name="temperature">@foreach(['cold','warm','hot'] as $temp)<option value="{{ $temp }}">{{ __('whatsapp_supervisor.'.$temp) }}</option>@endforeach</select></div>
                        <div class="col-3"><label class="form-label">{{ __('whatsapp_supervisor.status') }}</label><select class="form-select" name="status">@foreach(['open','converted','lost'] as $leadStatus)<option value="{{ $leadStatus }}">{{ __('whatsapp_supervisor.lead_statuses.'.$leadStatus) }}</option>@endforeach</select></div>
                        <input type="hidden" name="source_type" value="manual">
                        <div class="col-2"><button class="foodex-action-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card foodex-card h-100">
                <div class="card-header d-flex justify-content-between"><strong>{{ __('whatsapp_supervisor.follow_ups') }}</strong><span class="text-muted small">{{ __('whatsapp_supervisor.open') }} {{ $dashboard['follow_ups']['open'] }} · {{ __('whatsapp_supervisor.overdue') }} {{ $dashboard['follow_ups']['overdue'] }}</span></div>
                <div class="table-responsive">
                    <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp_supervisor.customer') }}</th><th>{{ __('whatsapp_supervisor.due') }}</th><th>{{ __('whatsapp_supervisor.owner') }}</th><th></th></tr></thead>
                        <tbody>
                        @forelse($dashboard['follow_ups']['rows'] as $followUp)
                            <tr>
                                <td><strong>{{ $followUp->customer_name ?: '#'.$followUp->platform_customer_id }}</strong><div class="small text-muted">{{ $followUp->title }}</div></td>
                                <td>{{ $followUp->due_at ?: '-' }}</td>
                                <td>{{ $followUp->agent_name ?: '-' }}</td>
                                <td>
                                    @if($followUp->status==='open')
                                    <form method="post" action="{{ route('admin.whatsapp-supervisor.follow-ups.transition',['followUp'=>$followUp->id]) }}">
                                        @csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="foodex-action-secondary">{{ __('whatsapp_supervisor.complete') }}</button>
                                    </form>
                                    @else<span class="badge bg-secondary">{{ __('whatsapp_supervisor.follow_up_statuses.'.strtolower((string) $followUp->status)) }}</span>@endif
                                </td>
                            </tr>
                        @empty<tr><td colspan="4" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.follow-ups.store') }}" class="row g-2">
                        @csrf
                        <div class="col-6">
                            <select class="form-select" required name="platform_customer_id" aria-label="{{ __('whatsapp_supervisor.select_customer') }}">
                                <option value="">{{ __('whatsapp_supervisor.select_customer') }}</option>
                                @foreach($customerOptions as $customerOption)
                                    <option value="{{ $customerOption->id }}">{{ $customerOption->name }}{{ $customerOption->phone ? ' · '.$customerOption->phone : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <select class="form-select" name="assigned_agent_id" aria-label="{{ __('whatsapp_supervisor.agent') }}">
                                <option value="">{{ __('whatsapp.inbox.unassigned') }}</option>
                                @foreach($dashboard['workload'] as $agentOption)
                                    <option value="{{ $agentOption->id }}">{{ $agentOption->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12"><input class="form-control" required name="title" maxlength="191" placeholder="{{ __('whatsapp_supervisor.follow_ups') }}"></div>
                        <div class="col-6"><input class="form-control" type="datetime-local" name="due_at"></div>
                        <div class="col-3"><select class="form-select" name="priority">@foreach(['normal','high','urgent','low'] as $priority)<option value="{{ $priority }}">{{ __('whatsapp_supervisor.priorities.'.$priority) }}</option>@endforeach</select></div>
                        <input type="hidden" name="source_type" value="manual">
                        <div class="col-3"><button class="foodex-action-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card foodex-card">
        <div class="card-header"><strong>{{ __('whatsapp_supervisor.timeline') }}</strong></div>
        <div class="card-body">
            @if($timelineCustomer)
                <div class="mb-2"><strong>{{ $timelineCustomer->name }}</strong> <span class="text-muted">{{ $timelineCustomer->phone ?: $timelineCustomer->email }}</span></div>
                <div class="table-responsive">
                    <table class="foodex-table table table-sm align-middle mb-0" data-pagination-exempt="bounded-100">
                        <thead><tr><th>{{ __('whatsapp_supervisor.time') }}</th><th>{{ __('whatsapp_supervisor.event') }}</th><th>{{ __('whatsapp_supervisor.detail') }}</th><th>{{ __('whatsapp_supervisor.status') }}</th></tr></thead>
                        <tbody>
                        @forelse($timeline as $event)
                            <tr><td class="text-nowrap">{{ $event['occurred_at'] }}</td><td><span class="badge bg-light text-dark">{{ __('whatsapp_supervisor.timeline_kinds.'.$event['kind']) }}</span> {{ $event['title'] }}</td><td>{{ $event['detail'] }}</td><td>{{ $event['status']===null?'-':__('whatsapp_supervisor.event_statuses.'.strtolower((string) $event['status'])) }}</td></tr>
                        @empty<tr><td colspan="4" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">{{ __('whatsapp_supervisor.timeline_hint') }}</p>
            @endif
        </div>
    </div>
</div>
    </main>
</div>
</body>
</html>
