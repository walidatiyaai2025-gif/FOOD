<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_bi_ui.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
.bi-shell{display:grid;gap:var(--foodex-space-5)}.bi-panel{padding:var(--foodex-space-5)}
.bi-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap}
.bi-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.bi-card{padding:18px;display:grid;gap:10px;min-width:0}
.bi-card h2,.bi-card h3{margin:0}.bi-muted{color:var(--foodex-muted);line-height:1.65}.bi-pill{display:inline-flex;width:max-content;max-width:100%;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.bi-pill.off{background:var(--foodex-background);color:var(--foodex-muted);border:1px solid var(--foodex-border)}
.bi-form{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;align-items:end}.bi-form label{display:grid;gap:5px;font-weight:700}.bi-form .wide{grid-column:span 2}
.bi-table-wrap{overflow:auto;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card)}.bi-table{width:100%;border-collapse:collapse;min-width:760px}.bi-table th,.bi-table td{padding:11px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.bi-table th{font-size:12px;color:var(--foodex-muted);background:var(--foodex-background);white-space:nowrap}
.bi-status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.bi-list{display:grid;gap:9px}.bi-list-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control)}.bi-list-row strong{display:block}.bi-metric{font-size:30px;font-weight:900}.bi-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.bi-error{border-inline-start:4px solid var(--foodex-danger-text)}.bi-empty{text-align:center;padding:28px;color:var(--foodex-muted)}
.bi-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}
@media(max-width:1100px){.bi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.bi-form{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767px){.bi-grid,.bi-status-grid{grid-template-columns:1fr}.bi-form{grid-template-columns:1fr}.bi-form .wide{grid-column:auto}.bi-form button{width:100%}.bi-list-row{grid-template-columns:1fr}.bi-table{min-width:620px}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header">
    <div>
        <span class="foodex-subtitle">{{ __('enterprise_bi_ui.eyebrow') }}</span>
        <h1>{{ __('enterprise_bi_ui.title') }}</h1>
        <p>{{ __('enterprise_bi_ui.subtitle') }}</p>
    </div>
    <div class="bi-actions">
        @if($legacyReportsUrl)<a class="foodex-action-secondary button secondary" href="{{ $legacyReportsUrl }}">{{ __('enterprise_bi_ui.legacy_reports') }}</a>@endif
        @include('admin._live-notifications',['user'=>auth()->user()])
    </div>
</header>

<div class="bi-shell">
<section class="foodex-card bi-panel">
    <div class="bi-head">
        <div><h2>{{ __('enterprise_bi_ui.catalog_title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.catalog_help') }}</p></div>
    </div>
    <div class="bi-grid" style="margin-top:14px">
        @foreach($catalog as $item)
        <article class="foodex-card bi-card">
            <span class="bi-pill {{ $item['available']?'':'off' }}">{{ $item['available']?__('enterprise_bi_ui.available'):__('enterprise_bi_ui.unavailable') }}</span>
            <h3>{{ $item['title'] }}</h3>
            <p class="bi-muted">{{ $item['description'] }}</p>
            @if($item['available'])
            <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.bi',['report'=>$item['code']]) }}">{{ __('enterprise_bi_ui.builder_title') }}</a>
            @endif
        </article>
        @endforeach
    </div>
</section>

@if($selected)
<section class="foodex-card bi-panel">
    <div class="bi-head">
        <div><h2>{{ __('enterprise_bi_ui.builder_title') }}</h2><p class="bi-muted">{{ $selected['title'] }}</p></div>
    </div>
    @if($selected['available'])
    <form method="get" action="{{ route('admin.erp.bi') }}" class="bi-form" data-bi-run-form style="margin-top:14px">
        <input type="hidden" name="run" value="1">
        <label>{{ __('enterprise_bi_ui.report') }}
            <select name="report" data-bi-report>
                @foreach($catalog as $item)
                    @if($item['available'])<option value="{{ $item['code'] }}" @selected($selected['code']===$item['code'])>{{ $item['title'] }}</option>@endif
                @endforeach
            </select>
        </label>
        <label class="wide">{{ __('enterprise_bi_ui.scope') }}
            <select name="scope">
                @foreach($selected['scope_options'] as $scope)
                    <option value="{{ $scope['key'] }}" @selected(($selection['scope_key']??null)===$scope['key'])>{{ $scope['label'] }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('enterprise_bi_ui.breakdown') }}
            <select name="breakdown">
                @foreach($selected['breakdowns'] as $key=>$dimensions)
                    <option value="{{ $key }}" @selected(($selection['breakdown']??'')===$key)>{{ __('enterprise_bi_ui.breakdowns.'.$key) }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('enterprise_bi_ui.from') }}<input type="date" name="from" value="{{ $selection['from']??'' }}" @if($selected['definition']->maxRangeDays===1) readonly @endif></label>
        <label>{{ __('enterprise_bi_ui.to') }}<input type="date" name="to" value="{{ $selection['to']??'' }}"></label>
        <button class="foodex-action-primary foodex-filter-action" type="submit" data-bi-run-button data-loading-label="{{ __('enterprise_bi_ui.running') }}">{{ __('enterprise_bi_ui.run') }}</button>
    </form>
    @else
        <div class="foodex-state bi-empty" role="status">{{ __('enterprise_bi_ui.unavailable') }}</div>
    @endif
</section>
@endif

@if($runError)
<section class="foodex-card bi-panel bi-error" role="alert">{{ $runError }}</section>
@endif

