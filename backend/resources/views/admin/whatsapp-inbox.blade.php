<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ __('whatsapp.inbox.page_title') }}</title>
@include('admin._brand-components')
<style>
*{box-sizing:border-box}
:root{--wa-green:var(--foodex-green);--wa-green-dark:var(--foodex-action-green);--wa-green-soft:var(--foodex-green-soft);--wa-ink:var(--foodex-ink);--wa-muted:var(--foodex-muted);--wa-border:var(--foodex-border);--wa-bg:var(--foodex-background);--wa-panel:var(--foodex-surface);--wa-shadow:0 8px 24px rgba(15,23,42,.08)}
.wa-inbox-main{min-width:0}.wa-shell-marker{display:none!important}
.wa-flash{position:fixed;z-index:10020;top:12px;left:50%;transform:translateX(-50%);max-width:min(680px,calc(100vw - 32px));padding:10px 16px;border:1px solid var(--wa-border);border-radius:10px;background:var(--foodex-surface);box-shadow:var(--wa-shadow);font-size:13px}.wa-flash.error{color:var(--foodex-red)}
.wa-golden-workspace{display:grid;grid-template-areas:"context thread rail";grid-template-columns:448px minmax(0,1fr) 379px;width:100%;min-width:0;background:var(--foodex-surface);color:var(--wa-ink)}
html[dir=ltr] .wa-golden-workspace{grid-template-areas:"rail thread context"}
.wa-context-pane{grid-area:context}.wa-thread-pane{grid-area:thread}.wa-conversation-rail{grid-area:rail}
.wa-pane{min-width:0;background:var(--wa-panel);border-color:var(--wa-border)}.wa-thread-pane{border-inline:1px solid var(--wa-border);display:flex;flex-direction:column;min-height:0}
.wa-pane button,.wa-pane input,.wa-pane select,.wa-pane textarea,.wa-pane summary{font:inherit}
.wa-icon-button{width:38px;height:38px;border:1px solid var(--wa-border);border-radius:8px;background:var(--foodex-surface);color:var(--foodex-muted);display:inline-grid;place-items:center;cursor:pointer;text-decoration:none}.wa-icon-button:hover{background:var(--foodex-background)}.wa-icon-button:focus-visible,.wa-filter-tab:focus-visible,.wa-conversation-row:focus-visible{outline:3px solid rgba(21,138,58,.22);outline-offset:2px}
.wa-avatar{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:var(--foodex-muted);background:color-mix(in srgb,var(--foodex-blue) 9%,var(--foodex-surface));flex:0 0 auto}.wa-avatar.sm{width:42px;height:42px}.wa-avatar.lg{width:52px;height:52px;font-size:18px}.wa-avatar.pink{background:color-mix(in srgb,var(--foodex-red) 10%,var(--foodex-surface))}.wa-avatar.blue{background:color-mix(in srgb,var(--foodex-blue) 12%,var(--foodex-surface))}.wa-avatar.green{background:var(--foodex-green-soft)}.wa-avatar.violet{background:color-mix(in srgb,var(--foodex-blue) 10%,var(--foodex-surface))}
.wa-presence{display:inline-flex;align-items:center;gap:6px;color:var(--wa-green-dark);font-size:12px;font-weight:700}.wa-presence::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green-bright)}
.wa-whatsapp{color:var(--foodex-green-bright);font-weight:900;font-size:17px}
.wa-conversation-rail{display:flex;flex-direction:column;min-height:0}.wa-rail-top{padding:22px 22px 12px;border-bottom:1px solid var(--wa-border);background:var(--foodex-surface)}.wa-rail-title{font-size:19px;font-weight:900;margin:0 0 5px}.wa-rail-helper{font-size:11px;color:var(--wa-muted);margin:0 0 16px;line-height:1.65}.wa-rail-tools{display:flex;align-items:center;gap:8px}.wa-search{position:relative;flex:1}.wa-search input{width:100%;height:40px;border:1px solid var(--wa-border);border-radius:9px;background:var(--foodex-surface);padding-inline:36px 10px;color:var(--wa-ink)}.wa-search span{position:absolute;inset-inline-start:12px;top:10px;color:var(--foodex-muted)}.wa-filter-tabs{display:flex;align-items:center;gap:4px;padding:10px 18px;border-bottom:1px solid var(--wa-border);overflow-x:auto;white-space:nowrap}.wa-filter-tab{height:36px;padding:0 11px;border-radius:8px;display:inline-flex;align-items:center;text-decoration:none;color:var(--foodex-muted);font-size:12px;font-weight:700;border:1px solid transparent}.wa-filter-tab.active{color:var(--wa-green-dark);border-color:color-mix(in srgb,var(--foodex-green) 45%,var(--foodex-surface));background:var(--foodex-green-soft)}.wa-filter-tab.primary{gap:5px}.wa-list{overflow:auto;min-height:0;overscroll-behavior:contain}.wa-conversation-row{min-height:91px;padding:14px 18px;display:grid;grid-template-columns:46px minmax(0,1fr) auto;gap:11px;align-items:start;border-bottom:1px solid var(--wa-border);color:inherit;text-decoration:none;background:var(--foodex-surface)}.wa-conversation-row:hover{background:var(--foodex-background)}.wa-conversation-row.active{background:var(--foodex-green-soft)}.wa-conversation-main{min-width:0}.wa-conversation-name{display:flex;gap:7px;align-items:center;font-size:14px;font-weight:900;white-space:nowrap}.wa-conversation-preview{margin-top:7px;color:var(--foodex-muted);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.wa-conversation-meta{text-align:end;color:var(--foodex-muted);font-size:11px;display:grid;gap:8px;justify-items:end}.wa-unread{min-width:20px;height:20px;padding-inline:6px;border-radius:99px;background:var(--foodex-green);color:var(--foodex-surface);display:inline-grid;place-items:center;font-size:10px;font-weight:900}.wa-rail-pagination{padding:9px 14px;border-top:1px solid var(--wa-border);font-size:11px}.wa-utility details{position:relative}.wa-utility details>summary{list-style:none}.wa-utility details>summary::-webkit-details-marker{display:none}.wa-utility-menu{position:absolute;z-index:40;inset-inline-end:0;top:44px;width:320px;max-width:calc(100vw - 32px);background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:var(--wa-shadow);padding:14px}.wa-utility-menu form{display:grid;gap:9px}.wa-utility-menu label{display:grid;gap:4px;font-size:11px;color:var(--wa-muted)}.wa-utility-menu input,.wa-utility-menu select{min-height:40px;border:1px solid var(--wa-border);border-radius:8px;padding:7px 9px;background:var(--foodex-surface)}
.wa-thread-head{min-height:95px;padding:16px 22px;border-bottom:1px solid var(--wa-border);display:flex;align-items:center;justify-content:space-between;gap:14px}.wa-thread-customer{display:flex;align-items:center;gap:12px;min-width:0}.wa-thread-identity{min-width:0}.wa-thread-name-line{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.wa-thread-name{font-size:17px;font-weight:900}.wa-thread-subline{display:flex;gap:8px;align-items:center;margin-top:5px;font-size:12px;color:var(--foodex-muted);direction:ltr;justify-content:flex-end}html[dir=ltr] .wa-thread-subline{justify-content:flex-start}.wa-thread-actions{display:flex;align-items:center;gap:7px}.wa-call-tools{display:flex;align-items:center;gap:7px}.wa-call-status{font-size:11px;color:var(--wa-muted);max-width:160px}.wa-call-live{color:var(--wa-green-dark);font-weight:800}.wa-call-danger{color:var(--foodex-danger-text)!important}
.wa-thread-meta{min-height:72px;padding:10px 20px;border-bottom:1px solid var(--wa-border);display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;background:var(--foodex-surface)}.wa-meta-cell{min-width:0;padding-inline:8px;border-inline-end:1px solid var(--wa-border)}.wa-meta-cell:last-child{border-inline-end:0}.wa-meta-label{font-size:10px;color:var(--foodex-muted);margin-bottom:6px}.wa-meta-value{font-size:12px;font-weight:800;display:flex;align-items:center;gap:6px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wa-meta-chip{padding:4px 8px;border-radius:999px;background:var(--foodex-background);color:var(--foodex-muted);font-size:10px;font-weight:800}.wa-meta-chip.green{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.wa-meta-details{position:relative}.wa-meta-details>summary{list-style:none;cursor:pointer}.wa-meta-details>summary::-webkit-details-marker{display:none}.wa-meta-popover{position:absolute;z-index:35;top:34px;inset-inline-start:0;width:340px;max-width:80vw;padding:12px;background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:var(--wa-shadow)}.wa-meta-popover form{display:grid;gap:8px;margin-bottom:10px}.wa-meta-popover form:last-child{margin-bottom:0}.wa-meta-popover label{display:grid;gap:4px;font-size:11px;color:var(--wa-muted)}.wa-meta-popover select,.wa-meta-popover input{min-height:38px;border:1px solid var(--wa-border);border-radius:8px;padding:6px 8px}
.wa-timeline{flex:1;min-height:0;overflow:auto;padding:28px 32px 20px;background:var(--foodex-background);display:flex;flex-direction:column;gap:12px;overscroll-behavior:contain}.wa-day-separator{align-self:center;padding:7px 18px;border-radius:999px;background:var(--foodex-background);color:var(--foodex-muted);font-size:11px;margin:3px 0 8px}.wa-message{max-width:68%;border:1px solid var(--wa-border);background:var(--foodex-surface);border-radius:13px;padding:11px 14px;font-size:13px;line-height:1.65;box-shadow:0 2px 7px rgba(15,23,42,.025)}html[dir=rtl] .wa-message.inbound{align-self:flex-end}html[dir=rtl] .wa-message.outbound{align-self:flex-start}html[dir=ltr] .wa-message.inbound{align-self:flex-start}html[dir=ltr] .wa-message.outbound{align-self:flex-end}.wa-message.outbound{background:var(--foodex-green-soft);border-color:color-mix(in srgb,var(--foodex-green) 18%,var(--foodex-surface))}.wa-message.note{align-self:center!important;max-width:88%;background:var(--foodex-surface)7ed;border-style:dashed;border-color:var(--foodex-orange-bright)}.wa-message.smart-ordering{max-width:80%;background:var(--foodex-green-soft);border-color:color-mix(in srgb,var(--foodex-green) 16%,var(--foodex-surface))}.wa-message small{display:block;margin-top:5px;color:var(--foodex-muted);font-size:10px}.wa-message.outbound small{color:var(--foodex-green-dark)}.wa-attachment{display:grid;gap:6px;margin-bottom:7px}.wa-attachment img,.wa-attachment video{max-width:min(340px,100%);max-height:250px;border-radius:10px}.wa-attachment audio{max-width:100%}.wa-attachment-link{display:inline-flex;gap:6px;color:var(--wa-green-dark);font-weight:700;text-decoration:none}.wa-so-action{display:inline-flex;margin-top:7px}
.wa-compose-wrap{border-top:1px solid var(--wa-border);background:var(--foodex-surface);padding:0 18px 14px}.wa-compose-tabs{display:flex;align-items:center;border-bottom:1px solid var(--wa-border);min-height:48px}.wa-compose-tab{min-height:44px;border:0;background:transparent;padding:0 16px;color:var(--foodex-muted);font-size:12px;display:inline-flex;align-items:center;gap:7px;cursor:pointer}.wa-compose-tab+.wa-compose-tab,.wa-compose-tabs details{border-inline-start:1px solid var(--wa-border)}.wa-compose-tabs details{position:relative}.wa-compose-tabs summary{list-style:none;cursor:pointer}.wa-compose-tabs summary::-webkit-details-marker{display:none}.wa-order-menu{position:absolute;z-index:30;bottom:44px;inset-inline-start:0;width:300px;max-width:75vw;background:var(--foodex-surface);border:1px solid var(--wa-border);border-radius:12px;box-shadow:var(--wa-shadow);padding:10px}.wa-order-ref{width:100%;border:0;background:var(--foodex-surface);text-align:start;padding:9px;border-radius:7px;cursor:pointer}.wa-order-ref:hover{background:var(--foodex-background)}.wa-quick-hint{display:none;padding:8px 12px;color:var(--foodex-muted);font-size:11px}.wa-quick-hint.show{display:block}.wa-reply-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:end;padding-top:11px}.wa-reply-input{border:0;resize:none;min-height:64px;max-height:140px;padding:8px 4px;outline:none;background:var(--foodex-surface);width:100%;font-size:13px}.wa-compose-bottom{display:flex;align-items:center;gap:10px;margin-top:4px}.wa-attach-label{width:36px;height:36px;border-radius:8px;display:grid;place-items:center;cursor:pointer;color:var(--foodex-muted)}.wa-attach-label input{display:none}.wa-send{height:40px;min-width:104px;border:0;border-radius:8px;background:var(--wa-green);color:var(--foodex-surface);font-weight:800;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer}.wa-send:disabled{opacity:.6;cursor:wait}.wa-note-form{display:none;grid-template-columns:minmax(0,1fr) auto;gap:9px;padding:10px 0 0}.wa-note-form.show{display:grid}.wa-note-form textarea{min-height:54px;border:1px solid var(--wa-border);border-radius:9px;padding:9px;resize:vertical}.wa-secondary{min-height:40px;border:1px solid color-mix(in srgb,var(--foodex-green) 24%,var(--foodex-surface));background:var(--foodex-green-soft);color:var(--wa-green-dark);border-radius:8px;padding:7px 12px;font-weight:800;cursor:pointer}
.wa-context-pane{overflow:auto;min-height:0;background:var(--foodex-surface)}.wa-context-top{min-height:95px;padding:20px 24px;border-bottom:1px solid var(--wa-border);display:flex;align-items:flex-start;justify-content:space-between}.wa-context-title{margin:0;font-size:17px;font-weight:900}.wa-customer-card{padding:20px 24px;border-bottom:1px solid var(--wa-border)}.wa-customer-center{text-align:center}.wa-customer-center .wa-avatar{margin:0 auto 9px}.wa-customer-name{font-weight:900;font-size:15px}.wa-customer-contact{display:grid;gap:7px;margin-top:12px;font-size:12px;color:var(--foodex-muted)}.wa-customer-contact span{display:flex;align-items:center;gap:8px;justify-content:center}.wa-section{padding:16px 24px;border-bottom:1px solid var(--wa-border)}.wa-section-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:11px}.wa-section-title{font-size:13px;font-weight:900}.wa-section-link{font-size:10px;color:var(--foodex-blue);text-decoration:none}.wa-tags{display:flex;gap:6px;flex-wrap:wrap}.wa-tag{padding:5px 9px;background:var(--foodex-background);border-radius:999px;color:var(--foodex-muted);font-size:10px;font-weight:800}.wa-tag.vip{background:var(--foodex-surface)7d6;color:var(--foodex-orange)}.wa-summary-grid{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--wa-border);border-radius:10px;overflow:hidden}.wa-summary-stat{padding:10px 7px;text-align:center}.wa-summary-stat+.wa-summary-stat{border-inline-start:1px solid var(--wa-border)}.wa-summary-stat strong{display:block;font-size:13px}.wa-summary-stat span{display:block;color:var(--foodex-muted);font-size:9px;margin-top:3px}.wa-record-list{display:grid;gap:10px}.wa-record{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;font-size:11px}.wa-record-main{min-width:0}.wa-record-title{font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wa-record-sub{color:var(--foodex-muted);margin-top:3px}.wa-record-value{text-align:end;font-weight:900;white-space:nowrap}.wa-status-pill{display:inline-flex;padding:3px 7px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:9px;font-weight:800;margin-top:4px}.wa-activity{display:grid;gap:9px}.wa-activity-row{display:grid;grid-template-columns:18px minmax(0,1fr) auto;gap:7px;align-items:start;font-size:10px}.wa-activity-icon{color:var(--foodex-green-dark)}.wa-activity-time{color:var(--foodex-muted);white-space:nowrap}.wa-empty{padding:28px;text-align:center;color:var(--foodex-muted);font-size:12px}
.wa-call-overlay{position:fixed;inset:0;background:rgba(15,23,42,.46);display:none;align-items:center;justify-content:center;z-index:99999;padding:18px}.wa-call-overlay.show{display:flex}.wa-call-card{width:min(440px,100%);background:var(--foodex-surface);border-radius:18px;padding:24px;box-shadow:0 22px 70px rgba(15,23,42,.28);text-align:center}.wa-call-icon{width:72px;height:72px;border-radius:50%;display:grid;place-items:center;margin:0 auto 14px;background:var(--foodex-green-soft);font-size:32px}.wa-call-card h2{margin:0 0 7px}.wa-call-card p{color:var(--foodex-muted);margin:0 0 18px}.wa-call-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
@media(min-width:1200px){html,body{height:100%;overflow:hidden}body{margin:0}.wa-inbox-layout{display:block!important}.wa-inbox-layout>.sidebar{display:none!important}.wa-inbox-main{padding:0!important;margin:0!important;max-width:none!important;width:100%!important}.wa-golden-workspace{height:100vh}.wa-pane{height:100vh}.wa-list{flex:1}.wa-thread-pane{height:100vh}}
@media(min-width:768px) and (max-width:1199px){.wa-golden-workspace{grid-template-areas:"rail thread" "context context";grid-template-columns:300px minmax(0,1fr);min-height:calc(100vh - 24px)}html[dir=ltr] .wa-golden-workspace{grid-template-areas:"rail thread" "context context"}.wa-conversation-rail,.wa-thread-pane{min-height:720px;max-height:calc(100vh - 24px)}.wa-context-pane{max-height:none}.wa-thread-meta{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(min-width:768px) and (max-width:900px){.wa-meta-popover{position:fixed;top:88px;inset-inline:16px;width:auto;max-width:none;max-height:calc(100vh - 104px);overflow:auto}}
@media(max-width:767px){.wa-golden-workspace,html[dir=ltr] .wa-golden-workspace{grid-template-areas:"rail" "thread" "context";grid-template-columns:minmax(0,1fr);height:auto}.wa-conversation-rail{max-height:470px}.wa-list{max-height:300px}.wa-thread-pane{min-height:760px}.wa-thread-head{padding:12px}.wa-thread-actions{gap:4px}.wa-thread-meta{grid-template-columns:repeat(2,minmax(0,1fr));padding:9px}.wa-timeline{min-height:390px;padding:18px 12px}.wa-message{max-width:90%}.wa-compose-wrap{padding-inline:10px}.wa-compose-tabs{overflow-x:auto}.wa-compose-tab{white-space:nowrap;padding-inline:11px}.wa-context-top,.wa-customer-card,.wa-section{padding-inline:16px}.wa-rail-top{padding-inline:14px}.wa-filter-tabs{padding-inline:10px}.wa-conversation-row{padding-inline:12px}.wa-meta-popover{position:fixed;top:88px;inset-inline:16px;width:auto;max-width:none}.wa-reply-form{grid-template-columns:1fr}.wa-send{width:100%}.wa-note-form{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="foodex-admin-layout wa-inbox-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main wa-inbox-main">
        <header class="foodex-page-header wa-shell-marker" aria-hidden="true">
            <div><h1>{{ __('whatsapp.inbox.heading') }}</h1><p>{{ __('whatsapp.inbox.intro') }}</p></div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        <?php if(session('status')): ?>
            <div class="wa-flash" role="status">{{ session('status') }}</div>
        <?php endif; ?>
        <?php if($errors->any()): ?>
            <div class="wa-flash error" role="alert">{{ $errors->first() }}</div>
        <?php endif; ?>

        <div class="wa-golden-workspace" data-wa-golden-workspace data-golden-reference="docs/golden/customer-service-inbox/customer-service-inbox-golden.webp">
            <aside class="wa-pane wa-context-pane" data-wa-pane="customer-context">
                <div class="wa-context-top">
                    <h2 class="wa-context-title">{{ __('whatsapp.inbox.golden.customer_data') }}</h2>
                    <button type="button" class="wa-icon-button" aria-label="{{ __('whatsapp.inbox.golden.more') }}">•••</button>
                </div>
                <?php if($customer360): ?>
                    <?php $customer=$customer360['customer']; ?>
                    <section class="wa-customer-card wa-customer-center">
                        <?php $customerInitial=mb_substr((string)($customer->name ?: __('whatsapp.inbox.customer')),0,2); ?>
                        <div class="wa-avatar lg">{{ $customerInitial }}</div>
                        <div class="wa-customer-name">{{ $customer->name }}</div>
                        <div class="wa-presence">{{ __('whatsapp.inbox.golden.active_customer') }}</div>
                        <div class="wa-customer-contact">
                            <?php if($customer->phone): ?>
                                <span><b>☎</b><span dir="ltr">{{ $customer->phone }}</span><b class="wa-whatsapp">◉</b></span>
                            <?php endif; ?>
                            <?php if($customer->email): ?>
                                <span><b>✉</b><span dir="ltr">{{ $customer->email }}</span></span>
                            <?php endif; ?>
                            <?php if($customer->originStore): ?>
                                <span><b>⌖</b>{{ $customer->originStore->name }}</span>
                            <?php endif; ?>
                            <?php if($customer->created_at): ?>
                                <span><b>▣</b>{{ __('whatsapp.inbox.golden.customer_since') }} {{ \Illuminate\Support\Carbon::parse($customer->created_at)->translatedFormat('d M Y') }}</span>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wa-section">
                        <div class="wa-section-head"><strong class="wa-section-title">{{ __('whatsapp.inbox.golden.categories_tags') }}</strong><span>✎</span></div>
                        <div class="wa-tags">
                            <?php if($tags->isEmpty()): ?>
                                <span class="wa-tag">{{ __('whatsapp.inbox.none') }}</span>
                            <?php else: ?>
                                @foreach($tags as $tag)
                                    <span class="wa-tag {{ $loop->first?'vip':'' }}">{{ $tag->name }}</span>
                                @endforeach
                            <?php endif; ?>
                            <?php if($customer->origin_channel): ?>
                                <span class="wa-tag">{{ __('whatsapp.inbox.channels.'.strtolower((string)$customer->origin_channel)) }}</span>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wa-section">
                        <div class="wa-section-head"><strong class="wa-section-title">{{ __('whatsapp.inbox.golden.total_purchases') }}</strong></div>
                        <?php $latestOrder=$customer360['orders']->first(); ?>
                        <div class="wa-summary-grid">
                            <div class="wa-summary-stat"><strong>{{ $latestOrder ? \Illuminate\Support\Carbon::parse($latestOrder->created_at)->translatedFormat('d M Y') : '—' }}</strong><span>{{ __('whatsapp.inbox.golden.customer_since') }}</span></div>
                            <div class="wa-summary-stat"><strong>{{ $customer360['orders_count'] }}</strong><span>{{ __('whatsapp.inbox.golden.orders_count') }}</span></div>
                            <div class="wa-summary-stat"><strong>{{ number_format($customer360['orders_value'],0) }}</strong><span>{{ __('whatsapp.inbox.golden.total_spend') }}</span></div>
                        </div>
                    </section>

                    <section class="wa-section">
                        <div class="wa-section-head"><strong class="wa-section-title">{{ __('whatsapp.inbox.recent_orders') }}</strong><span class="wa-section-link">{{ __('whatsapp.inbox.golden.show_all') }} ‹</span></div>
                        <div class="wa-record-list">
                            <?php if($customer360['orders']->isEmpty()): ?>
                                <div class="wa-empty">{{ __('whatsapp.inbox.none') }}</div>
                            <?php else: ?>
                                @foreach($customer360['orders']->take(3) as $order)
                                    <div class="wa-record">
                                        <div class="wa-record-main">
                                            <div class="wa-record-title">#{{ $order->order_number }}</div>
                                            <div class="wa-record-sub">{{ \Illuminate\Support\Carbon::parse($order->created_at)->translatedFormat('d M Y') }}</div>
                                        </div>
                                        <div class="wa-record-value">{{ number_format((float)$order->grand_total,0) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$order->currency)) }}<br><span class="wa-status-pill">{{ __('whatsapp.inbox.status_values.'.strtolower((string)$order->status)) }}</span></div>
                                    </div>
                                @endforeach
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wa-section">
                        <div class="wa-section-head"><strong class="wa-section-title">{{ __('whatsapp.inbox.golden.invoices_payments') }}</strong><span class="wa-section-link">{{ __('whatsapp.inbox.golden.show_all') }} ‹</span></div>
                        <div class="wa-record-list">
                            @foreach($customer360['invoices']->take(3) as $invoice)
                                <div class="wa-record"><div class="wa-record-main"><div class="wa-record-title">{{ $invoice->invoice_number }}</div><div class="wa-record-sub">{{ \Illuminate\Support\Carbon::parse($invoice->issued_at)->translatedFormat('d M Y') }}</div></div><div class="wa-record-value">{{ number_format((float)$invoice->total,0) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$invoice->currency)) }}<br><span class="wa-status-pill">{{ __('whatsapp.inbox.status_values.'.strtolower((string)$invoice->status)) }}</span></div></div>
                            @endforeach
                            @foreach($customer360['payments']->take(3) as $payment)
                                <div class="wa-record"><div class="wa-record-main"><div class="wa-record-title">{{ __('whatsapp.inbox.payment_providers.'.strtolower((string)$payment->provider)) }}</div><div class="wa-record-sub">{{ \Illuminate\Support\Carbon::parse($payment->created_at)->translatedFormat('d M Y') }}</div></div><div class="wa-record-value">{{ number_format((float)$payment->amount,0) }} {{ __('whatsapp.inbox.currencies.'.strtolower((string)$payment->currency)) }}<br><span class="wa-status-pill">{{ __('whatsapp.inbox.status_values.'.strtolower((string)$payment->status)) }}</span></div></div>
                            @endforeach
                            <?php if($customer360['invoices']->isEmpty() && $customer360['payments']->isEmpty()): ?>
                                <div class="wa-empty">{{ __('whatsapp.inbox.none') }}</div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="wa-section">
                        <div class="wa-section-head"><strong class="wa-section-title">{{ __('whatsapp.inbox.golden.activity_log') }}</strong></div>
                        <div class="wa-activity">
                            <?php if($timeline->isEmpty()): ?>
                                <div class="wa-empty">{{ __('whatsapp.inbox.no_messages') }}</div>
                            <?php else: ?>
                                @foreach($timeline->reverse()->take(5) as $activity)
                                    <div class="wa-activity-row"><span class="wa-activity-icon">{{ $activity['kind']==='note'?'▱':'◉' }}</span><span>{{ \Illuminate\Support\Str::limit((string)($activity['body'] ?: $activity['actor']),55) }}</span><span class="wa-activity-time">{{ $activity['timestamp'] ? \Illuminate\Support\Carbon::parse($activity['timestamp'])->diffForHumans(null,true) : '' }}</span></div>
                                @endforeach
                            <?php endif; ?>
                        </div>
                    </section>
                <?php else: ?>
                    <div class="wa-empty">{{ __('whatsapp.inbox.no_customer_identity') }}</div>
                <?php endif; ?>
            </aside>

            <section class="wa-pane wa-thread-pane" data-wa-pane="active-conversation">
                <?php if($selected): ?>
                    <?php $selectedName=$selected->customer_name ?: __('whatsapp.inbox.customer'); ?>
                    <?php $selectedInitial=mb_substr((string)$selectedName,0,2); ?>
                    <div class="wa-thread-head">
                        <div class="wa-thread-customer">
                            <div class="wa-avatar lg blue">{{ $selectedInitial }}</div>
                            <div class="wa-thread-identity">
                                <div class="wa-thread-name-line"><span class="wa-thread-name">{{ $selectedName }}</span><span class="wa-presence">{{ __('whatsapp.inbox.golden.active_customer') }}</span></div>
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
                                <summary class="wa-icon-button" aria-label="{{ __('whatsapp.inbox.golden.more') }}">•••</summary>
                                <div class="wa-utility-menu">
                                    <form method="post" action="{{ route('admin.whatsapp-inbox.transition',['conversation'=>$selected->id]) }}" data-wa-mutation>@csrf @method('PATCH')<input type="hidden" name="action" value="{{ $transitionAction }}"><button class="wa-secondary" type="submit">{{ $selectedIsResolved?__('whatsapp.inbox.reopen'):__('whatsapp.inbox.resolve') }}</button></form>
                                </div>
                            </details>
                        </div>
                    </div>

                    <div class="wa-thread-meta">
                        <div class="wa-meta-cell"><div class="wa-meta-label">{{ __('whatsapp.inbox.golden.conversation_classification') }}</div><div class="wa-meta-value">{{ __('whatsapp.inbox.channels.'.strtolower((string)$selected->channel)) }}⌄</div></div>
                        <div class="wa-meta-cell">
                            <div class="wa-meta-label">{{ __('whatsapp.inbox.golden.responsible') }}</div>
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
                        <div class="wa-meta-cell"><div class="wa-meta-label">{{ __('whatsapp.inbox.golden.priority') }}</div><div class="wa-meta-value"><span class="wa-meta-chip green">● {{ ((int)$selected->priority)>1?__('whatsapp.inbox.golden.priority_high'):__('whatsapp.inbox.golden.priority_normal') }}</span></div></div>
                        <div class="wa-meta-cell">
                            <div class="wa-meta-label">{{ __('whatsapp.inbox.golden.tags') }}</div>
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
                                        <label>{{ __('whatsapp.inbox.golden.manage_assignment') }}<select name="action"><option value="attach">{{ __('whatsapp.inbox.attach') }}</option><option value="detach">{{ __('whatsapp.inbox.detach') }}</option></select></label>
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
                                <div class="wa-day-separator">{{ $item['timestamp']?\Illuminate\Support\Carbon::parse($item['timestamp'])->translatedFormat('d M Y'):__('whatsapp.inbox.golden.today') }}</div>
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
                            <button class="wa-compose-tab" type="button" data-wa-quick-toggle>⚡ {{ __('whatsapp.inbox.golden.quick_replies') }}</button>
                            <details>
                                <summary class="wa-compose-tab">▣ {{ __('whatsapp.inbox.golden.attach_order') }}</summary>
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
                        <div class="wa-quick-hint" data-wa-quick-hint>{{ __('whatsapp.inbox.golden.no_quick_replies') }}</div>
                        <form class="wa-reply-form" method="post" enctype="multipart/form-data" action="{{ route('admin.whatsapp-inbox.reply',['conversation'=>$selected->id]) }}" data-wa-mutation>
                            @csrf
                            <div>
                                <textarea class="wa-reply-input" name="body" maxlength="5000" placeholder="{{ __('whatsapp.inbox.golden.write_message') }}" data-wa-reply></textarea>
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

            <aside class="wa-pane wa-conversation-rail" data-wa-pane="conversation-list">
                <?php
                    $stateFilter = (string) ($filters['status'] ?? '');
                    $mediumFilter = (string) ($filters['channel'] ?? '');
                ?>
                <div class="wa-rail-top">
                    <h1 class="wa-rail-title">{{ __('whatsapp.inbox.heading') }}</h1>
                    <p class="wa-rail-helper">{{ __('whatsapp.inbox.golden.helper') }}</p>
                    <div class="wa-rail-tools">
                        <form class="wa-search" method="get" action="{{ route('admin.whatsapp-inbox.index') }}">
                            @foreach(request()->except('q','page','conversation') as $key=>$value)
                                <?php if(is_scalar($value)): ?>
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                <?php endif; ?>
                            @endforeach
                            <span>⌕</span><input name="q" value="{{ $filters['q']??'' }}" aria-label="{{ __('whatsapp.inbox.search') }}" placeholder="{{ __('whatsapp.inbox.search_placeholder') }}">
                        </form>
                        <div class="wa-utility">
                            <details>
                                <summary class="wa-icon-button" aria-label="{{ __('whatsapp.inbox.golden.filters') }}">☷</summary>
                                <div class="wa-utility-menu">
                                    <form method="get" action="{{ route('admin.whatsapp-inbox.index') }}">
                                        <?php if(!empty($filters['q'])): ?>
                                            <input type="hidden" name="q" value="{{ $filters['q'] }}">
                                        <?php endif; ?>
                                        <?php if($stateFilter !== ''): ?>
                                            <input type="hidden" name="status" value="{{ $stateFilter }}">
                                        <?php endif; ?>
                                        <label>{{ __('whatsapp.inbox.assignment') }}<select name="assignment">@foreach(['all','mine','unassigned'] as $value)<option value="{{ $value }}" @selected(($filters['assignment']??'all')===$value)>{{ __('whatsapp.inbox.assignment_filters.'.$value) }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.store') }}<select name="store_id"><option value="">{{ __('whatsapp.inbox.all_authorized') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.channel') }}<input name="channel" value="{{ $mediumFilter }}" placeholder="{{ __('whatsapp.inbox.channels.whatsapp') }}"></label>
                                        <button class="wa-secondary" type="submit">{{ __('whatsapp.inbox.apply') }}</button>
                                    </form>
                                    <hr style="border:0;border-top:1px solid var(--wa-border);margin:12px 0">
                                    <form method="post" action="{{ route('admin.whatsapp-inbox.status') }}" data-wa-mutation>
                                        @csrf @method('PATCH')
                                        <label>{{ __('whatsapp.inbox.my_status') }}<select name="status">@foreach(['ONLINE','BUSY','AWAY','OFFLINE'] as $status)<option value="{{ $status }}" @selected(($profile->status??'OFFLINE')===$status)>{{ __('whatsapp.inbox.agent_statuses.'.$status) }}</option>@endforeach</select></label>
                                        <label>{{ __('whatsapp.inbox.capacity') }}<input type="number" min="1" max="500" name="capacity" value="{{ $profile->capacity??20 }}"></label>
                                        <input type="hidden" name="accepts_new" value="{{ ($profile->accepts_new??1)?1:0 }}">
                                        <button class="wa-secondary" type="submit">{{ __('whatsapp.inbox.update') }}</button>
                                    </form>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
                <?php $statusBase=request()->except('page','conversation','status'); ?>
                <nav class="wa-filter-tabs" aria-label="{{ __('whatsapp.inbox.status') }}">
                    <a class="wa-filter-tab primary {{ $stateFilter===''?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index',$statusBase) }}">⌄ {{ __('whatsapp.inbox.golden.all_conversations') }} ({{ $conversations->total() }})</a>
                    <a class="wa-filter-tab {{ $stateFilter==='OPEN'?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index',array_merge($statusBase,['status'=>'OPEN'])) }}">{{ __('whatsapp.inbox.golden.open') }}</a>
                    <a class="wa-filter-tab {{ $stateFilter==='WAITING_FOR_CUSTOMER'?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index',array_merge($statusBase,['status'=>'WAITING_FOR_CUSTOMER'])) }}">{{ __('whatsapp.inbox.golden.waiting_reply') }}</a>
                    <a class="wa-filter-tab {{ in_array($stateFilter,['RESOLVED','CLOSED'],true)?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index',array_merge($statusBase,['status'=>'CLOSED'])) }}">{{ __('whatsapp.inbox.golden.closed') }}</a>
                </nav>
                <div class="wa-list">
                    <?php if($conversations->count() === 0): ?>
                        <div class="wa-empty">{{ __('whatsapp.inbox.no_conversations') }}</div>
                    <?php else: ?>
                    @foreach($conversations as $row)
                        <?php $active=$selected && (int)$selected->id===(int)$row->id; ?>
                        <?php $rowName=$row->customer_name ?: __('whatsapp.inbox.unknown_customer'); ?>
                        <?php $rowInitial=mb_substr((string)$rowName,0,2); ?>
                        <a class="wa-conversation-row {{ $active?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index',array_merge(request()->except('page','conversation'),['conversation'=>$row->id])) }}" aria-current="{{ $active?'page':'false' }}">
                            <div class="wa-avatar {{ $loop->index%4===1?'violet':($loop->index%4===2?'pink':($loop->index%4===3?'green':'blue')) }}">{{ $rowInitial }}</div>
                            <div class="wa-conversation-main">
                                <div class="wa-conversation-name"><span>{{ $rowName }}</span><span class="wa-whatsapp">◉</span></div>
                                <div class="wa-conversation-preview">{{ $row->last_message_body ?: __('whatsapp.inbox.no_messages') }}</div>
                            </div>
                            <div class="wa-conversation-meta">
                                <span>{{ $row->last_message_at?\Illuminate\Support\Carbon::parse($row->last_message_at)->format('H:i'):'' }}</span>
                                <?php if($row->unread_count>0): ?>
                                    <span class="wa-unread">{{ $row->unread_count }}</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    @endforeach
                    <?php endif; ?>
                </div>
                <div class="wa-rail-pagination">{{ $conversations->onEachSide(0)->links() }}</div>
            </aside>
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
