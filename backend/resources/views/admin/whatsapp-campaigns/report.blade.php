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
<header class="foodex-page-header">
    <div>
        <span class="foodex-subtitle">{{ __('whatsapp.campaigns.reports') }}</span>
        <h1>{{ $overview['campaign']->name }}</h1>
        <p>{{ $overview['campaign']->template_name }} · {{ __('whatsapp.campaigns.statuses.'.strtolower((string)$overview['campaign']->status)) }}</p>
    </div>
    <div class="wa-actions">
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.index') }}">{{ __('whatsapp.campaigns.back_campaigns') }}</a>
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.export',['campaign'=>$overview['campaign']->id,'format'=>'csv']) }}">CSV</a>
        <a class="foodex-action-secondary" href="{{ route('admin.whatsapp-campaigns.export',['campaign'=>$overview['campaign']->id,'format'=>'xlsx']) }}">XLSX</a>
    </div>
</header>

<div class="wa-report-shell">
<section class="wa-report-kpis">
@foreach([
 ['label'=>__('whatsapp.campaigns.total'),'value'=>$overview['metrics']['total']],
 ['label'=>__('whatsapp.campaigns.eligible'),'value'=>$overview['metrics']['eligible']],
 ['label'=>__('whatsapp.campaigns.sent'),'value'=>$overview['metrics']['sent']],
 ['label'=>__('whatsapp.campaigns.delivered'),'value'=>$overview['metrics']['delivered']],
 ['label'=>__('whatsapp.campaigns.read'),'value'=>$overview['metrics']['read']],
 ['label'=>__('whatsapp.campaigns.replies'),'value'=>$overview['metrics']['replied']],
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
<table class="wa-table" style="min-width:0">@forelse($overview['suppression'] as $reason=>$count)<tr><td>{{ $reason }}</td><td>{{ $count }}</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.failure_report') }}</h3>
<table class="wa-table" style="min-width:0">@forelse($overview['failures'] as $reason=>$count)<tr><td style="white-space:normal">{{ $reason }}</td><td>{{ $count }}</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.optout_report') }}</h3>
<p><strong>{{ $overview['opt_outs'] }}</strong> · {{ $overview['rates']['opt_out'] }}%</p>
</section>
<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.followup_report') }}</h3>
<table class="wa-table" style="min-width:0">@forelse($overview['followups'] as $status=>$count)<tr><td>{{ $status }}</td><td>{{ $count }}</td></tr>@empty<tr><td>{{ __('whatsapp.campaigns.none') }}</td></tr>@endforelse</table>
</section>
</div>

<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.recipient_report') }}</h3>
<form method="get" class="wa-filter">
<label>{{ __('whatsapp.campaigns.customer_search') }}<input name="q" value="{{ $filters['q']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.send_status') }}<input name="send_status" value="{{ $filters['send_status']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.eligibility') }}<select name="eligibility_status"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="eligible" @selected(($filters['eligibility_status']??'')==='eligible')>eligible</option><option value="suppressed" @selected(($filters['eligibility_status']??'')==='suppressed')>suppressed</option></select></label>
<label>{{ __('whatsapp.campaigns.consent_state') }}<select name="consent_state"><option value="">{{ __('whatsapp.campaigns.all') }}</option><option value="granted" @selected(($filters['consent_state']??'')==='granted')>granted</option><option value="denied" @selected(($filters['consent_state']??'')==='denied')>denied</option><option value="opted_out" @selected(($filters['consent_state']??'')==='opted_out')>opted_out</option></select></label>
<button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
</form>
<div class="wa-table-wrap" style="margin-top:12px">
<table class="wa-table">
<thead><tr><th>{{ __('whatsapp.inbox.customer') }}</th><th>{{ __('whatsapp.connections.display_phone') }}</th><th>{{ __('whatsapp.inbox.store') }}</th><th>{{ __('whatsapp.inbox.channel') }}</th><th>{{ __('whatsapp.campaigns.consent_state') }}</th><th>{{ __('whatsapp.campaigns.eligibility') }}</th><th>{{ __('whatsapp.campaigns.suppression_reasons') }}</th><th>{{ __('whatsapp.campaigns.send_status') }}</th><th>Provider ID</th><th>{{ __('whatsapp.campaigns.sent') }}</th><th>{{ __('whatsapp.campaigns.delivered') }}</th><th>{{ __('whatsapp.campaigns.read') }}</th><th>{{ __('whatsapp.campaigns.failed') }}</th><th>{{ __('whatsapp.campaigns.replies') }}</th><th>{{ __('whatsapp.campaigns.failure_report') }}</th><th>{{ __('whatsapp.campaigns.followup_report') }}</th></tr></thead>
<tbody>
@forelse($recipients as $row)
<tr>
<td>{{ $row->customer_name ?: ($row->snapshot_data['name']??'-') }}</td><td dir="ltr">{{ $row->phone }}</td><td>{{ $row->store_name ?: '-' }}</td><td>{{ $row->origin_channel ?: ($row->snapshot_data['origin_channel']??'-') }}</td><td>{{ $row->consent_state ?: 'unknown' }}</td><td>{{ $row->eligibility_status }}</td><td>{{ $row->suppression_reason ?: '-' }}</td><td>{{ $row->send_status }}</td><td>{{ $row->provider_message_ref ?: '-' }}</td><td>{{ $row->sent_at ?: '-' }}</td><td>{{ $row->delivered_at ?: '-' }}</td><td>{{ $row->read_at ?: '-' }}</td><td>{{ $row->failed_at ?: '-' }}</td><td>{{ $row->replied_at ?: '-' }}</td><td style="white-space:normal">{{ $row->last_error ?: '-' }}</td><td>{{ $row->followup_status ?: '-' }} @if($row->conversation_id)· <a href="{{ route('admin.whatsapp-inbox.index',['conversation'=>$row->conversation_id]) }}">{{ __('whatsapp.campaigns.open_conversation') }}</a>@endif</td>
</tr>
@empty<tr><td colspan="16">{{ __('whatsapp.campaigns.no_recipients') }}</td></tr>@endforelse
</tbody></table>
</div>
<div style="margin-top:12px">{{ $recipients->links() }}</div>
</section>

<section class="foodex-card wa-report-card">
<h3>{{ __('whatsapp.campaigns.compare_campaigns') }}</h3>
<form method="get" class="wa-filter">
<label>{{ __('whatsapp.campaigns.from') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.to') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ __('whatsapp.campaigns.status') }}<select name="status"><option value="">{{ __('whatsapp.campaigns.all') }}</option>@foreach(['draft','scheduled','running','paused','cancelled','completed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ __('whatsapp.campaigns.statuses.'.$status) }}</option>@endforeach</select></label>
<button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
</form>
<div class="wa-table-wrap" style="margin-top:12px"><table class="wa-table"><thead><tr><th>{{ __('whatsapp.campaigns.campaign') }}</th><th>{{ __('whatsapp.campaigns.template') }}</th><th>{{ __('whatsapp.campaigns.sent') }}</th><th>{{ __('whatsapp.campaigns.delivered') }}</th><th>{{ __('whatsapp.campaigns.read') }}</th><th>{{ __('whatsapp.campaigns.replies') }}</th><th>{{ __('whatsapp.campaigns.failed') }}</th><th>Delivery %</th><th>Read %</th><th>Reply %</th></tr></thead><tbody>
@forelse($comparison as $row)<tr><td><a href="{{ route('admin.whatsapp-campaigns.report',['campaign'=>$row['id']]) }}">{{ $row['name'] }}</a></td><td>{{ $row['template'] }}</td><td>{{ $row['sent'] }}</td><td>{{ $row['delivered'] }}</td><td>{{ $row['read'] }}</td><td>{{ $row['replied'] }}</td><td>{{ $row['failed'] }}</td><td>{{ $row['delivery_rate'] }}</td><td>{{ $row['read_rate'] }}</td><td>{{ $row['reply_rate'] }}</td></tr>
@empty<tr><td colspan="10">{{ __('whatsapp.campaigns.no_campaigns') }}</td></tr>@endforelse
</tbody></table></div>
</section>
</div>
</main>
</div>
</body>
</html>
