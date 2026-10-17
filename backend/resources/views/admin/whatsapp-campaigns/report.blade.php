<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('whatsapp.campaigns.reports') }} · {{ $overview['campaign']->name }}</title>
@include('admin._brand-components')
<style>
.wa-report-shell{display:grid;gap:14px}.wa-report-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.wa-report-kpi{padding:14px}.wa-report-kpi span{display:block;color:var(--foodex-muted);font-size:12px}.wa-report-kpi strong{display:block;font-size:24px;margin-top:4px}
.wa-report-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wa-report-card{padding:16px}.wa-report-card h3{margin-top:0}.wa-report-funnel{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}.wa-funnel-step{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;text-align:center}.wa-funnel-step strong{display:block;font-size:20px}
.wa-table-wrap{overflow:auto}.wa-table{width:100%;border-collapse:collapse;min-width:1100px}.wa-table th,.wa-table td{padding:10px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;white-space:nowrap}.wa-table th{background:var(--foodex-background);font-size:12px}
.wa-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.wa-filter{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;align-items:end}.wa-filter label{display:grid;gap:4px}.wa-rate{color:var(--foodex-muted);font-size:12px}
@media(max-width:1100px){.wa-report-kpis{grid-template-columns:repeat(3,1fr)}.wa-report-funnel{grid-template-columns:repeat(3,1fr)}.wa-filter{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.wa-report-kpis,.wa-report-grid,.wa-report-funnel,.wa-filter{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
<header class="foodex-page-toolbar">
    <div>
        <span class="foodex-subtitle">{{ __('whatsapp.campaigns.reports') }}</span>
        <h1>{{ $overview['campaign']->name }}</h1>
        <p>
            {{ $overview['campaign']->template_name }}
            · {{ __('whatsapp.campaigns.statuses.'.strtolower((string)$overview['campaign']->status)) }}
            · {{ __('whatsapp.campaigns.audience_sources.'.strtolower((string)($overview['campaign']->audience_source ?? 'existing'))) }}
        </p>
        <p class="wa-rate">
            {{ __('whatsapp.campaigns.scheduled_at') }}: {{ $overview['campaign']->scheduled_at ?: '—' }}
            · {{ __('whatsapp.campaigns.started_at') }}: {{ $overview['campaign']->started_at ?: '—' }}
            · {{ __('whatsapp.campaigns.completed_at') }}: {{ $overview['campaign']->completed_at ?: '—' }}
        </p>
    </div>
    <div class="wa-actions">
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.index') }}">{{ __('whatsapp.campaigns.back_campaigns') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.export',array_merge(['campaign'=>$overview['campaign']->id,'format'=>'csv'],request()->only(['q','send_status','eligibility_status','suppression_reason','consent_state','store_id','channel','agent_id','from','to']))) }}">{{ __('whatsapp.campaigns.export_csv') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.export',array_merge(['campaign'=>$overview['campaign']->id,'format'=>'xlsx'],request()->only(['q','send_status','eligibility_status','suppression_reason','consent_state','store_id','channel','agent_id','from','to']))) }}">{{ __('whatsapp.campaigns.export_xlsx') }}</a>
    </div>
</header>

<div class="wa-report-shell">
<section class="wa-report-kpis">
@foreach([
 ['label'=>__('whatsapp.campaigns.total'),'value'=>$overview['metrics']['total']],
 ['label'=>__('whatsapp.campaigns.eligible'),'value'=>$overview['metrics']['eligible']],
 ['label'=>__('whatsapp.campaigns.suppressed'),'value'=>$overview['metrics']['suppressed']],
 ['label'=>__('whatsapp.campaigns.queued'),'value'=>$overview['metrics']['queued']],
 ['label'=>__('whatsapp.campaigns.sent'),'value'=>$overview['metrics']['sent']],
 ['label'=>__('whatsapp.campaigns.delivered'),'value'=>$overview['metrics']['delivered'].' · '.$overview['rates']['delivery'].'%'],
 ['label'=>__('whatsapp.campaigns.read'),'value'=>$overview['metrics']['read'].' · '.$overview['rates']['read'].'%'],
 ['label'=>__('whatsapp.campaigns.replies'),'value'=>$overview['metrics']['replied'].' · '.$overview['rates']['reply'].'%'],
 ['label'=>__('whatsapp.campaigns.failed'),'value'=>$overview['metrics']['failed'].' · '.$overview['rates']['failure'].'%'],
 ['label'=>__('whatsapp.campaigns.opt_outs'),'value'=>$overview['opt_outs'].' · '.$overview['rates']['opt_out'].'%'],
] as $kpi)
<div class="foodex-card wa-report-kpi"><span>{{ $kpi['label'] }}</span><strong>{{ $kpi['value'] }}</strong></div>
@endforeach
</section>

