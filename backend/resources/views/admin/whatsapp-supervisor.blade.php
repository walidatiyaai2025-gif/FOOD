@extends('layouts.admin')

@section('title', __('whatsapp_supervisor.page_title'))

@section('content')
<div class="container-fluid py-3 foodex-admin-page" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <span class="text-muted small">{{ __('whatsapp_supervisor.subtitle') }}</span>
            <h1 class="h3 mb-1">{{ __('whatsapp_supervisor.heading') }}</h1>
            <p class="text-muted mb-0">{{ __('whatsapp_supervisor.description') }}</p>
        </div>
        <span class="badge bg-warning text-dark">{{ __('whatsapp_supervisor.dark_launch') }}</span>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card mb-3">
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
                    <button class="btn btn-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            'total' => $dashboard['summary']['total'],
            'open' => $dashboard['summary']['open'],
            'waiting' => $dashboard['summary']['waiting'],
            'unassigned' => $dashboard['summary']['unassigned'],
            'resolved' => $dashboard['summary']['resolved'],
        ] as $label => $value)
            <div class="col-6 col-md">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">{{ __('whatsapp_supervisor.'.$label) }}</div>
                    <div class="fs-3 fw-bold">{{ $value }}</div>
                </div></div>
            </div>
        @endforeach
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>{{ __('whatsapp_supervisor.sla') }}</strong></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
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

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-header"><strong>{{ __('whatsapp_supervisor.workload') }}</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('whatsapp_supervisor.agent') }}</th><th>{{ __('whatsapp_supervisor.status') }}</th><th>{{ __('whatsapp_supervisor.load') }}</th><th>{{ __('whatsapp_supervisor.capacity') }}</th><th>{{ __('whatsapp_supervisor.utilization') }}</th></tr></thead>
                        <tbody>
                        @forelse($dashboard['workload'] as $agent)
                            <tr>
                                <td>{{ $agent->name }}</td><td>{{ $agent->status }}</td><td>{{ $agent->load }}</td><td>{{ $agent->capacity }}</td>
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
            <div class="card h-100">
                <div class="card-header"><strong>{{ __('whatsapp_supervisor.outcomes') }}</strong></div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <thead><tr><th>{{ __('whatsapp_supervisor.outcome') }}</th><th>{{ __('whatsapp_supervisor.count') }}</th></tr></thead>
                        <tbody>
                        @forelse($dashboard['outcomes'] as $row)<tr><td>{{ $row->outcome_code }}</td><td>{{ $row->total }}</td></tr>
                        @empty<tr><td colspan="2" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.outcomes.store',['conversation'=>0]) }}" onsubmit="this.action=this.action.replace('/0/outcome','/'+this.elements['conversation_id'].value+'/outcome')">
                        @csrf @method('PUT')
                        <div class="row g-2">
                            <div class="col-4"><input class="form-control" type="number" min="1" required name="conversation_id" placeholder="{{ __('whatsapp_supervisor.conversation_id') }}"></div>
                            <div class="col-4"><input class="form-control" required name="outcome_code" maxlength="64" placeholder="{{ __('whatsapp_supervisor.outcome') }}"></div>
                            <div class="col-4"><button class="btn btn-outline-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                            <div class="col-12"><input class="form-control" name="notes" maxlength="4000" placeholder="{{ __('whatsapp_supervisor.detail') }}"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>{{ __('whatsapp_supervisor.campaigns') }}</strong>
            <small class="text-muted">{{ __('whatsapp_supervisor.conversion_window',['days'=>$dashboard['campaigns']['conversion_window_days']]) }}</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>{{ __('whatsapp_supervisor.campaign') }}</th><th>{{ __('whatsapp_supervisor.sent') }}</th><th>{{ __('whatsapp_supervisor.delivered') }}</th><th>{{ __('whatsapp_supervisor.read') }}</th><th>{{ __('whatsapp_supervisor.replied') }}</th><th>{{ __('whatsapp_supervisor.converted') }}</th><th>{{ __('whatsapp_supervisor.conversion') }}</th></tr></thead>
                <tbody>
                @forelse($dashboard['campaigns']['rows'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td>{{ $row['sent'] }}</td><td>{{ $row['delivered'] }}</td><td>{{ $row['read'] }}</td><td>{{ $row['replied'] }}</td><td>{{ $row['converted'] }}</td><td>{{ $row['conversion_rate'] }}%</td></tr>
                @empty<tr><td colspan="7" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between">
                    <strong>{{ __('whatsapp_supervisor.leads') }}</strong>
                    <span class="text-muted small">{{ __('whatsapp_supervisor.cold') }} {{ $dashboard['leads']['cold'] }} · {{ __('whatsapp_supervisor.warm') }} {{ $dashboard['leads']['warm'] }} · {{ __('whatsapp_supervisor.hot') }} {{ $dashboard['leads']['hot'] }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
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
                                        <input type="hidden" name="status" value="{{ $lead->status }}">
                                        <input type="hidden" name="source_type" value="{{ $lead->source_type }}">
                                        <input type="hidden" name="source_id" value="{{ $lead->source_id }}">
                                        <select class="form-select form-select-sm" name="temperature" onchange="this.form.submit()">
                                            @foreach(['cold','warm','hot'] as $temp)<option value="{{ $temp }}" @selected($lead->temperature===$temp)>{{ __('whatsapp_supervisor.'.$temp) }}</option>@endforeach
                                        </select>
                                    </form>
                                </td>
                                <td>{{ $lead->source_type }}{{ $lead->source_id?' #'.$lead->source_id:'' }}</td>
                                <td>{{ $lead->agent_name ?: '-' }}</td>
                                <td>{{ $lead->last_activity_at }}</td>
                                <td><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.whatsapp-supervisor.index',array_merge(request()->except('customer_id'),['customer_id'=>$lead->platform_customer_id])) }}">{{ __('whatsapp_supervisor.view_timeline') }}</a></td>
                            </tr>
                        @empty<tr><td colspan="6" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.leads.store') }}" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-4"><label class="form-label">Customer ID</label><input class="form-control" type="number" min="1" required name="platform_customer_id"></div>
                        <div class="col-3"><label class="form-label">{{ __('whatsapp_supervisor.temperature') }}</label><select class="form-select" name="temperature">@foreach(['cold','warm','hot'] as $temp)<option value="{{ $temp }}">{{ __('whatsapp_supervisor.'.$temp) }}</option>@endforeach</select></div>
                        <div class="col-3"><label class="form-label">{{ __('whatsapp_supervisor.status') }}</label><select class="form-select" name="status">@foreach(['open','converted','lost'] as $leadStatus)<option value="{{ $leadStatus }}">{{ __('whatsapp_supervisor.lead_statuses.'.$leadStatus) }}</option>@endforeach</select></div>
                        <input type="hidden" name="source_type" value="manual">
                        <div class="col-2"><button class="btn btn-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between"><strong>{{ __('whatsapp_supervisor.follow_ups') }}</strong><span class="text-muted small">{{ __('whatsapp_supervisor.open') }} {{ $dashboard['follow_ups']['open'] }} · {{ __('whatsapp_supervisor.overdue') }} {{ $dashboard['follow_ups']['overdue'] }}</span></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
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
                                        @csrf @method('PATCH')<input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-outline-success">{{ __('whatsapp_supervisor.complete') }}</button>
                                    </form>
                                    @else<span class="badge bg-secondary">{{ $followUp->status }}</span>@endif
                                </td>
                            </tr>
                        @empty<tr><td colspan="4" class="text-muted">{{ __('whatsapp_supervisor.no_data') }}</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body border-top">
                    <form method="post" action="{{ route('admin.whatsapp-supervisor.follow-ups.store') }}" class="row g-2">
                        @csrf
                        <div class="col-6"><input class="form-control" type="number" min="1" name="platform_customer_id" placeholder="{{ __('whatsapp_supervisor.customer_id') }}"></div>
                        <div class="col-6"><input class="form-control" type="number" min="1" name="assigned_agent_id" placeholder="{{ __('whatsapp_supervisor.agent_id') }}"></div>
                        <div class="col-12"><input class="form-control" required name="title" maxlength="191" placeholder="{{ __('whatsapp_supervisor.follow_ups') }}"></div>
                        <div class="col-6"><input class="form-control" type="datetime-local" name="due_at"></div>
                        <div class="col-3"><select class="form-select" name="priority">@foreach(['normal','high','urgent','low'] as $priority)<option value="{{ $priority }}">{{ __('whatsapp_supervisor.priorities.'.$priority) }}</option>@endforeach</select></div>
                        <input type="hidden" name="source_type" value="manual">
                        <div class="col-3"><button class="btn btn-primary w-100" type="submit">{{ __('whatsapp_supervisor.apply') }}</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>{{ __('whatsapp_supervisor.timeline') }}</strong></div>
        <div class="card-body">
            @if($timelineCustomer)
                <div class="mb-2"><strong>{{ $timelineCustomer->name }}</strong> <span class="text-muted">{{ $timelineCustomer->phone ?: $timelineCustomer->email }}</span></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('whatsapp_supervisor.time') }}</th><th>{{ __('whatsapp_supervisor.event') }}</th><th>{{ __('whatsapp_supervisor.detail') }}</th><th>{{ __('whatsapp_supervisor.status') }}</th></tr></thead>
                        <tbody>
                        @forelse($timeline as $event)
                            <tr><td class="text-nowrap">{{ $event['occurred_at'] }}</td><td><span class="badge bg-light text-dark">{{ __('whatsapp_supervisor.timeline_kinds.'.$event['kind']) }}</span> {{ $event['title'] }}</td><td>{{ $event['detail'] }}</td><td>{{ $event['status'] }}</td></tr>
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
@endsection
