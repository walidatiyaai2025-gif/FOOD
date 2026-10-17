<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ __('whatsapp.inbox.page_title') }}</title>
@include('admin._brand-components')
<style>
.wa-shell{display:grid;gap:14px}.wa-controls-row{display:grid;grid-template-columns:minmax(0,1fr);gap:14px;align-items:start}.wa-toolbar,.wa-profile{padding:16px}
.wa-workspace{display:grid;grid-template-columns:minmax(300px,.95fr) minmax(360px,1.6fr) minmax(280px,1fr);gap:14px;min-height:68vh}
.wa-pane{padding:0;overflow:hidden}.wa-pane-head{padding:14px 16px;border-bottom:1px solid var(--foodex-border);display:flex;justify-content:space-between;gap:10px;align-items:center}.wa-pane-body{padding:14px 16px}
.wa-conversation-pane{min-width:0;display:flex;flex-direction:column}.wa-conversation-head{padding:17px 16px 12px;align-items:flex-start}.wa-conversation-title{display:grid;gap:3px;min-width:0}.wa-conversation-title strong{font-size:16px;line-height:1.35}.wa-conversation-title small{color:var(--foodex-muted);font-size:11px;line-height:1.55}.wa-conversation-count{display:grid;place-items:center;min-width:28px;height:28px;padding:0 8px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:11px;font-weight:800}
.wa-conversation-filters{padding:11px 12px 10px;border-bottom:1px solid var(--foodex-border);background:var(--foodex-surface)}.wa-conversation-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:center}.wa-search-field{position:relative;display:block;min-width:0}.wa-search-field input{width:100%;height:42px;padding-inline-start:36px}.wa-search-icon{position:absolute;inset-inline-start:12px;top:50%;transform:translateY(-50%);color:var(--foodex-muted);pointer-events:none}.wa-status-tabs{display:flex;gap:6px;overflow-x:auto;padding:10px 1px 1px;scrollbar-width:thin}.wa-status-tab{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 10px;border:1px solid var(--foodex-border);border-radius:999px;text-decoration:none;color:var(--foodex-muted);font-size:11px;font-weight:800;white-space:nowrap}.wa-status-tab:hover,.wa-status-tab.active{border-color:var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-filter-more{margin-top:8px}.wa-filter-more summary{cursor:pointer;color:var(--foodex-green-dark);font-size:11px;font-weight:800;list-style-position:inside}.wa-filter-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px}.wa-filter-grid label{display:grid;gap:4px;min-width:0;color:var(--foodex-muted);font-size:11px}.wa-filter-grid select,.wa-filter-grid input{width:100%;min-width:0}.wa-filter-actions{display:flex;align-items:flex-end;justify-content:flex-end}
.wa-list{display:grid;min-height:0}.wa-row{display:grid;grid-template-columns:40px minmax(0,1fr);gap:10px;padding:12px 14px;border-bottom:1px solid var(--foodex-border);text-decoration:none;color:inherit;min-width:0}.wa-row:hover,.wa-row.active{background:var(--foodex-green-soft)}.wa-row.active{box-shadow:inset 3px 0 0 var(--foodex-green-dark)}[dir="rtl"] .wa-row.active{box-shadow:inset -3px 0 0 var(--foodex-green-dark)}.wa-avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-background);color:var(--foodex-green-dark);font-weight:900;border:1px solid var(--foodex-border)}.wa-row.active .wa-avatar{background:var(--foodex-green-dark);color:var(--foodex-surface);border-color:var(--foodex-green-dark)}.wa-row-main{display:grid;gap:5px;min-width:0}.wa-row-top,.wa-row-preview,.wa-row-bottom{display:flex;justify-content:space-between;gap:8px;align-items:center;min-width:0}.wa-row-name,.wa-preview-text,.wa-row-meta{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.wa-row-name{font-size:13px}.wa-preview-text{color:var(--foodex-muted);font-size:11px}.wa-row-time{color:var(--foodex-muted);font-size:10px;white-space:nowrap;font-variant-numeric:tabular-nums}.wa-row-meta{color:var(--foodex-muted);font-size:10px}.wa-channel{display:inline-flex;align-items:center;gap:4px;color:var(--foodex-muted);font-size:10px;white-space:nowrap}.wa-channel-mark{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:9px;font-weight:900}.wa-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;background:var(--foodex-background)}.wa-badge.wait{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.wa-badge.open{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-badge.resolved{background:var(--foodex-background);color:var(--foodex-muted)}.wa-unread{display:grid;place-items:center;min-width:20px;height:20px;padding:0 5px;border-radius:99px;background:var(--foodex-green-dark);color:var(--foodex-surface);font-size:10px;font-weight:900}.wa-list-pagination{margin-top:auto}
.wa-timeline{display:grid;gap:10px;max-height:46vh;overflow:auto;padding:16px}.wa-message{max-width:82%;padding:11px 13px;border-radius:14px;background:var(--foodex-background)}.wa-message.outbound{margin-inline-start:auto;background:var(--foodex-green-soft)}.wa-message.note{max-width:100%;background:var(--foodex-orange-soft);border:1px dashed var(--foodex-orange)}.wa-message.smart-ordering{max-width:100%;border:1px solid var(--foodex-border);background:var(--foodex-surface);box-shadow:0 8px 22px rgba(15,23,42,.06)}.wa-message small{display:block;color:var(--foodex-muted);margin-top:5px}
.wa-attachment{display:grid;gap:7px;margin-bottom:8px}.wa-attachment img,.wa-attachment video{display:block;max-width:min(360px,100%);max-height:320px;border-radius:12px;border:1px solid var(--foodex-border);background:var(--foodex-surface)}.wa-attachment audio{width:min(360px,100%)}.wa-attachment-link{display:inline-flex;align-items:center;gap:8px;width:max-content;max-width:100%;padding:9px 11px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-surface);color:var(--foodex-green-dark);text-decoration:none;font-weight:700}.wa-attachment-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}
.wa-compose{border-top:1px solid var(--foodex-border);padding:14px 16px;display:grid;gap:10px}.wa-compose textarea{width:100%;min-height:82px}.wa-compose-file{display:grid;gap:5px;margin:9px 0}.wa-compose-file input{width:100%;padding:9px;border:1px dashed var(--foodex-border);border-radius:10px;background:var(--foodex-surface)}.wa-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
.wa-call-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 12px;margin-bottom:10px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-background)}.wa-call-status{font-size:12px;color:var(--foodex-muted);flex:1;min-width:160px}.wa-call-live{color:var(--foodex-green-dark);font-weight:800}.wa-call-danger{background:var(--foodex-red)!important;color:var(--foodex-surface)!important;border-color:var(--foodex-red)!important}
.wa-call-overlay{position:fixed;inset:0;background:rgba(15,23,42,.46);display:none;align-items:center;justify-content:center;z-index:99999;padding:18px}.wa-call-overlay.show{display:flex}.wa-call-card{width:min(440px,100%);background:var(--foodex-surface);border-radius:18px;padding:24px;box-shadow:0 22px 70px rgba(15,23,42,.28);text-align:center}.wa-call-icon{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:var(--foodex-green-soft);font-size:32px}.wa-call-card h2{margin:0 0 7px}.wa-call-card p{color:var(--foodex-muted);margin:0 0 18px}.wa-call-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}.wa-inline label{display:grid;gap:4px;min-width:130px}
.wa-360{display:grid;gap:12px}.wa-card{border:1px solid var(--foodex-border);border-radius:12px;padding:12px}.wa-card h3{margin:0 0 9px;font-size:14px}.wa-card p{margin:4px 0;font-size:13px}.wa-so-order{display:grid;gap:8px}.wa-so-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.wa-so-items{display:grid;gap:4px;padding:8px 0;border-block:1px solid var(--foodex-border)}.wa-so-items span{font-size:12px}.wa-so-action{display:inline-flex;justify-content:center;text-decoration:none}.wa-mini{width:100%;border-collapse:collapse}.wa-mini td{padding:6px 0;border-bottom:1px solid var(--foodex-border);font-size:12px}.wa-tags{display:flex;gap:6px;flex-wrap:wrap}.wa-tag{padding:4px 8px;background:var(--foodex-background);border-radius:999px;font-size:11px;font-weight:700}
.wa-empty{padding:32px;text-align:center;color:var(--foodex-muted)}.wa-status{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr)) auto;gap:8px;align-items:end}

