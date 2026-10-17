<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('customer_service_workspace.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.cs-shell{display:grid;gap:var(--foodex-space-5)}
.cs-search,.cs-panel{padding:var(--foodex-space-5)}
.cs-search form,.cs-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;align-items:end}
.cs-search label,.cs-filter label,.cs-form label{display:grid;gap:6px;font-weight:700}
.cs-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.cs-kpi{padding:16px;display:grid;gap:5px;text-decoration:none;color:inherit}
.cs-kpi strong{font-size:1.55rem}.cs-kpi small{color:var(--foodex-muted)}
.cs-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.cs-chart{padding:var(--foodex-space-5)}.cs-chart h2{margin:0 0 14px;font-size:1rem}
.cs-bars{display:grid;gap:10px}.cs-bar-row{display:grid;grid-template-columns:minmax(90px,150px) minmax(0,1fr) auto;gap:10px;align-items:center}
.cs-bar-track{height:9px;border-radius:999px;background:var(--foodex-background);overflow:hidden}.cs-bar{height:100%;background:var(--foodex-green-dark);border-radius:999px}
.cs-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.cs-card{padding:var(--foodex-space-5);display:grid;gap:12px;align-content:start;min-height:170px}
.cs-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
.cs-card-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:20px;font-weight:800}
.cs-card h2{margin:0;font-size:1.05rem}.cs-card p{margin:0;color:var(--foodex-muted);line-height:1.7}
.cs-table-wrap{overflow:auto}.cs-table{width:100%;border-collapse:collapse}.cs-table th,.cs-table td{padding:12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.cs-table th{font-size:12px;color:var(--foodex-muted);background:var(--foodex-background)}
.cs-badge{display:inline-flex;border:1px solid var(--foodex-border);border-radius:999px;padding:4px 8px;font-size:12px;font-weight:800;background:#fff}
.cs-badge.danger{background:var(--foodex-danger-soft);color:var(--foodex-danger-text);border-color:var(--foodex-border)}.cs-badge.warn{background:var(--foodex-orange-soft);color:var(--foodex-ink);border-color:var(--foodex-border)}.cs-badge.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:transparent}
.cs-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.cs-form .wide{grid-column:1/-1}.cs-form textarea{min-height:100px}
.cs-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.cs-detail-item{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;display:grid;gap:3px}.cs-detail-item small{color:var(--foodex-muted)}
.cs-notes{display:grid;gap:10px}.cs-note{padding:12px;border:1px solid var(--foodex-border);border-radius:12px}.cs-note p{white-space:pre-wrap}
.cs-exceptions{display:grid;gap:8px}.cs-exception{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px;border:1px solid var(--foodex-border);border-radius:12px;text-decoration:none;color:inherit}
.cs-state{padding:28px;text-align:center}
.cs-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}.cs-section-head h2{margin:0}.cs-section-head p{margin:4px 0 0;color:var(--foodex-muted)}
@media(max-width:1100px){.cs-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.cs-form,.cs-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:850px){.cs-charts,.cs-grid{grid-template-columns:1fr}.cs-table thead{display:none}.cs-table,.cs-table tbody,.cs-table tr,.cs-table td{display:block;width:100%}.cs-table tr{padding:10px 0;border-bottom:1px solid var(--foodex-border)}.cs-table td{border:0;padding:6px 0}.cs-table td:before{content:attr(data-label);display:block;font-size:11px;font-weight:800;color:var(--foodex-muted);margin-bottom:2px}}
@media(max-width:620px){.cs-kpis,.cs-form,.cs-detail-grid{grid-template-columns:1fr}.cs-bar-row{grid-template-columns:90px minmax(0,1fr) auto}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        @include('admin._global-header')
        <header class="foodex-page-toolbar">
            <div>
                <span class="foodex-subtitle">{{ __('customer_service_workspace.eyebrow') }}</span>
                <h1>{{ __('customer_service_workspace.title') }}</h1>
                <p>{{ __('customer_service_workspace.subtitle') }}</p>
            </div>
        </header>

        <div class="cs-shell">
            @if(session('status'))<div class="foodex-success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="foodex-error">{{ $errors->first() }}</div>@endif

            <section class="cs-kpis" aria-label="{{ __('customer_service_workspace.dashboard.kpis') }}">
                @foreach($dashboard['kpis'] as $metric)
                    <a class="foodex-card cs-kpi" href="{{ $metric['url'] }}">
                        <small>{{ __('customer_service_workspace.metrics.'.$metric['key']) }}</small>
                        <strong>{{ $metric['value'] === null ? '—' : $metric['value'] }}</strong>
                        <small>{{ __('customer_service_workspace.dashboard.refreshed') }} {{ $metric['last_refresh'] }}</small>
                    </a>
                @endforeach
            </section>

            <section class="foodex-card cs-panel">
                <div class="cs-section-head">
                    <div><h2>{{ __('customer_service_workspace.dashboard.title') }}</h2><p>{{ __('customer_service_workspace.dashboard.help') }}</p></div>
                    <span class="cs-badge">{{ $dashboard['from'] }} → {{ $dashboard['to'] }}</span>
                </div>
                <form method="get" class="cs-filter">
                    <label>{{ __('customer_service_workspace.filters.from') }}<input type="date" name="from" value="{{ $filters['from'] ?? $dashboard['from'] }}"></label>
                    <label>{{ __('customer_service_workspace.filters.to') }}<input type="date" name="to" value="{{ $filters['to'] ?? $dashboard['to'] }}"></label>
                    <label>{{ __('customer_service_workspace.filters.store') }}
                        <select name="store_id"><option value="">{{ __('customer_service_workspace.values.all') }}</option>
                            @foreach($storeOptions as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }} · {{ $store->code }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('customer_service_workspace.filters.status') }}
                        <select name="status"><option value="">{{ __('customer_service_workspace.values.all') }}</option>
                            @foreach($caseStatuses as $value)<option value="{{ $value }}" @selected(($filters['status']??'')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('customer_service_workspace.filters.priority') }}
                        <select name="priority"><option value="">{{ __('customer_service_workspace.values.all') }}</option>
                            @foreach($casePriorities as $value)<option value="{{ $value }}" @selected(($filters['priority']??'')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('customer_service_workspace.filters.queue') }}
                        <select name="queue"><option value="">{{ __('customer_service_workspace.values.all') }}</option>
                            @foreach($caseQueues as $value)<option value="{{ $value }}" @selected(($filters['queue']??'')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('customer_service_workspace.filters.category') }}
                        <select name="type"><option value="">{{ __('customer_service_workspace.values.all') }}</option>
                            @foreach($caseCategories as $value)<option value="{{ $value }}" @selected(($filters['type']??'')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('customer_service_workspace.filters.search') }}<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="{{ __('customer_service_workspace.filters.search_placeholder') }}"></label>
                    <div><button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('customer_service_workspace.filters.apply') }}</button></div>
                </form>
            </section>

            <section class="cs-charts" aria-label="{{ __('customer_service_workspace.dashboard.charts') }}">
                @foreach(['customer_growth','case_status','sla','category','store_mix','customer_value'] as $chartKey)
                    @php($chart=$dashboard['charts'][$chartKey] ?? [])
                    @php($max=max(1,(float)(collect($chart)->max(fn($row)=>max((float)($row['value']??0),(float)($row['new']??0),(float)($row['repeat']??0))) ?: 1)))
                    <article class="foodex-card cs-chart">
                        <h2>{{ __('customer_service_workspace.charts.'.$chartKey) }}</h2>
                        <div class="cs-bars">
                            @forelse($chart as $row)
                                @if($chartKey==='customer_growth')
                                    <div class="cs-bar-row"><span>{{ $row['label'] }}</span><div class="cs-bar-track"><div class="cs-bar" style="width:{{ min(100,((float)$row['new']/$max)*100) }}%"></div></div><strong>{{ $row['new'] }} / {{ $row['repeat'] }}</strong></div>
                                @else
                                    @php($value=(float)($row['value']??0))
                                    <div class="cs-bar-row"><span>{{ __('customer_service_workspace.values.'.$row['label']) !== 'customer_service_workspace.values.'.$row['label'] ? __('customer_service_workspace.values.'.$row['label']) : $row['label'] }}</span><div class="cs-bar-track"><div class="cs-bar" style="width:{{ min(100,($value/$max)*100) }}%"></div></div><strong>{{ $value }}</strong></div>
                                @endif
                            @empty
                                <div class="foodex-state">{{ __('customer_service_workspace.dashboard.no_data') }}</div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </section>

            @if(!empty($dashboard['exceptions']))
            <section class="foodex-card cs-panel">
                <div class="cs-section-head"><div><h2>{{ __('customer_service_workspace.exceptions.title') }}</h2><p>{{ __('customer_service_workspace.exceptions.help') }}</p></div></div>
                <div class="cs-exceptions">
                    @foreach($dashboard['exceptions'] as $item)
                        <a class="cs-exception" href="{{ $item['url'] }}">
                            <span><strong>#{{ $item['id'] }} · {{ $item['subject'] ?: __('customer_service_workspace.values.untitled') }}</strong><br><small>{{ $item['customer_name'] ?: '—' }} · {{ __('customer_service_workspace.values.'.$item['priority']) }}</small></span>
                            <span class="cs-badge danger">{{ $item['sla_target_at'] ?: '—' }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
            @endif

            @if($canCustomers)
            <section class="foodex-card cs-search" aria-labelledby="customer-service-search-title">
                <div class="cs-section-head">
                    <div><h2 id="customer-service-search-title">{{ __('customer_service_workspace.search_title') }}</h2><p>{{ __('customer_service_workspace.search_help') }}</p></div>
                </div>
                <form method="get" action="{{ route('admin.customer-360.index') }}">
                    <label><span>{{ __('customer_service_workspace.search_label') }}</span><input name="q" maxlength="120" autocomplete="off" placeholder="{{ __('customer_service_workspace.search_placeholder') }}"></label>
                    <button class="foodex-action-primary foodex-filter-action" type="submit">{{ __('customer_service_workspace.open_customer') }}</button>
                </form>
            </section>
            @endif

            <section class="foodex-card cs-panel" data-pagination-required>
                <div class="cs-section-head">
                    <div><h2>{{ __('customer_service_workspace.cases.title') }}</h2><p>{{ __('customer_service_workspace.cases.help') }}</p></div>
                    @if($canManageCases)<span class="cs-badge ok">{{ __('customer_service_workspace.cases.manage_enabled') }}</span>@endif
                </div>
                @if($cases->isEmpty())
                    <div class="foodex-state cs-state">{{ __('customer_service_workspace.cases.empty') }}</div>
                @else
                    <div class="cs-table-wrap">
                        <table class="cs-table" data-pagination-required>
                            <thead><tr>
                                <th>{{ __('customer_service_workspace.cases.case') }}</th><th>{{ __('customer_service_workspace.cases.customer') }}</th><th>{{ __('customer_service_workspace.filters.store') }}</th><th>{{ __('customer_service_workspace.filters.status') }}</th><th>{{ __('customer_service_workspace.filters.priority') }}</th><th>{{ __('customer_service_workspace.filters.queue') }}</th><th>{{ __('customer_service_workspace.cases.owner') }}</th><th>{{ __('customer_service_workspace.cases.sla') }}</th><th></th>
                            </tr></thead>
                            <tbody>
                            @foreach($cases as $case)
                                @php($overdue=in_array($case->status,['open','pending'],true) && $case->sla_target_at && \Illuminate\Support\Carbon::parse($case->sla_target_at)->isPast())
                                <tr>
                                    <td data-label="{{ __('customer_service_workspace.cases.case') }}"><strong>{{ \App\Support\DisplayNumber::reference($case->id) }}</strong><br><small>{{ $case->subject ?: __('customer_service_workspace.values.untitled') }}</small></td>
                                    <td data-label="{{ __('customer_service_workspace.cases.customer') }}">{{ $case->customer_name ?: '—' }}</td>
                                    <td data-label="{{ __('customer_service_workspace.filters.store') }}">{{ $case->store_name ?: '—' }}</td>
                                    <td data-label="{{ __('customer_service_workspace.filters.status') }}"><span class="cs-badge">{{ __('customer_service_workspace.values.'.$case->status) }}</span></td>
                                    <td data-label="{{ __('customer_service_workspace.filters.priority') }}"><span class="cs-badge {{ in_array($case->priority,['high','urgent'],true)?'warn':'' }}">{{ __('customer_service_workspace.values.'.$case->priority) }}</span></td>
                                    <td data-label="{{ __('customer_service_workspace.filters.queue') }}">{{ __('customer_service_workspace.values.'.($case->queue ?: 'general')) }}</td>
                                    <td data-label="{{ __('customer_service_workspace.cases.owner') }}">{{ $case->assigned_agent_name ?: __('customer_service_workspace.values.unassigned') }}</td>
                                    <td data-label="{{ __('customer_service_workspace.cases.sla') }}"><span class="cs-badge {{ $overdue?'danger':'' }}">{{ $case->sla_target_at ?: '—' }}</span></td>
                                    <td><a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.customer-service.cases.show',['case'=>$case->id]) }}">{{ __('customer_service_workspace.cases.open') }}</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:14px">{{ $cases->links() }}</div>
                @endif
            </section>

            @if($canManageCases)
            <section class="foodex-card cs-panel">
                <details @if(old('platform_customer_id')) open @endif>
                    <summary class="foodex-action-primary" style="display:inline-flex">{{ __('customer_service_workspace.cases.create') }}</summary>
                    <form method="post" action="{{ route('admin.erp.customer-service.cases.store') }}" class="cs-form" style="margin-top:16px">@csrf
                        <label>{{ __('customer_service_workspace.filters.store') }}<select name="store_id" required><option value="">{{ __('customer_service_workspace.values.choose') }}</option>@foreach($manageStoreOptions as $store)<option value="{{ $store->id }}" @selected((string)old('store_id')===(string)$store->id)>{{ $store->name }} · {{ $store->code }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.cases.customer') }}<select name="platform_customer_id" required><option value="">{{ __('customer_service_workspace.values.choose') }}</option>@foreach($customerOptions as $customer)<option value="{{ $customer->id }}" @selected((string)old('platform_customer_id')===(string)$customer->id)>{{ $customer->name }} · {{ $customer->email ?: $customer->phone }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.filters.category') }}<select name="type" required>@foreach($caseCategories as $value)<option value="{{ $value }}" @selected(old('type','other')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.filters.priority') }}<select name="priority" required>@foreach($casePriorities as $value)<option value="{{ $value }}" @selected(old('priority','normal')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.filters.queue') }}<select name="queue"><option value="">{{ __('customer_service_workspace.values.choose') }}</option>@foreach($caseQueues as $value)<option value="{{ $value }}" @selected(old('queue')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.cases.owner') }}<select name="assigned_agent_id"><option value="">{{ __('customer_service_workspace.values.unassigned') }}</option>@foreach($agentOptions as $agent)<option value="{{ $agent->id }}" @selected((string)old('assigned_agent_id')===(string)$agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
                        <label class="wide">{{ __('customer_service_workspace.cases.subject') }}<input name="subject" maxlength="190" required value="{{ old('subject') }}"></label>
                        <label class="wide">{{ __('customer_service_workspace.cases.description') }}<textarea name="description" maxlength="8000">{{ old('description') }}</textarea></label>
                        <label>{{ __('customer_service_workspace.cases.order_id') }}<select name="order_id"><option value="">{{ __('customer_service_workspace.values.none') }}</option>@foreach($orderOptions as $record)<option value="{{ $record->id }}" @selected((string)old('order_id')===(string)$record->id)>{{ $record->reference }} · {{ $record->customer_name }} · {{ $record->store_name }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.cases.invoice_id') }}<select name="invoice_id"><option value="">{{ __('customer_service_workspace.values.none') }}</option>@foreach($invoiceOptions as $record)<option value="{{ $record->id }}" @selected((string)old('invoice_id')===(string)$record->id)>{{ $record->reference }} · {{ $record->customer_name }} · {{ $record->store_name }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.cases.conversation_id') }}<select name="conversation_id"><option value="">{{ __('customer_service_workspace.values.none') }}</option>@foreach($conversationOptions as $record)<option value="{{ $record->id }}" @selected((string)old('conversation_id')===(string)$record->id)>{{ $record->customer_name }} · {{ $record->store_name }} · {{ \Illuminate\Support\Str::limit($record->reference,18) }}</option>@endforeach</select></label>
                        <label>{{ __('customer_service_workspace.cases.delivery_reference') }}<input name="delivery_reference" maxlength="120" value="{{ old('delivery_reference') }}"></label>
                        <label>{{ __('customer_service_workspace.cases.sla') }}<input type="datetime-local" name="sla_target_at" value="{{ old('sla_target_at') }}"></label>
                        <div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_service_workspace.cases.create_action') }}</button></div>
                    </form>
                </details>
            </section>
            @endif

            @if($selectedCase)
            @php($selectedOverdue=in_array($selectedCase->status,['open','pending'],true) && $selectedCase->sla_target_at && \Illuminate\Support\Carbon::parse($selectedCase->sla_target_at)->isPast())
            <section class="foodex-card cs-panel" id="case-detail">
                <div class="cs-section-head">
                    <div><h2>{{ \App\Support\DisplayNumber::reference($selectedCase->id) }} · {{ $selectedCase->subject ?: __('customer_service_workspace.values.untitled') }}</h2><p>{{ $selectedCase->customer_name }} · {{ $selectedCase->store_name }}</p></div>
                    <span class="cs-badge {{ $selectedOverdue?'danger':'ok' }}">{{ __('customer_service_workspace.values.'.$selectedCase->status) }}</span>
                </div>
                <div class="cs-detail-grid">
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.filters.category') }}</small><strong>{{ __('customer_service_workspace.values.'.$selectedCase->type) }}</strong></div>
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.filters.priority') }}</small><strong>{{ __('customer_service_workspace.values.'.$selectedCase->priority) }}</strong></div>
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.filters.queue') }}</small><strong>{{ __('customer_service_workspace.values.'.($selectedCase->queue ?: 'general')) }}</strong></div>
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.cases.owner') }}</small><strong>{{ $selectedCase->assigned_agent_name ?: __('customer_service_workspace.values.unassigned') }}</strong></div>
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.cases.sla') }}</small><strong>{{ $selectedCase->sla_target_at ?: '—' }}</strong></div>
                    <div class="cs-detail-item"><small>{{ __('customer_service_workspace.cases.source') }}</small><strong>{{ __('customer_service_workspace.values.'.$selectedCase->source) }}</strong></div>
                </div>
                @if($selectedCase->description)
                    <p style="white-space:pre-wrap">{{ $selectedCase->description }}</p>
                @endif
                <div class="c360-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
                    @if($selectedCase->platform_customer_id)<a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.show',['platformCustomer'=>$selectedCase->platform_customer_id]).'#service' }}">{{ __('customer_service_workspace.cases.open_customer') }}</a>@endif
                    @if($selectedCase->order_id)<a class="foodex-action-secondary button secondary" href="{{ route('admin.operations.orders.index',['q'=>$selectedCase->order_number ?: $selectedCase->order_id]) }}">{{ __('customer_service_workspace.cases.open_order') }}</a>@endif
                    @if($selectedCase->invoice_id)<a class="foodex-action-secondary button secondary" href="{{ route('admin.invoices.show',['invoice'=>$selectedCase->invoice_id]) }}">{{ __('customer_service_workspace.cases.open_invoice') }}</a>@endif
                    @if($selectedCase->conversation_id)<a class="foodex-action-secondary button secondary" href="{{ route('admin.whatsapp-inbox.index',['conversation'=>$selectedCase->conversation_id]) }}">{{ __('customer_service_workspace.cases.open_conversation') }}</a>@endif
                    @if($selectedCase->delivery_reference)<a class="foodex-action-secondary button secondary" href="{{ route('admin.field-operations.overview',['q'=>$selectedCase->delivery_reference]) }}">{{ __('customer_service_workspace.cases.open_delivery') }}</a>@endif
                </div>

                @if($canManageCases && $selectedCase->store_id && $manageStoreOptions->contains('id',(int)$selectedCase->store_id))
                <form method="post" action="{{ route('admin.erp.customer-service.cases.update',['case'=>$selectedCase->id]) }}" class="cs-form" style="margin-top:18px">@csrf @method('PATCH')
                    <label>{{ __('customer_service_workspace.filters.status') }}<select name="status">@foreach($caseStatuses as $value)<option value="{{ $value }}" @selected($selectedCase->status===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                    <label>{{ __('customer_service_workspace.filters.priority') }}<select name="priority">@foreach($casePriorities as $value)<option value="{{ $value }}" @selected($selectedCase->priority===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                    <label>{{ __('customer_service_workspace.filters.queue') }}<select name="queue">@foreach($caseQueues as $value)<option value="{{ $value }}" @selected(($selectedCase->queue?:'general')===$value)>{{ __('customer_service_workspace.values.'.$value) }}</option>@endforeach</select></label>
                    <label>{{ __('customer_service_workspace.cases.owner') }}<select name="assigned_agent_id"><option value="">{{ __('customer_service_workspace.values.unassigned') }}</option>@foreach($agentOptions as $agent)<option value="{{ $agent->id }}" @selected((int)$selectedCase->assigned_agent_id===(int)$agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
                    <label>{{ __('customer_service_workspace.cases.sla') }}<input type="datetime-local" name="sla_target_at" value="{{ $selectedCase->sla_target_at ? \Illuminate\Support\Carbon::parse($selectedCase->sla_target_at)->format('Y-m-d\TH:i') : '' }}"></label>
                    <label>{{ __('customer_service_workspace.cases.recovery_code') }}<input name="service_recovery_code" maxlength="64" value="{{ $selectedCase->service_recovery_code }}"></label>
                    <label class="wide">{{ __('customer_service_workspace.cases.recovery_note') }}<textarea name="service_recovery_note" maxlength="4000">{{ $selectedCase->service_recovery_note }}</textarea></label>
                    <label>{{ __('customer_service_workspace.cases.delivery_reference') }}<input name="delivery_reference" maxlength="120" value="{{ $selectedCase->delivery_reference }}"></label>
                    <div class="wide"><button class="foodex-action-primary" type="submit">{{ __('customer_service_workspace.cases.save') }}</button></div>
                </form>
                @endif

                <hr style="border:0;border-top:1px solid var(--foodex-border);margin:20px 0">
                <div class="cs-section-head"><div><h2>{{ __('customer_service_workspace.notes.title') }}</h2><p>{{ __('customer_service_workspace.notes.help') }}</p></div></div>
                <div class="cs-notes">
                    @forelse($selectedCase->notes as $note)
                        <article class="cs-note"><strong>{{ $note->author_name ?: __('customer_service_workspace.values.system') }}</strong> · <small>{{ $note->created_at }}</small>
                            @if($note->body)
                                <p>{{ $note->body }}</p>
                            @endif
                            @if($note->original_name)<a href="{{ route('admin.erp.customer-service.cases.notes.attachment',['case'=>$selectedCase->id,'note'=>$note->id]) }}">{{ $note->original_name }}</a>@endif
                        </article>
                    @empty<div class="foodex-state">{{ __('customer_service_workspace.notes.empty') }}</div>@endforelse
                </div>
                @if($canManageCases && $selectedCase->store_id && $manageStoreOptions->contains('id',(int)$selectedCase->store_id))
                <form method="post" enctype="multipart/form-data" action="{{ route('admin.erp.customer-service.cases.notes.store',['case'=>$selectedCase->id]) }}" class="cs-form" style="margin-top:14px">@csrf
                    <label class="wide">{{ __('customer_service_workspace.notes.body') }}<textarea name="body" maxlength="8000"></textarea></label>
                    <label>{{ __('customer_service_workspace.notes.attachment') }}<input type="file" name="attachment" accept=".pdf,.png,.jpg,.jpeg,.txt,.csv"></label>
                    <div class="wide"><button class="foodex-action-secondary button secondary" type="submit">{{ __('customer_service_workspace.notes.add') }}</button></div>
                </form>
                @endif

                @if(in_array($selectedCase->status,['resolved','closed'],true) && $canManageCases)
                <hr style="border:0;border-top:1px solid var(--foodex-border);margin:20px 0">
                <form method="post" action="{{ route('admin.erp.customer-service.cases.feedback.store',['case'=>$selectedCase->id]) }}" class="cs-form">@csrf
                    <label>{{ __('customer_service_workspace.feedback.csat') }}<input type="number" name="csat_score" min="1" max="5" value="{{ $selectedCase->csat_score }}"></label>
                    <label>{{ __('customer_service_workspace.feedback.nps') }}<input type="number" name="nps_score" min="0" max="10" value="{{ $selectedCase->nps_score }}"></label>
                    <label>{{ __('customer_service_workspace.feedback.context') }}<input name="feedback_context" maxlength="190" required value="{{ $selectedCase->feedback_context }}"></label>
                    <label class="wide" style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input type="checkbox" name="consent" value="1" required> <span>{{ __('customer_service_workspace.feedback.consent') }}</span></label>
                    <div class="wide"><button class="foodex-action-secondary button secondary" type="submit">{{ __('customer_service_workspace.feedback.record') }}</button></div>
                </form>
                @endif
            </section>
            @endif

            <nav class="foodex-tabs" aria-label="{{ __('customer_service_workspace.journey_label') }}">
                @foreach($capabilities as $item)<a class="foodex-tab" href="{{ $item['url'] }}">{{ __('customer_service_workspace.cards.'.$item['key'].'.title') }}</a>@endforeach
            </nav>
            @if($capabilities->isNotEmpty())
                <section class="cs-grid" aria-label="{{ __('customer_service_workspace.journey_label') }}">
                    @foreach($capabilities as $item)
                        <article class="foodex-card cs-card"><div class="cs-card-head"><h2>{{ __('customer_service_workspace.cards.'.$item['key'].'.title') }}</h2><span class="cs-card-icon" aria-hidden="true">{{ $item['icon'] }}</span></div><p>{{ __('customer_service_workspace.cards.'.$item['key'].'.description') }}</p><a class="foodex-action-secondary button secondary" href="{{ $item['url'] }}">{{ __('customer_service_workspace.cards.'.$item['key'].'.action') }}</a></article>
                    @endforeach
                </section>
            @endif

            <section class="foodex-card cs-search" role="note"><strong>{{ __('customer_service_workspace.truth_title') }}</strong><p>{{ __('customer_service_workspace.truth_help') }}</p></section>
        </div>
    </main>
</div>
</body>
</html>