@if($result)
<section class="foodex-card bi-panel">
    <div class="bi-head">
        <div><h2>{{ __('enterprise_bi_ui.results') }}</h2><p class="bi-muted">{{ $selected['title'] }} · {{ __('enterprise_bi_ui.page') }} {{ $selection['page'] }}</p></div>
    </div>
    @if(empty($rows))
        <div class="bi-empty">{{ __('enterprise_bi_ui.no_rows') }}</div>
    @else
    @php($columnKeys=array_keys($rows[0]['cells']))
    <div class="bi-table-wrap" style="margin-top:14px">
        <table class="bi-table" data-pagination-required="server-bounded">
            <thead><tr>
                @foreach($columnKeys as $key)<th>{{ __('enterprise_bi_ui.fields.'.$key) }}</th>@endforeach
                <th>{{ __('enterprise_bi_ui.source_provenance') }}</th>
            </tr></thead>
            <tbody>
            @foreach($rows as $row)
                <tr>
                @foreach($columnKeys as $key)
                    <td>{{ data_get($row['cells'],$key,'—')===null?'—':data_get($row['cells'],$key,'—') }}</td>
                @endforeach
                <td>
                    @if($row['drill_url'])
                        <a class="foodex-action-secondary button secondary" href="{{ $row['drill_url'] }}">{{ __('enterprise_bi_ui.open_source') }}</a>
                    @elseif($row['provenance'])
                        <span class="bi-pill">{{ __('enterprise_bi_ui.source_provenance') }}</span>
                    @else — @endif
                </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
    <div class="bi-pagination">
        <span>{{ __('enterprise_bi_ui.page') }} {{ $selection['page'] }}</span>
        <div class="bi-actions">
            @if($selection['page']>1)
            <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.bi',array_merge(request()->except('page'),['page'=>$selection['page']-1])) }}">{{ __('enterprise_bi_ui.previous') }}</a>
            @endif
            @if(($result['has_more']??false))
            <a class="foodex-action-secondary button secondary" href="{{ route('admin.erp.bi',array_merge(request()->except('page'),['page'=>$selection['page']+1])) }}">{{ __('enterprise_bi_ui.next') }}</a>
            @endif
        </div>
    </div>
</section>
@endif

<section class="bi-status-grid">
<article class="foodex-card bi-panel">
    <div class="bi-head"><div><h2>{{ __('enterprise_bi_ui.exports_title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.exports_help') }}</p></div></div>
    <div class="bi-list" style="margin-top:12px">
    @forelse($exports as $export)
        <div class="bi-list-row">
            <div><strong>{{ $export['report'] }}</strong><span class="bi-muted">{{ __('enterprise_bi_ui.rows') }} {{ $export['row_count'] }} · {{ __('enterprise_bi_ui.processed') }} {{ $export['processed_rows'] }} · {{ __('enterprise_bi_ui.chunks') }} {{ $export['chunks_completed'] }}</span></div>
            <span class="bi-pill {{ $export['failed']?'off':'' }}">{{ __('enterprise_bi_ui.statuses.'.$export['status']) }}</span>
        </div>
    @empty
        <div class="bi-empty">{{ __('enterprise_bi_ui.no_exports') }}</div>
    @endforelse
    </div>
</article>

<article class="foodex-card bi-panel">
    <div class="bi-head"><div><h2>{{ __('enterprise_bi_ui.schedules_title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.schedules_help') }}</p></div></div>
    <div class="bi-list" style="margin-top:12px">
    @forelse($schedules as $schedule)
        <div class="bi-list-row">
            <div><strong>{{ $schedule['report'] }}</strong><span class="bi-muted">{{ __('enterprise_bi_ui.interval') }} {{ $schedule['interval_minutes'] }} {{ __('enterprise_bi_ui.minutes') }} · {{ __('enterprise_bi_ui.next_run') }} {{ $schedule['next_run_at']??'—' }} · {{ __('enterprise_bi_ui.last_run') }} {{ $schedule['last_run_at']??'—' }}</span></div>
            <span class="bi-pill {{ (!$schedule['enabled']||$schedule['failed'])?'off':'' }}">{{ $schedule['enabled']?__('enterprise_bi_ui.statuses.enabled'):__('enterprise_bi_ui.statuses.disabled') }}</span>
        </div>
    @empty
        <div class="bi-empty">{{ __('enterprise_bi_ui.no_schedules') }}</div>
    @endforelse
    </div>
</article>
</section>

<section class="foodex-card bi-panel">
    <div class="bi-head">
        <div><h2>{{ __('enterprise_bi_ui.exceptions_title') }}</h2><p class="bi-muted">{{ __('enterprise_bi_ui.exceptions_help') }}</p></div>
        <div class="bi-metric">{{ $exceptions['total'] }}</div>
    </div>
    <div class="bi-grid" style="margin-top:12px">
        <div class="foodex-card bi-card"><strong>{{ __('enterprise_bi_ui.failed_exports') }}</strong><span class="bi-metric">{{ $exceptions['failed_exports'] }}</span></div>
        <div class="foodex-card bi-card"><strong>{{ __('enterprise_bi_ui.failed_schedules') }}</strong><span class="bi-metric">{{ $exceptions['failed_schedules'] }}</span></div>
    </div>
</section>
</div>
</main>
</div>
<script>
document.querySelector('[data-bi-run-form]')?.addEventListener('submit',function(){
 const button=this.querySelector('[data-bi-run-button]');
 if(!button)return;
 button.disabled=true;
 button.setAttribute('aria-busy','true');
 button.textContent=button.dataset.loadingLabel||button.textContent;
});
</script>
</body>
</html>