/* #2017 center conversation pane — golden thread/composer contract. */
.wa-thread-pane{--wa-green:var(--foodex-green);--wa-green-dark:var(--foodex-action-green);--wa-border:var(--foodex-border);--wa-muted:var(--foodex-muted);--wa-ink:var(--foodex-ink);display:flex;flex-direction:column;min-height:68vh;background:var(--foodex-surface)}
.wa-thread-pane .wa-avatar{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-size:18px;font-weight:800;color:var(--foodex-muted);background:color-mix(in srgb,var(--foodex-blue) 12%,var(--foodex-surface));flex:0 0 auto}
.wa-thread-pane .wa-presence{display:inline-flex;align-items:center;gap:6px;color:var(--wa-green-dark);font-size:12px;font-weight:700}.wa-thread-pane .wa-presence::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green-bright)}
.wa-thread-pane .wa-whatsapp{color:var(--foodex-green-bright);font-weight:900;font-size:17px}
.wa-thread-head{min-height:95px;padding:16px 22px;border-bottom:1px solid var(--wa-border);display:flex;align-items:center;justify-content:space-between;gap:14px}.wa-thread-customer{display:flex;align-items:center;gap:12px;min-width:0}.wa-thread-identity{min-width:0}.wa-thread-name-line{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.wa-thread-name{font-size:17px;font-weight:900}.wa-thread-subline{display:flex;gap:8px;align-items:center;margin-top:5px;font-size:12px;color:var(--foodex-muted);direction:ltr;justify-content:flex-end}html[dir=ltr] .wa-thread-subline{justify-content:flex-start}.wa-thread-actions{display:flex;align-items:center;gap:7px}
.wa-thread-pane .wa-icon-button{width:38px;height:38px;border:1px solid var(--wa-border);border-radius:8px;background:var(--foodex-surface);color:var(--foodex-muted);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none}.wa-thread-pane .wa-icon-button:hover{background:var(--foodex-background)}.wa-thread-pane .wa-icon-button:focus-visible,.wa-thread-pane .wa-compose-tab:focus-visible,.wa-thread-pane .wa-send:focus-visible{outline:3px solid color-mix(in srgb,var(--foodex-green) 22%,transparent);outline-offset:2px}
.wa-thread-pane .wa-call-tools{display:flex;align-items:center;gap:7px;padding:0;margin:0;border:0;background:transparent}.wa-thread-pane .wa-call-status{font-size:11px;color:var(--wa-muted);max-width:160px;min-width:0}.wa-thread-pane .wa-call-live{color:var(--wa-green-dark);font-weight:800}.wa-thread-pane .wa-call-danger{color:var(--foodex-danger-text)!important;background:var(--foodex-surface)!important;border-color:var(--wa-border)!important}
.wa-thread-pane .wa-utility{position:relative}.wa-thread-pane .wa-utility>summary{list-style:none}.wa-thread-pane .wa-utility>summary::-webkit-details-marker{display:none}.wa-thread-pane .wa-utility-menu{position:absolute;z-index:40;inset-inline-end:0;top:44px;width:220px;max-width:calc(100vw - 32px);background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08);padding:12px}.wa-thread-pane .wa-utility-menu form{display:grid;gap:8px}
.wa-thread-meta{min-height:72px;padding:10px 20px;border-bottom:1px solid var(--wa-border);display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;background:var(--foodex-surface)}.wa-meta-cell{min-width:0;padding-inline:8px;border-inline-end:1px solid var(--wa-border)}.wa-meta-cell:last-child{border-inline-end:0}.wa-meta-label{font-size:10px;color:var(--foodex-muted);margin-bottom:6px}.wa-meta-value{font-size:12px;font-weight:800;display:flex;align-items:center;gap:6px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wa-meta-chip{padding:4px 8px;border-radius:999px;background:var(--foodex-background);color:var(--foodex-muted);font-size:10px;font-weight:800}.wa-meta-chip.green{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-meta-details{position:relative}.wa-meta-details>summary{list-style:none;cursor:pointer}.wa-meta-details>summary::-webkit-details-marker{display:none}.wa-meta-popover{position:absolute;z-index:35;top:34px;inset-inline-start:0;width:340px;max-width:80vw;padding:12px;background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08)}.wa-meta-popover form{display:grid;gap:8px;margin-bottom:10px}.wa-meta-popover form:last-child{margin-bottom:0}.wa-meta-popover label{display:grid;gap:4px;font-size:11px;color:var(--wa-muted)}.wa-meta-popover select,.wa-meta-popover input{min-height:38px;border:1px solid var(--wa-border);border-radius:8px;padding:6px 8px}
.wa-thread-pane .wa-timeline{flex:1;min-height:0;max-height:none;overflow:auto;padding:28px 32px 20px;background:var(--foodex-background);display:flex;flex-direction:column;gap:12px;overscroll-behavior:contain}.wa-day-separator{align-self:center;padding:7px 18px;border-radius:999px;background:var(--foodex-surface);border:1px solid var(--wa-border);color:var(--foodex-muted);font-size:11px;margin:3px 0 8px}.wa-thread-pane .wa-message{max-width:68%;border:1px solid var(--wa-border);background:var(--foodex-surface);border-radius:13px;padding:11px 14px;font-size:13px;line-height:1.65;box-shadow:0 2px 7px rgba(15,23,42,.025)}html[dir=rtl] .wa-thread-pane .wa-message.inbound{align-self:flex-end}html[dir=rtl] .wa-thread-pane .wa-message.outbound{align-self:flex-start}html[dir=ltr] .wa-thread-pane .wa-message.inbound{align-self:flex-start}html[dir=ltr] .wa-thread-pane .wa-message.outbound{align-self:flex-end}.wa-thread-pane .wa-message.outbound{margin-inline-start:0;background:var(--foodex-green-soft);border-color:color-mix(in srgb,var(--foodex-green) 18%,var(--foodex-surface))}.wa-thread-pane .wa-message.note{align-self:center!important;max-width:88%;background:var(--foodex-orange-soft);border-style:dashed;border-color:var(--foodex-orange)}.wa-thread-pane .wa-message.smart-ordering{max-width:80%;background:var(--foodex-green-soft);border-color:color-mix(in srgb,var(--foodex-green) 16%,var(--foodex-surface))}.wa-thread-pane .wa-message small{display:block;margin-top:5px;color:var(--foodex-muted);font-size:10px}.wa-thread-pane .wa-message.outbound small{color:var(--foodex-green-dark)}
.wa-thread-pane .wa-attachment{display:grid;gap:6px;margin-bottom:7px}.wa-thread-pane .wa-attachment img,.wa-thread-pane .wa-attachment video{display:block;max-width:min(340px,100%);max-height:250px;border-radius:10px;border:1px solid var(--wa-border)}.wa-thread-pane .wa-attachment audio{max-width:100%}.wa-thread-pane .wa-attachment-link{display:inline-flex;gap:6px;color:var(--wa-green-dark);font-weight:700;text-decoration:none}.wa-thread-pane .wa-so-action{display:inline-flex;margin-top:7px}
.wa-compose-wrap{border-top:1px solid var(--wa-border);background:var(--foodex-surface);padding:0 18px 14px}.wa-compose-tabs{display:flex;align-items:center;border-bottom:1px solid var(--wa-border);min-height:48px}.wa-compose-tab{min-height:44px;border:0;background:transparent;padding:0 16px;color:var(--foodex-muted);font-size:12px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}.wa-compose-tab+.wa-compose-tab,.wa-compose-tabs details{border-inline-start:1px solid var(--wa-border)}.wa-compose-tabs details{position:relative}.wa-compose-tabs summary{list-style:none;cursor:pointer}.wa-compose-tabs summary::-webkit-details-marker{display:none}.wa-order-menu{position:absolute;z-index:30;bottom:44px;inset-inline-start:0;width:300px;max-width:75vw;background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.08);padding:10px}.wa-order-ref{width:100%;border:0;background:var(--foodex-surface);text-align:start;padding:9px;border-radius:7px;cursor:pointer}.wa-order-ref:hover{background:var(--foodex-background)}.wa-quick-hint{display:none;padding:8px 12px;color:var(--foodex-muted);font-size:11px}.wa-quick-hint.show{display:block}.wa-reply-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:end;padding-top:11px}.wa-reply-input{border:0;resize:none;min-height:64px;max-height:140px;padding:8px 4px;outline:none;background:var(--foodex-surface);width:100%;font-size:13px}.wa-compose-bottom{display:flex;align-items:center;gap:10px;margin-top:4px}.wa-attach-label{width:36px;height:36px;border-radius:8px;display:grid;place-items:center;cursor:pointer;color:var(--foodex-muted)}.wa-attach-label input{display:none}.wa-send{height:40px;min-width:104px;border:0;border-radius:8px;background:var(--wa-green);color:var(--foodex-surface);font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer}.wa-send:disabled{opacity:.6;cursor:wait}.wa-note-form{display:none;grid-template-columns:minmax(0,1fr) auto;gap:9px;padding:10px 0 0}.wa-note-form.show{display:grid}.wa-note-form textarea{min-height:54px;border:1px solid var(--wa-border);border-radius:9px;padding:9px;resize:vertical}.wa-thread-pane .wa-secondary{min-height:40px;border:1px solid color-mix(in srgb,var(--foodex-green) 24%,var(--foodex-surface));background:var(--foodex-green-soft);color:var(--wa-green-dark);border-radius:8px;padding:7px 12px;font-weight:800;cursor:pointer}
@media(max-width:760px){.wa-thread-meta{grid-template-columns:repeat(2,minmax(0,1fr));padding:9px}.wa-thread-pane .wa-timeline{min-height:390px;padding:18px 12px}.wa-thread-pane .wa-message{max-width:90%}.wa-compose-wrap{padding-inline:10px}.wa-compose-tabs{overflow-x:auto}.wa-compose-tab{white-space:nowrap;padding-inline:11px}.wa-meta-popover{position:fixed;top:88px;inset-inline:16px;width:auto;max-width:none}.wa-reply-form,.wa-note-form{grid-template-columns:1fr}.wa-send{width:100%}}