<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.delivery_funnel') }}</h3>
<div class="wa-report-funnel">
@foreach($overview['funnel'] as $key=>$value)
<div class="wa-funnel-step"><span>{{ __('whatsapp.campaigns.funnel_'.$key) }}</span><strong>{{ $value }}</strong>
@if($key==='delivered')<div class="wa-rate">{{ $overview['rates']['delivery'] }}%</div>@elseif($key==='read')<div class="wa-rate">{{ $overview['rates']['read'] }}%</div>@elseif($key==='replied')<div class="wa-rate">{{ $overview['rates']['reply'] }}%</div>@endif
</div>
@endforeach
</div>
</section>

<div class="wa-report-grid">
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.suppression_report') }}</h3>
<table class="wa-table" style="min-width:0" data-pagination-exempt="bounded-summary">@forelse($overview['suppression'] as $reason=>$count)<tr><td><a href="{{ route('admin.whatsapp-campaigns.report',['campaign'=>$overview['campaign']->id,'suppression_reason'=>$reason]) }}">{{ __('whatsapp.campaigns.suppression_reason_values.'.strtolower((string)$reason)) }}</a></td><td>{{ $count }}</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.failure_report') }}</h3>
@php($failedDenominator=max(1,(int)$overview['metrics']['failed']))
<table class="wa-table" style="min-width:0" data-pagination-exempt="bounded-summary">@forelse($overview['failures'] as $reason=>$count)<tr><td style="white-space:normal">{{ $reason }}</td><td>{{ $count }} · {{ round(((int)$count/$failedDenominator)*100,2) }}%</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.optout_report') }}</h3>
<p><strong>{{ $overview['opt_outs'] }}</strong> · {{ $overview['rates']['opt_out'] }}%</p>
<div class="wa-table-wrap">
<table class="wa-table" style="min-width:0" data-pagination-exempt="bounded-100">
<thead><tr><th>{{ __('whatsapp.inbox.customer') }}</th><th>{{ __('whatsapp.connections.display_phone') }}</th><th>{{ __('whatsapp.campaigns.optout_time') }}</th><th>{{ __('whatsapp.campaigns.source') }}</th></tr></thead>
<tbody>
@forelse($overview['opt_out_details'] as $optOut)<tr><td>{{ $optOut->customer_name ?: '-' }}</td><td dir="ltr">{{ $optOut->phone }}</td><td>{{ $optOut->occurred_at }}</td><td>{{ $optOut->source }}</td></tr>
@empty<tr><td colspan="4">{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse
</tbody></table>
</div>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.followup_report') }}</h3>
<table class="wa-table" style="min-width:0" data-pagination-exempt="bounded-summary">@forelse($overview['followups'] as $followupState=>$count)<tr><td>{{ __('whatsapp.campaigns.followup_status_values.'.strtolower((string)$followupState)) }}</td><td>{{ $count }}</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
</div>

<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.recipient_report') }}</h3>
<form method="get" class="wa-filter">
<label>{{ __('whatsapp.campaigns.customer_search') }}<input name="q" value="{{ $filters['q']??'' }}"></label>
<label>{{ __('whatsapp.inbox.store') }}<select name="store_id"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach($reportStores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
<label>{{ __('whatsapp.inbox.channel') }}<select name="channel"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="b2b" @selected(($filters['channel']??'')==='b2b')>{{ __('whatsapp.inbox.channels.b2b') }}</option><option value="b2c" @selected(($filters['channel']??'')==='b2c')>{{ __('whatsapp.inbox.channels.b2c') }}</option></select></label>
<label>{{ __('whatsapp.campaigns.agents') }}<select name="agent_id"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach($reportAgents as $agent)<option value="{{ $agent->id }}" @selected((string)($filters['agent_id']??'')===(string)$agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
<label>{{ __('whatsapp.campaigns.send_status') }}<select name="send_status"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach(['pending','queued','sent','delivered','read','failed','suppressed','cancelled'] as $sendStatus)<option value="{{ $sendStatus }}" @selected(($filters['send_status']??'')===$sendStatus)>{{ __('whatsapp.campaigns.send_status_values.'.$sendStatus) }}</option>@endforeach</select></label>
<label>{{ __('whatsapp.campaigns.eligibility') }}<select name="eligibility_status"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="eligible" @selected(($filters['eligibility_status']??'')==='eligible')>{{ __('whatsapp.campaigns.eligibility_values.eligible') }}</option><option value="suppressed" @selected(($filters['eligibility_status']??'')==='suppressed')>{{ __('whatsapp.campaigns.eligibility_values.suppressed') }}</option></select></label>
<label>{{ __('whatsapp.campaigns.consent_state') }}<select name="consent_state"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="granted" @selected(($filters['consent_state']??'')==='granted')>{{ __('whatsapp.campaigns.consent_values.granted') }}</option><option value="denied" @selected(($filters['consent_state']??'')==='denied')>{{ __('whatsapp.campaigns.consent_values.denied') }}</option><option value="opted_out" @selected(($filters['consent_state']??'')==='opted_out')>{{ __('whatsapp.campaigns.consent_values.opted_out') }}</option></select></label>
<label>{{ __('whatsapp.campaigns.suppression_reasons') }}<select name="suppression_reason"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach(['invalid_phone','opted_out','no_consent','test_allowlist','runtime_policy','template_policy','campaign_limit','transport_unavailable','campaign_cancelled'] as $reason)<option value="{{ $reason }}" @selected(($filters['suppression_reason']??'')===$reason)>{{ __('whatsapp.campaigns.suppression_reason_values.'.$reason) }}</option>@endforeach</select></label>
<label>{{ __('whatsapp.campaigns.from') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.to') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
</form>
<div class="wa-table-wrap" style="margin-top:12px">
<table class="wa-table" data-pagination="server">
<thead><tr><th>{{ __('whatsapp.inbox.customer') }}</th><th>{{ __('whatsapp.connections.display_phone') }}</th><th>{{ __('whatsapp.campaigns.contact_type') }}</th><th>{{ __('whatsapp.inbox.store') }}</th><th>{{ __('whatsapp.inbox.channel') }}</th><th>{{ __('whatsapp.campaigns.consent_state') }}</th><th>{{ __('whatsapp.campaigns.eligibility') }}</th><th>{{ __('whatsapp.campaigns.suppression_reasons') }}</th><th>{{ __('whatsapp.campaigns.send_status') }}</th><th>{{ __('whatsapp.campaigns.provider_message_id') }}</th><th>{{ __('whatsapp.campaigns.sent') }}</th><th>{{ __('whatsapp.campaigns.delivered') }}</th><th>{{ __('whatsapp.campaigns.read') }}</th><th>{{ __('whatsapp.campaigns.failed') }}</th><th>{{ __('whatsapp.campaigns.replies') }}</th><th>{{ __('whatsapp.campaigns.first_reply_time') }}</th><th>{{ __('whatsapp.campaigns.failure_report') }}</th><th>{{ __('whatsapp.campaigns.followup_report') }}</th><th>{{ __('whatsapp.campaigns.followup_overdue') }}</th></tr></thead>
<tbody>
@forelse($recipients as $row)
<tr>
<td>{{ $row->customer_name ?: ($row->snapshot_data['name']??'-') }}</td><td dir="ltr">{{ $row->phone }}</td><td>{{ __('whatsapp.campaigns.contact_type_values.'.($row->snapshot_data['contact_type'] ?? 'existing_contact')) }}</td><td>{{ $row->store_name ?: '-' }}</td><td>{{ $row->origin_channel ? __('whatsapp.inbox.channels.'.strtolower((string)$row->origin_channel)) : (($row->snapshot_data['origin_channel']??null) ? __('whatsapp.inbox.channels.'.strtolower((string)$row->snapshot_data['origin_channel'])) : '-') }}</td><td>{{ __('whatsapp.campaigns.consent_values.'.strtolower((string)($row->consent_state ?: 'unknown'))) }}</td><td>{{ __('whatsapp.campaigns.eligibility_values.'.strtolower((string)$row->eligibility_status)) }}</td><td>{{ $row->suppression_reason ? __('whatsapp.campaigns.suppression_reason_values.'.strtolower((string)$row->suppression_reason)) : '-' }}</td><td>{{ __('whatsapp.campaigns.send_status_values.'.strtolower((string)$row->send_status)) }}</td><td>{{ $row->provider_message_ref ?: '-' }}</td><td>{{ $row->sent_at ?: '-' }}</td><td>{{ $row->delivered_at ?: '-' }}</td><td>{{ $row->read_at ?: '-' }}</td><td>{{ $row->failed_at ?: '-' }}</td><td>{{ $row->replied_at ?: '-' }}</td><td>{{ $row->first_reply_seconds === null ? '-' : $row->first_reply_seconds.' '.__('whatsapp.campaigns.seconds') }}</td><td style="white-space:normal">{{ $row->last_error ?: '-' }}</td><td>{{ $row->followup_status ? __('whatsapp.campaigns.followup_status_values.'.strtolower((string)$row->followup_status)) : '-' }} @if($row->conversation_id)<span aria-hidden="true">·</span> <a href="{{ route('admin.whatsapp-inbox.index',['conversation'=>$row->conversation_id]) }}">{{ __('whatsapp.campaigns.open_conversation') }}</a>@endif</td><td>{{ $row->followup_overdue ? __('whatsapp.campaigns.yes') : __('whatsapp.campaigns.no') }}</td>
</tr>
@empty<tr><td colspan="19">{{ __('whatsapp.campaigns.no_recipients') }}</td></tr>@endforelse
</tbody></table>
</div>
<div style="margin-top:12px">{{ $recipients->links() }}</div>
</section>

<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.compare_campaigns') }}</h3>
<form method="get" class="wa-filter">
<label>{{ __('whatsapp.campaigns.from') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.to') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.template') }}<select name="template_id"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach($reportTemplates as $template)<option value="{{ $template->id }}" @selected((string)($filters['template_id']??'')===(string)$template->id)>{{ $template->name }}</option>@endforeach</select></label>
<label>{{ __('whatsapp.campaigns.status') }}<select name="status"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach(['draft','scheduled','running','paused','cancelled','completed'] as $campaignState)<option value="{{ $campaignState }}" @selected(($filters['status']??'')===$campaignState)>{{ __('whatsapp.campaigns.statuses.'.$campaignState) }}</option>@endforeach</select></label>
<button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
</form>
<div class="wa-table-wrap" style="margin-top:12px"><table class="wa-table" data-pagination-exempt="bounded-100"><thead><tr><th>{{ __('whatsapp.campaigns.campaign') }}</th><th>{{ __('whatsapp.campaigns.template') }}</th><th>{{ __('whatsapp.campaigns.sent') }}</th><th>{{ __('whatsapp.campaigns.delivered') }}</th><th>{{ __('whatsapp.campaigns.read') }}</th><th>{{ __('whatsapp.campaigns.replies') }}</th><th>{{ __('whatsapp.campaigns.failed') }}</th><th>{{ __('whatsapp.campaigns.opt_outs') }}</th><th>{{ __('whatsapp.campaigns.delivery_rate') }}</th><th>{{ __('whatsapp.campaigns.read_rate') }}</th><th>{{ __('whatsapp.campaigns.reply_rate') }}</th><th>{{ __('whatsapp.campaigns.opt_out_rate') }}</th></tr></thead><tbody>
@forelse($comparison as $row)<tr><td><a href="{{ route('admin.whatsapp-campaigns.report',['campaign'=>$row['id']]) }}">{{ $row['name'] }}</a></td><td>{{ $row['template'] }}</td><td>{{ $row['sent'] }}</td><td>{{ $row['delivered'] }}</td><td>{{ $row['read'] }}</td><td>{{ $row['replied'] }}</td><td>{{ $row['failed'] }}</td><td>{{ $row['opt_outs'] }}</td><td>{{ $row['delivery_rate'] }}</td><td>{{ $row['read_rate'] }}</td><td>{{ $row['reply_rate'] }}</td><td>{{ $row['opt_out_rate'] }}</td></tr>
@empty<tr><td colspan="12">{{ __('whatsapp.campaigns.no_campaigns') }}</td></tr>@endforelse
</tbody></table></div>
</section>
</div>
</main>
</div>
</body>
</html>