@media(max-width:1180px){.wa-workspace{grid-template-columns:300px 1fr}.wa-context{grid-column:1/-1}}
@media(max-width:900px){.wa-controls-row{grid-template-columns:1fr}}
@media(max-width:760px){.wa-status{grid-template-columns:1fr}.wa-workspace{grid-template-columns:1fr}.wa-context{grid-column:auto}.wa-message{max-width:94%}.wa-filter-grid{grid-template-columns:1fr}.wa-conversation-search{grid-template-columns:minmax(0,1fr) auto}}
</style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">{{ __('whatsapp.inbox.subtitle') }}</span>
                <h1>{{ __('whatsapp.inbox.heading') }}</h1>
                <p>{{ __('whatsapp.inbox.intro') }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        @if(session('status'))<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px;color:var(--foodex-red)"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="wa-shell">
            <div class="wa-workspace">
                <section class="foodex-card wa-pane wa-conversation-pane">
                    @php
                        $activeStatus = (string) ($filters['status'] ?? '');
                        $statusTabs = [
                            '' => __('whatsapp.inbox.all'),
                            'OPEN' => __('whatsapp.inbox.conversation_statuses.OPEN'),
                            'WAITING_FOR_AGENT' => __('whatsapp.inbox.waiting_for_reply'),
                            'CLOSED' => __('whatsapp.inbox.conversation_statuses.CLOSED'),
                        ];
                        $hasAdvancedFilters = ($filters['assignment'] ?? 'all') !== 'all'
                            || filled($filters['store_id'] ?? null)
                            || filled($filters['channel'] ?? null)
                            || in_array($activeStatus, ['WAITING_FOR_CUSTOMER', 'RESOLVED'], true);
                    @endphp
                    <div class="wa-pane-head wa-conversation-head">
                        <div class="wa-conversation-title">
                            <strong>{{ __('whatsapp.inbox.heading') }}</strong>
                            <small>{{ __('whatsapp.inbox.conversation_helper') }}</small>
                        </div>
                        <span class="wa-conversation-count" aria-label="{{ __('whatsapp.inbox.conversations') }}">{{ $conversations->total() }}</span>
                    </div>
                    <div class="wa-conversation-filters">
                        <form method="get">
                            <div class="wa-conversation-search">
                                <label class="wa-search-field">
                                    <span class="wa-search-icon" aria-hidden="true">⌕</span>
                                    <input name="q" value="{{ $filters['q']??'' }}" placeholder="{{ __('whatsapp.inbox.search_placeholder') }}" aria-label="{{ __('whatsapp.inbox.search') }}">
                                </label>
                                <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.search') }}</button>
                            </div>
                            <nav class="wa-status-tabs" aria-label="{{ __('whatsapp.inbox.status') }}">
                                @foreach($statusTabs as $statusValue => $statusLabel)
                                    @php
                                        $statusQuery = request()->except('page', 'conversation', 'status');
                                        if ($statusValue !== '') {
                                            $statusQuery['status'] = $statusValue;
                                        }
                                    @endphp
                                    <a class="wa-status-tab {{ $activeStatus === $statusValue ? 'active' : '' }}"
                                       href="{{ route('admin.whatsapp-inbox.index', $statusQuery) }}"
                                       @if($activeStatus === $statusValue) aria-current="page" @endif>{{ $statusLabel }}</a>
                                @endforeach
                            </nav>
                            <details class="wa-filter-more" @if($hasAdvancedFilters) open @endif>
                                <summary>{{ __('whatsapp.inbox.more_filters') }}</summary>
                                <div class="wa-filter-grid">
                                    <label>{{ __('whatsapp.inbox.status') }}
                                        <select name="status">
                                            <option value="">{{ __('whatsapp.inbox.all') }}</option>
                                            @foreach(['OPEN','WAITING_FOR_AGENT','WAITING_FOR_CUSTOMER','RESOLVED','CLOSED'] as $status)
                                                <option value="{{ $status }}" @selected($activeStatus===$status)>{{ __('whatsapp.inbox.conversation_statuses.'.$status) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>{{ __('whatsapp.inbox.assignment') }}
                                        <select name="assignment">@foreach(['all','mine','unassigned'] as $value)<option value="{{ $value }}" @selected(($filters['assignment']??'all')===$value)>{{ __('whatsapp.inbox.assignment_filters.'.$value) }}</option>@endforeach</select>
                                    </label>
                                    <label>{{ __('whatsapp.inbox.store') }}
                                        <select name="store_id"><option value="">{{ __('whatsapp.inbox.all_authorized') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select>
                                    </label>
                                    <label>{{ __('whatsapp.inbox.channel') }}
                                        <select name="channel">
                                            <option value="">{{ __('whatsapp.inbox.all_channels') }}</option>
                                            @foreach(['whatsapp','b2b','b2c','unknown'] as $channel)
                                                <option value="{{ $channel }}" @selected(($filters['channel']??'')===$channel)>{{ __('whatsapp.inbox.channels.'.$channel) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <div class="wa-filter-actions">
                                        <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
                                    </div>
                                </div>
                            </details>
                        </form>
                    </div>
                    <div class="wa-list">
                    @forelse($conversations as $row)
                        @php
                            $active = $selected && (int) $selected->id === (int) $row->id;
                            $customerName = $row->customer_name ?: __('whatsapp.inbox.unknown_customer');
                            $preview = trim((string) ($row->last_message_body ?? ''));
                            if ($preview === '') {
                                $preview = filled($row->last_message_type ?? null)
                                    ? __('whatsapp.inbox.attachment')
                                    : __('whatsapp.inbox.no_messages');
                            }
                            $lastMoment = \Illuminate\Support\Carbon::parse($row->last_message_at ?? $row->updated_at);
                            $lastLabel = $lastMoment->isToday() ? $lastMoment->format('H:i') : $lastMoment->format('d/m');
                            $channelKey = strtolower((string) $row->channel);
                            $channelTranslationKey = 'whatsapp.inbox.channels.'.$channelKey;
                            $channelLabel = \Illuminate\Support\Facades\Lang::has($channelTranslationKey)
                                ? __($channelTranslationKey)
                                : \Illuminate\Support\Str::upper($channelKey);
                            $initial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customerName, 0, 1));
                        @endphp
                        <a class="wa-row {{ $active?'active':'' }}"
                           href="{{ route('admin.whatsapp-inbox.index', array_merge(request()->except('page','conversation'),['conversation'=>$row->id])) }}"
                           @if($active) aria-current="page" @endif>
                            <span class="wa-avatar" aria-hidden="true">{{ $initial }}</span>
                            <span class="wa-row-main">
                                <span class="wa-row-top">
                                    <strong class="wa-row-name">{{ $customerName }}</strong>
                                    <time class="wa-row-time" datetime="{{ $lastMoment->toIso8601String() }}">{{ $lastLabel }}</time>
                                </span>
                                <span class="wa-row-preview">
                                    <span class="wa-preview-text">{{ \Illuminate\Support\Str::limit($preview, 76) }}</span>
                                    @if($row->unread_count>0)<span class="wa-unread">{{ $row->unread_count }}</span>@endif
                                </span>
                                <span class="wa-row-bottom">
                                    <span class="wa-row-meta">{{ $row->store_name ?: '-' }} · {{ $row->agent_name ?: __('whatsapp.inbox.unassigned') }}</span>
                                    <span class="wa-channel"><span class="wa-channel-mark" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($channelLabel, 0, 1)) }}</span>{{ $channelLabel }}</span>
                                </span>
                            </span>
                        </a>
                    @empty
                        <div class="wa-empty">{{ __('whatsapp.inbox.no_conversations') }}</div>
                    @endforelse
                    </div>
                    <div class="wa-pane-body wa-list-pagination">{{ $conversations->links() }}</div>
                </section>

                <section class="foodex-card wa-pane wa-thread-pane" data-wa-pane="active-conversation">
                <?php if($selected): ?>
                    <?php $selectedName=$selected->customer_name ?: __('whatsapp.inbox.customer'); ?>
                    <?php $selectedInitial=mb_substr((string)$selectedName,0,2); ?>
                    <div class="wa-thread-head">
                        <div class="wa-thread-customer">
                            <div class="wa-avatar lg blue">{{ $selectedInitial }}</div>
                            <div class="wa-thread-identity">
                                <div class="wa-thread-name-line"><span class="wa-thread-name">{{ $selectedName }}</span><span class="wa-presence">{{ __('whatsapp.inbox.conversation_statuses.'.$selected->status) }}</span></div>
                                <div class="wa-thread-subline"><span>{{ $selected->customer_phone ?: ($selected->external_participant_phone ?: $selected->customer_email) }}</span><span class="wa-whatsapp">◉</span></div>
                            </div>
                        </div>
                        <div class="wa-thread-actions">
                            <?php if($callsEnabled): ?>
                            <div class="wa-call-tools" id="wa-call-tools" data-conversation="{{ $selected->id }}" data-can-dial="{{ $canDialCalls ? '1' : '0' }}" data-can-manage="{{ $canManageCalls ? '1' : '0' }}">
                                <?php if($canDialCalls): ?>
                                    <button type="button" class="wa-icon-button" id="wa-call-start" aria-label="{{ __('whatsapp.inbox.make_call') }}">☎</button>
                                    <button type="button" class="wa-icon-button" id="wa-call-permission" aria-label="{{ __('whatsapp.inbox.request_call_permission') }}">✓</button>
                                <?php endif; ?>
                                <button type="button" class="wa-icon-button wa-call-danger" id="wa-call-hangup" hidden aria-label="{{ __('whatsapp.inbox.hang_up') }}">■</button>
                                <span class="wa-call-status" id="wa-call-status">{{ $inboundCallsEnabled ? '' : __('whatsapp.inbox.calls_disabled') }}</span>
                            </div>
                            <?php endif; ?>
                            <details class="wa-utility">
                                <summary class="wa-icon-button" aria-label="{{ __('whatsapp.inbox.thread_ui.more') }}">•••</summary>
                                <div class="wa-utility-menu">
                                    <form method="post" action="{{ route('admin.whatsapp-inbox.transition',['conversation'=>$selected->id]) }}" data-wa-mutation>@csrf @method('PATCH')<input type="hidden" name="action" value="{{ $transitionAction }}"><button class="wa-secondary" type="submit">{{ $selectedIsResolved?__('whatsapp.inbox.reopen'):__('whatsapp.inbox.resolve') }}</button></form>
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="wa-thread-meta">
                        <div class="wa-meta-cell"><div class="wa-meta-label">{{ __('whatsapp.inbox.thread_ui.conversation_classification') }}</div><div class="wa-meta-value">{{ __('whatsapp.inbox.channels.'.strtolower((string)$selected->channel)) }}⌄</div></div>
                        <div class="wa-meta-cell">
                            <div class="wa-meta-label">{{ __('whatsapp.inbox.thread_ui.responsible') }}</div>
                            <details class="wa-meta-details"><summary class="wa-meta-value">{{ $selected->agent_name ?: __('whatsapp.inbox.unassigned') }}⌄</summary>
                                <div class="wa-meta-popover">
                                    <form method="post" action="{{ route('admin.whatsapp-inbox.assign',['conversation'=>$selected->id]) }}" data-wa-mutation>
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="expected_version" value="{{ $selected->lock_version }}">
                                        <label>{{ __('whatsapp.inbox.primary_agent') }}<select name="agent_id" required><option value="">{{ __('whatsapp.inbox.choose') }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((int)$selected->primary_agent_id===$agent->id)>{{ $agent->name }} · {{ $agent->load }}/{{ $agent->capacity }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.backup') }}<select name="backup_agent_id"><option value="">{{ __('whatsapp.inbox.none') }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.reason') }}<input name="reason" maxlength="500"></label>
                                        <button class="wa-secondary" type="submit">{{ __('whatsapp.inbox.assign_reassign') }}</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                        <div class="wa-meta-cell"><div class="wa-meta-label">{{ __('whatsapp.inbox.thread_ui.priority') }}</div><div class="wa-meta-value"><span class="wa-meta-chip green">● {{ ((int)$selected->priority)>1?__('whatsapp.inbox.thread_ui.priority_high'):__('whatsapp.inbox.thread_ui.priority_normal') }}</span></div></div>
                        <div class="wa-meta-cell">
                            <div class="wa-meta-label">{{ __('whatsapp.inbox.thread_ui.tags') }}</div>
                            <details class="wa-meta-details"><summary class="wa-meta-value">
                                <?php if($tags->isEmpty()): ?>
                                    <span class="wa-meta-chip">{{ __('whatsapp.inbox.none') }}</span>
                                <?php else: ?>
                                    @foreach($tags->take(2) as $tag)
                                        <span class="wa-meta-chip">{{ $tag->name }}</span>
                                    @endforeach
                                <?php endif; ?>
                                <span>＋</span>
                            </summary>
                                <div class="wa-meta-popover">
                                    <form method="post" action="{{ route('admin.whatsapp-inbox.tag',['conversation'=>$selected->id]) }}" data-wa-mutation>@csrf
                                        <label>{{ __('whatsapp.inbox.tag') }}<select name="tag_id" required><option value="">{{ __('whatsapp.inbox.choose') }}</option>@foreach($allTags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.thread_ui.manage_assignment') }}<select name="action"><option value="attach">{{ __('whatsapp.inbox.attach') }}</option><option value="detach">{{ __('whatsapp.inbox.detach') }}</option></select></label>
                                        <button class="wa-secondary" type="submit">{{ __('whatsapp.inbox.update_tag') }}</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="wa-timeline" id="wa-conversation-timeline" data-conversation="{{ $selected->id }}">
                        <?php $lastDay=null; ?>
                        <?php if($timeline->isEmpty()): ?>
                            <div class="wa-empty">{{ __('whatsapp.inbox.no_messages') }}</div>
                        <?php else: ?>
                        @foreach($timeline as $item)
                            <?php $dayKey=$item['timestamp']?\Illuminate\Support\Carbon::parse($item['timestamp'])->toDateString():''; ?>
                            <?php if($dayKey!==$lastDay): ?>
                                <div class="wa-day-separator">{{ $item['timestamp']?\Illuminate\Support\Carbon::parse($item['timestamp'])->translatedFormat('d M Y'):__('whatsapp.inbox.thread_ui.today') }}</div>
                                <?php $lastDay=$dayKey; ?>
                            <?php endif; ?>
                            <article class="wa-message {{ $item['kind']==='note'?'note':($item['kind']==='smart_ordering'?'smart-ordering':$item['direction']) }}">
                                <?php if($item['kind']==='note'): ?>
                                    <strong>🔒 {{ __('whatsapp.inbox.internal_note') }}</strong>
                                <?php endif; ?>
                                <?php if($item['kind']==='smart_ordering'): ?>
                                    <strong>🧾 {{ __('whatsapp.smart_ordering.timeline_actor') }}</strong>
                                <?php endif; ?>
                                <?php if(!empty($item['attachment'])): ?>
                                    <?php $attachment=$item['attachment']; ?>
                                    <div class="wa-attachment">
                                        <?php if(in_array($attachment['media_type'],['image','sticker'],true)): ?>
                                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                <img src="{{ $attachment['url'] }}" alt="{{ $attachment['filename'] ?: __('whatsapp.inbox.attachment') }}" loading="lazy">
                                            </a>
                                        <?php elseif($attachment['media_type']==='video'): ?>
                                            <video controls preload="metadata">
                                                <source src="{{ $attachment['url'] }}" <?php if($attachment['mime_type']): ?> type="{{ $attachment['mime_type'] }}" <?php endif; ?>>
                                            </video>
                                        <?php elseif($attachment['media_type']==='audio'): ?>
                                            <audio controls preload="metadata">
                                                <source src="{{ $attachment['url'] }}" <?php if($attachment['mime_type']): ?> type="{{ $attachment['mime_type'] }}" <?php endif; ?>>
                                            </audio>
                                        <?php else: ?>
                                            <a class="wa-attachment-link" href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                                                📎 {{ $attachment['filename'] ?: __('whatsapp.inbox.attachment') }}
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if(filled($item['body'])): ?>
                                    <div>{{ $item['body'] }}</div>
                                <?php elseif(empty($item['attachment'])): ?>
                                    <div>{{ __('whatsapp.inbox.attachment') }}</div>
                                <?php endif; ?>
                                <?php if($item['kind']==='smart_ordering' && !empty($item['smart_ordering']['order_url'])): ?>
                                    <a class="wa-so-action" href="{{ $item['smart_ordering']['order_url'] }}">{{ __('whatsapp.smart_ordering.open_order') }}</a>
                                <?php endif; ?>
                                <small>
                                    {{ $item['actor'] }} · {{ $item['timestamp']?\Illuminate\Support\Carbon::parse($item['timestamp'])->format('H:i'):'' }}
                                    <?php if($item['delivery_status']): ?>
                                        · {{ __('whatsapp.inbox.delivery_statuses.'.$item['delivery_status']) }}
                                    <?php endif; ?>
                                </small>
                            </article>
                        @endforeach
                        <?php endif; ?>
                    </div>

                    <div class="wa-compose-wrap">
                        <div class="wa-compose-tabs">
                            <button class="wa-compose-tab" type="button" data-wa-quick-toggle>⚡ {{ __('whatsapp.inbox.thread_ui.quick_replies') }}</button>
                            <details>
                                <summary class="wa-compose-tab">▣ {{ __('whatsapp.inbox.thread_ui.attach_order') }}</summary>
                                <div class="wa-order-menu">
                                    <?php if($customer360 && $customer360['orders']->isNotEmpty()): ?>
                                        @foreach($customer360['orders'] as $order)
                                            <button type="button" class="wa-order-ref" data-wa-order-ref="#{{ $order->order_number }}">
                                                #{{ $order->order_number }} · {{ number_format((float)$order->grand_total,0) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$order->currency)) }}
                                            </button>
                                        @endforeach
                                    <?php else: ?>
                                        <div class="wa-empty">{{ __('whatsapp.inbox.none') }}</div>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <button class="wa-compose-tab" type="button" data-wa-note-toggle>▱ {{ __('whatsapp.inbox.internal_note') }}</button>
                        </div>
                        <div class="wa-quick-hint" data-wa-quick-hint>{{ __('whatsapp.inbox.thread_ui.no_quick_replies') }}</div>
                        <form class="wa-reply-form" method="post" enctype="multipart/form-data" action="{{ route('admin.whatsapp-inbox.reply',['conversation'=>$selected->id]) }}" data-wa-mutation>
                            @csrf
                            <div>
                                <textarea class="wa-reply-input" name="body" maxlength="5000" placeholder="{{ __('whatsapp.inbox.thread_ui.write_message') }}" data-wa-reply></textarea>
                                <div class="wa-compose-bottom"><span aria-hidden="true">☺</span><label class="wa-attach-label" aria-label="{{ __('whatsapp.inbox.send_attachment') }}">⌕<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp4,.3gp,.mp3,.m4a,.aac,.amr,.ogg,.opus"></label></div>
                            </div>
                            <button class="wa-send" type="submit">➤ {{ __('whatsapp.inbox.queue_transport') }}</button>
                        </form>
                        <form class="wa-note-form" method="post" action="{{ route('admin.whatsapp-inbox.note',['conversation'=>$selected->id]) }}" data-wa-note-form data-wa-mutation>@csrf
                            <textarea name="body" required maxlength="5000" placeholder="{{ __('whatsapp.inbox.internal_note_only') }}"></textarea>
                            <button class="wa-secondary" type="submit">{{ __('whatsapp.inbox.save_note') }}</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="wa-empty">{{ __('whatsapp.inbox.choose_conversation') }}</div>
                <?php endif; ?>
            </section>

                <section class="foodex-card wa-pane wa-context">
                    <div class="wa-pane-head"><strong>{{ __('whatsapp.inbox.customer_360') }}</strong></div>
                    <div class="wa-pane-body wa-360">
                    @if(!empty($smartOrderingContext['orders']))
                        <div class="wa-card">
                            <h3>{{ __('whatsapp.smart_ordering.orders_title') }}</h3>
                            @foreach($smartOrderingContext['orders'] as $smartOrder)
                                @php($so=$smartOrder['snapshot'] ?? [])
                                <div class="wa-so-order">
                                    <div class="wa-so-head">
                                        <div>
                                            <strong>{{ data_get($so,'order_number','#'.$smartOrder['order_id']) }}</strong>
                                            <p>{{ __('whatsapp.smart_ordering.branch') }}: {{ app()->getLocale()==='ar' ? (data_get($so,'branch.name_ar') ?: data_get($so,'branch.name_en','-')) : (data_get($so,'branch.name_en') ?: data_get($so,'branch.name_ar','-')) }}</p>
                                        </div>
                                        <span class="wa-badge">{{ __('whatsapp.smart_ordering.statuses.'.strtolower((string)$smartOrder['status'])) }}</span>
                                    </div>
                                    <p>{{ __('whatsapp.smart_ordering.fulfillment') }}: {{ __('whatsapp.smart_ordering.modes.'.strtolower((string)$smartOrder['fulfillment_mode'])) }}</p>
                                    @if(data_get($so,'scheduled_for'))<p>{{ __('whatsapp.smart_ordering.scheduled') }}: {{ data_get($so,'scheduled_for') }}</p>@endif
                                    @if(data_get($so,'table.label'))<p>{{ __('whatsapp.smart_ordering.table') }}: {{ data_get($so,'table.label') }}</p>@endif
                                    @if(data_get($so,'delivery_address.line1'))<p>{{ __('whatsapp.smart_ordering.delivery_address') }}: {{ data_get($so,'delivery_address.line1') }}</p>@endif
                                    @if(!empty($so['items']))
                                        <div class="wa-so-items">
                                            @foreach($so['items'] as $line)
                                                <span>{{ $line['name'] ?? '-' }} × {{ rtrim(rtrim(number_format((float)($line['quantity'] ?? 0),3),'0'),'.') }} · {{ number_format((float)($line['unit_price'] ?? 0),3) }} {{ data_get($so,'currency','KWD') }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <p><strong>{{ __('whatsapp.smart_ordering.total') }}:</strong> {{ number_format((float)data_get($so,'totals.grand_total',0),3) }} {{ data_get($so,'currency','KWD') }}</p>
                                    @if(data_get($so,'note'))<p>{{ __('whatsapp.smart_ordering.note') }}: {{ data_get($so,'note') }}</p>@endif
                                    <a class="foodex-action-primary wa-so-action" href="{{ $smartOrder['order_url'] }}">{{ __('whatsapp.smart_ordering.open_order') }}</a>
                                </div>
                                @unless($loop->last)
                                    <hr style="border:0;border-top:1px solid var(--foodex-border);margin:12px 0">
                                @endunless
                            @endforeach
                        </div>
                    @endif
                    @if($customer360)
                        @php($customer=$customer360['customer'])
                        <div class="wa-card">
                            <h3>{{ $customer->name }}</h3>
                            <p>{{ $customer->phone ?: '-' }}</p><p>{{ $customer->email }}</p>
                            <p>{{ __('whatsapp.inbox.origin') }}: {{ $customer->origin_channel ? __('whatsapp.inbox.channels.'.strtolower((string)$customer->origin_channel)) : '-' }}</p>
                            <p>{{ __('whatsapp.inbox.store') }}: {{ $customer->originStore?->name ?: '-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.ownership') }}</h3>
                            <p>{{ __('whatsapp.inbox.primary') }}: {{ $customer360['ownership']->primary_agent_name??__('whatsapp.inbox.unassigned') }}</p>
                            <p>{{ __('whatsapp.inbox.backup') }}: {{ $customer360['ownership']->backup_agent_name??'-' }}</p>
                            <p>{{ __('whatsapp.inbox.source') }}: {{ isset($customer360['ownership']->source) ? __('whatsapp.inbox.ownership_sources.'.$customer360['ownership']->source) : '-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.commercial_activity') }}</h3>
                            <p>{{ __('whatsapp.inbox.orders') }}: {{ $customer360['orders_count'] }}</p>
                            <p>{{ __('whatsapp.inbox.order_value') }}: {{ number_format($customer360['orders_value'],3) }}</p>
                            <p>{{ __('whatsapp.inbox.invoices') }}: {{ $customer360['invoices_count'] }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_orders') }}</h3><table class="wa-mini">@forelse($customer360['orders'] as $order)<tr><td>{{ $order->order_number }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$order->status)) }}</td><td>{{ number_format((float)$order->grand_total,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$order->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_invoices') }}</h3><table class="wa-mini">@forelse($customer360['invoices'] as $invoice)<tr><td>{{ $invoice->invoice_number }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$invoice->status)) }}</td><td>{{ number_format((float)$invoice->total,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$invoice->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ __('whatsapp.inbox.recent_payments') }}</h3><table class="wa-mini">@forelse($customer360['payments'] as $payment)<tr><td>{{ __('whatsapp.inbox.payment_providers.'.strtolower((string)$payment->provider)) }}</td><td>{{ __('whatsapp.inbox.status_values.'.strtolower((string)$payment->status)) }}</td><td>{{ number_format((float)$payment->amount,3) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$payment->currency)) }}</td></tr>@empty<tr><td>{{ __('whatsapp.inbox.none') }}</td></tr>@endforelse</table></div>
                    @else
                        <div class="wa-empty">{{ __('whatsapp.inbox.no_customer_identity') }}</div>
                    @endif
                    </div>
                </section>
            </div>

            <div class="wa-controls-row">
                <section class="foodex-card wa-profile">
                    <form method="post" action="{{ route('admin.whatsapp-inbox.status') }}" class="wa-status">
                        @csrf @method('PATCH')
                        <label>{{ __('whatsapp.inbox.my_status') }}
                            <select name="status">
                                @foreach(['ONLINE','BUSY','AWAY','OFFLINE'] as $status)
                                    <option value="{{ $status }}" @selected(($profile->status??'OFFLINE')===$status)>{{ __('whatsapp.inbox.agent_statuses.'.$status) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('whatsapp.inbox.capacity') }}<input type="number" min="1" max="500" name="capacity" value="{{ $profile->capacity??20 }}"></label>
                        <label>{{ __('whatsapp.inbox.accept_new') }}
                            <select name="accepts_new"><option value="1" @selected(($profile->accepts_new??1)==1)>{{ __('whatsapp.inbox.yes') }}</option><option value="0" @selected(($profile->accepts_new??1)==0)>{{ __('whatsapp.inbox.no') }}</option></select>
                        </label>
                        <button class="foodex-action-primary" type="submit">{{ __('whatsapp.inbox.update') }}</button>
                    </form>
                </section>
                
            </div>
        </div>
    </main>
</div>

<script>
(() => {
    const timeline = document.getElementById('wa-conversation-timeline');
    const reply = document.querySelector('[data-wa-reply]');
    const noteForm = document.querySelector('[data-wa-note-form]');
    const quickHint = document.querySelector('[data-wa-quick-hint]');

    const scrollToLatest = () => {
        if (!timeline) return;
        requestAnimationFrame(() => { timeline.scrollTop = timeline.scrollHeight; });
    };
    scrollToLatest();
    window.addEventListener('load', scrollToLatest, {once:true});
    window.addEventListener('pageshow', scrollToLatest);
    if (timeline) {
        new MutationObserver(mutations => {
            if (mutations.some(mutation => mutation.addedNodes.length > 0)) scrollToLatest();
        }).observe(timeline,{childList:true,subtree:true});
        timeline.querySelectorAll('img,video,audio').forEach(media => {
            media.addEventListener('load',scrollToLatest,{once:true});
            media.addEventListener('loadedmetadata',scrollToLatest,{once:true});
        });
    }

    document.querySelector('[data-wa-note-toggle]')?.addEventListener('click', () => {
        noteForm?.classList.toggle('show');
        if (noteForm?.classList.contains('show')) noteForm.querySelector('textarea')?.focus();
    });
    document.querySelector('[data-wa-quick-toggle]')?.addEventListener('click', () => {
        quickHint?.classList.toggle('show');
        reply?.focus();
    });
    document.querySelectorAll('[data-wa-order-ref]').forEach(button => button.addEventListener('click', () => {
        if (!reply) return;
        const ref = button.dataset.waOrderRef || '';
        const prefix = reply.value.trim() === '' ? '' : reply.value.replace(/\s*$/,'') + ' ';
        reply.value = prefix + ref + ' ';
        reply.focus();
        button.closest('details')?.removeAttribute('open');
    }));
    document.querySelectorAll('form[data-wa-mutation]').forEach(form => form.addEventListener('submit', () => {
        if (form.dataset.waSubmitting === '1') return;
        form.dataset.waSubmitting = '1';
        form.querySelectorAll('button[type="submit"],input[type="submit"]').forEach(button => {
            button.disabled = true;
            button.setAttribute('aria-busy','true');
        });
    }));
})();
</script>

@if($callsEnabled)
<div class="wa-call-overlay" id="wa-incoming-call" role="dialog" aria-modal="true" aria-labelledby="wa-incoming-title">
    <div class="wa-call-card">
        <div class="wa-call-icon">☎</div>
        <h2 id="wa-incoming-title">{{ __('whatsapp.inbox.incoming_call') }}</h2>
        <p id="wa-incoming-caller"></p>
        <div class="wa-call-actions">
            @if($canManageCalls)<button type="button" class="foodex-action-primary" id="wa-call-answer">{{ __('whatsapp.inbox.answer_call') }}</button>@endif
            @if($canManageCalls)<button type="button" class="foodex-action-secondary wa-call-danger" id="wa-call-reject">{{ __('whatsapp.inbox.reject_call') }}</button>@endif
        </div>
    </div>
</div>
<audio id="wa-remote-audio" autoplay playsinline></audio>
<script>
(() => {
    const tools = document.getElementById('wa-call-tools');
    const overlay = document.getElementById('wa-incoming-call');
    const remoteAudio = document.getElementById('wa-remote-audio');
    const statusNode = document.getElementById('wa-call-status');
    const startButton = document.getElementById('wa-call-start');
    const permissionButton = document.getElementById('wa-call-permission');
    const answerButton = document.getElementById('wa-call-answer');
    const rejectButton = document.getElementById('wa-call-reject');
    const hangupButton = document.getElementById('wa-call-hangup');
    const callerNode = document.getElementById('wa-incoming-caller');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const feedUrl = @json(route('admin.whatsapp-calls.feed'));
    const actionTemplate = @json(route('admin.whatsapp-calls.action', ['call' => 0]));
    const selectedConversation = tools ? Number(tools.dataset.conversation || 0) : 0;
    const permissionUrl = selectedConversation ? @json($selected ? route('admin.whatsapp-calls.permission', ['conversation'=>$selected->id]) : '') : '';
    const requestPermissionUrl = selectedConversation ? @json($selected ? route('admin.whatsapp-calls.permission.request', ['conversation'=>$selected->id]) : '') : '';
    const startUrl = selectedConversation ? @json($selected ? route('admin.whatsapp-calls.start', ['conversation'=>$selected->id]) : '') : '';
    const text = {
        calling: @json(__('whatsapp.inbox.calling')),
        connecting: @json(__('whatsapp.inbox.call_connecting')),
        active: @json(__('whatsapp.inbox.call_active')),
        ended: @json(__('whatsapp.inbox.call_ended')),
        permissionRequired: @json(__('whatsapp.inbox.call_permission_required')),
        permissionSent: @json(__('whatsapp.inbox.call_permission_sent')),
        error: @json(__('whatsapp.inbox.call_error')),
        microphone: @json(__('whatsapp.inbox.microphone_error')),
    };

    let peer = null;
    let localStream = null;
    let iceServers = [];
    let activeCallId = null;
    let activeDirection = null;
    let incomingCall = null;
    let remoteDescriptionApplied = false;
    let polling = false;

    const setStatus = (message, live = false) => {
        if (!statusNode) return;
        statusNode.textContent = message || '';
        statusNode.classList.toggle('wa-call-live', live);
    };

    const callActionUrl = id => actionTemplate.replace('/0/action', '/' + Number(id) + '/action');

    const api = async (url, options = {}) => {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-CSRF-TOKEN', csrf);
        if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
        const response = await fetch(url, {...options, headers, credentials: 'same-origin'});
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const firstValidation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error(firstValidation || payload?.error || payload?.message || text.error);
        }
        return payload;
    };

    const normalizedSdp = value => {
        const source = String(value || '');
        const hadTrailingBreak = /(?:\r\n|\r|\n)$/.test(source);
        const lines = source.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
        while (lines.length && lines[lines.length - 1] === '') lines.pop();
        const result = lines.join('\r\n');
        return result + (hadTrailingBreak ? '\r\n' : '');
    };

    const chromiumUnsignedSsrcFallback = value => {
        const source = normalizedSdp(value);
        const oversized = new Set();

        for (const line of source.split('\r\n')) {
            const match = line.match(/^a=ssrc:(\d+)(?:\s|$)/);
            if (!match) continue;

            const ssrc = Number(match[1]);
            if (Number.isSafeInteger(ssrc) && ssrc > 0x7fffffff && ssrc <= 0xffffffff) {
                oversized.add(match[1]);
            }
        }

        if (!oversized.size) {
            return {changed: false, sdp: source};
        }

        const filtered = source.split('\r\n').filter(line => {
            const ssrc = line.match(/^a=ssrc:(\d+)(?:\s|$)/);
            if (ssrc && oversized.has(ssrc[1])) return false;

            if (line.startsWith('a=ssrc-group:')) {
                const ids = line.trim().split(/\s+/).slice(1);
                if (ids.some(id => oversized.has(id))) return false;
            }

            return true;
        });

        return {changed: true, sdp: filtered.join('\r\n')};
    };

    const setRemoteDescriptionCompat = async (pc, description) => {
        const candidate = {
            ...description,
            sdp: normalizedSdp(description?.sdp),
        };

        try {
            await pc.setRemoteDescription(candidate);
            return;
        } catch (error) {
            const syntaxFailure = error?.errorDetail === 'sdp-syntax-error'
                || /Failed to parse SessionDescription|Invalid SDP line/i.test(String(error?.message || ''));

            const fallback = chromiumUnsignedSsrcFallback(candidate.sdp);
            if (!syntaxFailure || !fallback.changed) throw error;

            // Chromium currently rejects some RFC-valid unsigned 32-bit SSRC values
            // above INT32_MAX. Keep Meta's SDP unchanged in storage/signaling and
            // remove only the offending source-signaling lines for browser parsing.
            await pc.setRemoteDescription({...candidate, sdp: fallback.sdp});
        }
    };

    const waitForIce = pc => new Promise(resolve => {
        if (pc.iceGatheringState === 'complete') { resolve(); return; }
        const timeout = setTimeout(() => {
            pc.removeEventListener('icegatheringstatechange', check);
            resolve();
        }, 4500);
        const check = () => {
            if (pc.iceGatheringState === 'complete') {
                clearTimeout(timeout);
                pc.removeEventListener('icegatheringstatechange', check);
                resolve();
            }
        };
        pc.addEventListener('icegatheringstatechange', check);
    });

    const closeMedia = () => {
        if (peer) {
            try { peer.close(); } catch (_) {}
            peer = null;
        }
        if (localStream) {
            localStream.getTracks().forEach(track => track.stop());
            localStream = null;
        }
        if (remoteAudio) remoteAudio.srcObject = null;
        activeCallId = null;
        activeDirection = null;
        remoteDescriptionApplied = false;
        if (hangupButton) hangupButton.hidden = true;
    };

    const createPeer = async () => {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({audio: true, video: false});
        } catch (_) {
            throw new Error(text.microphone);
        }
        peer = new RTCPeerConnection({iceServers});
        localStream.getTracks().forEach(track => peer.addTrack(track, localStream));
        try {
            const audioTransceiver = peer.getTransceivers().find(item => item.receiver?.track?.kind === 'audio');
            const capabilities = window.RTCRtpReceiver?.getCapabilities?.('audio');
            if (audioTransceiver?.setCodecPreferences && capabilities?.codecs) {
                const opus = capabilities.codecs.filter(codec =>
                    String(codec.mimeType || '').toLowerCase() === 'audio/opus'
                );
                if (opus.length) audioTransceiver.setCodecPreferences(opus);
            }
        } catch (_) {
            // Browser codec preference APIs are optional; Meta will validate the negotiated SDP.
        }
        peer.ontrack = event => {
            const stream = event.streams?.[0] || new MediaStream([event.track]);
            if (remoteAudio) {
                remoteAudio.srcObject = stream;
                remoteAudio.play().catch(() => {});
            }
        };
        peer.onconnectionstatechange = () => {
            if (!peer) return;
            if (peer.connectionState === 'connected') setStatus(text.active, true);
            if (['failed','closed'].includes(peer.connectionState)) {
                setStatus(text.ended);
                closeMedia();
            }
        };
        return peer;
    };

    const requestPermission = async () => {
        if (!requestPermissionUrl) return;
        permissionButton && (permissionButton.disabled = true);
        try {
            await api(requestPermissionUrl, {
                method: 'POST',
                body: JSON.stringify({reason: @json(__('whatsapp.inbox.call_permission_reason'))}),
            });
            setStatus(text.permissionSent, true);
        } catch (error) {
            setStatus(error.message || text.error);
        } finally {
            permissionButton && (permissionButton.disabled = false);
        }
    };

    const startCall = async () => {
        if (!startUrl || !permissionUrl || activeCallId) return;
        startButton && (startButton.disabled = true);
        try {
            const permission = await api(permissionUrl);
            if (!permission.can_start) {
                setStatus(text.permissionRequired);
                return;
            }

            const pc = await createPeer();
            const offer = await pc.createOffer({offerToReceiveAudio: true});
            await pc.setLocalDescription(offer);
            await waitForIce(pc);

            setStatus(text.calling, true);
            const result = await api(startUrl, {
                method: 'POST',
                body: JSON.stringify({sdp: pc.localDescription?.sdp || offer.sdp}),
            });
            activeCallId = Number(result.call_id);
            activeDirection = 'BUSINESS_INITIATED';
            if (hangupButton) hangupButton.hidden = false;
        } catch (error) {
            closeMedia();
            setStatus(error.message || text.error);
        } finally {
            startButton && (startButton.disabled = false);
        }
    };

    const answerIncoming = async () => {
        if (!incomingCall || activeCallId) return;
        answerButton && (answerButton.disabled = true);
        try {
            const pc = await createPeer();
            await setRemoteDescriptionCompat(pc, {type: 'offer', sdp: incomingCall.remote_sdp});
            remoteDescriptionApplied = true;
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await waitForIce(pc);
            const localSdp = pc.localDescription?.sdp || answer.sdp;

            setStatus(text.connecting, true);
            await api(callActionUrl(incomingCall.id), {
                method: 'POST',
                body: JSON.stringify({action: 'pre_accept', sdp: localSdp}),
            });
            await api(callActionUrl(incomingCall.id), {
                method: 'POST',
                body: JSON.stringify({action: 'accept', sdp: localSdp}),
            });

            activeCallId = Number(incomingCall.id);
            activeDirection = 'USER_INITIATED';
            overlay?.classList.remove('show');
            if (hangupButton) hangupButton.hidden = false;
            setStatus(text.active, true);
        } catch (error) {
            closeMedia();
            setStatus(error.message || text.error);
        } finally {
            answerButton && (answerButton.disabled = false);
        }
    };

    const rejectIncoming = async () => {
        if (!incomingCall) return;
        rejectButton && (rejectButton.disabled = true);
        try {
            await api(callActionUrl(incomingCall.id), {
                method: 'POST',
                body: JSON.stringify({action: 'reject'}),
            });
            overlay?.classList.remove('show');
            incomingCall = null;
            setStatus(text.ended);
        } catch (error) {
            setStatus(error.message || text.error);
        } finally {
            rejectButton && (rejectButton.disabled = false);
        }
    };

    const hangup = async () => {
        const id = activeCallId;
        if (!id) return;
        hangupButton && (hangupButton.disabled = true);
        try {
            await api(callActionUrl(id), {
                method: 'POST',
                body: JSON.stringify({action: 'terminate'}),
            });
        } catch (_) {
            // Always stop local media even if the provider already ended the call.
        } finally {
            closeMedia();
            setStatus(text.ended);
            hangupButton && (hangupButton.disabled = false);
        }
    };

    const terminal = status => ['REJECTED','FAILED','NO_ANSWER','COMPLETED','TERMINATED','CANCELED','CANCELLED'].includes(String(status || '').toUpperCase());

    const poll = async () => {
        if (polling) return;
        polling = true;
        try {
            const payload = await api(feedUrl);
            iceServers = Array.isArray(payload.ice_servers) ? payload.ice_servers : [];

            const calls = Array.isArray(payload.calls) ? payload.calls : [];
            if (activeCallId) {
                const active = calls.find(item => Number(item.id) === Number(activeCallId));
                if (active) {
                    if (terminal(active.status)) {
                        closeMedia();
                        setStatus(text.ended);
                    } else if (activeDirection === 'BUSINESS_INITIATED'
                        && active.remote_sdp
                        && String(active.remote_sdp_type).toLowerCase() === 'answer'
                        && peer
                        && !remoteDescriptionApplied) {
                        await setRemoteDescriptionCompat(peer, {type: 'answer', sdp: active.remote_sdp});
                        remoteDescriptionApplied = true;
                        setStatus(text.connecting, true);
                    } else if (String(active.status).toUpperCase() === 'ACCEPTED') {
                        setStatus(text.active, true);
                    } else if (String(active.status).toUpperCase() === 'RINGING') {
                        setStatus(text.calling, true);
                    }
                }
            } else {
                const incoming = calls.find(item =>
                    item.direction === 'USER_INITIATED'
                    && item.remote_sdp
                    && String(item.remote_sdp_type).toLowerCase() === 'offer'
                    && ['INCOMING','RINGING','CONNECTING'].includes(String(item.status).toUpperCase())
                );
                if (incoming) {
                    incomingCall = incoming;
                    if (callerNode) callerNode.textContent = incoming.contact_name || incoming.from_phone || '';
                    overlay?.classList.add('show');
                } else if (incomingCall) {
                    overlay?.classList.remove('show');
                    incomingCall = null;
                }
            }
        } catch (_) {
            // Polling must not interrupt the rest of the CRM if calling is temporarily unavailable.
        } finally {
            polling = false;
        }
    };

    startButton?.addEventListener('click', startCall);
    permissionButton?.addEventListener('click', requestPermission);
    answerButton?.addEventListener('click', answerIncoming);
    rejectButton?.addEventListener('click', rejectIncoming);
    hangupButton?.addEventListener('click', hangup);

    poll();
    setInterval(poll, 2500);
    window.addEventListener('beforeunload', () => {
        if (localStream) localStream.getTracks().forEach(track => track.stop());
    });
})();
</script>
@endif
</body>
</html>
