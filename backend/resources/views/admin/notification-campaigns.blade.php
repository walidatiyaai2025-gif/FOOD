<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('notifications.campaigns') }} · FOODEX</title>
@include('admin._brand-components')
<style>
*{box-sizing:border-box}body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}.sidebar{grid-column:2;grid-row:1;direction:rtl}.main{grid-column:1;grid-row:1;direction:rtl;min-width:0;padding:var(--foodex-space-8)}
html[dir=ltr] .layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}html[dir=ltr] .sidebar{grid-column:1;direction:ltr}html[dir=ltr] .main{grid-column:2;direction:ltr}
.header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:20px}.header h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}
.panel,.card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm);padding:18px}.panel{margin-bottom:16px}.cards{display:grid;gap:14px}
.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}.span2{grid-column:span 2}
label{display:block;font-size:.78rem;font-weight:700;color:var(--foodex-muted);margin-bottom:6px}input,select,textarea,button{font:inherit}input,select,textarea{width:100%;min-height:42px;border:1px solid var(--foodex-border);border-radius:10px;padding:9px 11px;background:#fff;color:inherit}textarea{min-height:96px;resize:vertical}
.actions,.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.primary,.secondary,.danger{border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer}.primary{border:1px solid var(--foodex-green);background:var(--foodex-action-green);color:#fff}.secondary{border:1px solid var(--foodex-green);background:#fff;color:var(--foodex-green-dark)}.danger{border:1px solid #fecaca;background:#fff;color:#991b1b}.row-actions{position:relative;display:inline-block;margin-top:12px}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green);border-radius:50%;display:grid;place-items:center;background:var(--foodex-action-green);color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:220px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu form{margin:0 0 6px}.row-action-menu form:last-child{margin-bottom:0}.row-action-menu button,.row-action-menu .action-link{width:100%}.action-link{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.review-note{margin:0 0 14px}.recipient-list{margin:8px 0 0;padding-inline-start:20px}.review-actions{margin-top:14px}.review-actions form{margin:0}
.badge{display:inline-flex;padding:4px 9px;border-radius:999px;background:#eef2f7;font-size:.78rem;font-weight:700}.badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.paused{background:var(--foodex-orange-soft);color:var(--foodex-orange)}.badge.cancelled{background:#fee2e2;color:#991b1b}
.preview{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:12px 0}.preview>div{border:1px solid var(--foodex-border);border-radius:12px;padding:12px}.preview strong{display:block;margin-top:4px}
.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:10px 0}.meta div{background:#f8fafc;border-radius:10px;padding:10px}.meta small{display:block;color:var(--foodex-muted)}
.history{width:100%;border-collapse:collapse;margin-top:10px}.history th,.history td{padding:8px;border-bottom:1px solid var(--foodex-border);text-align:start;font-size:.85rem}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;padding:12px;border-radius:12px;margin-bottom:14px}.errors{background:#fff1f2;border:1px solid #fecdd3;padding:12px;border-radius:12px;margin-bottom:14px;color:#9f1239}
.schedule-fields{display:contents}
@media(max-width:1000px){.grid{grid-template-columns:1fr 1fr}.meta{grid-template-columns:1fr 1fr}}@media(max-width:760px){.layout,html[dir=ltr] .layout{grid-template-columns:1fr}.sidebar,html[dir=ltr] .sidebar{grid-column:1;grid-row:1;position:relative;height:auto}.main,html[dir=ltr] .main{grid-column:1;grid-row:2;padding:16px}.grid,.preview,.meta{grid-template-columns:1fr}.span2{grid-column:auto}.header{flex-direction:column}}
</style>
</head>
<body>
<div class="layout foodex-admin-layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="main foodex-admin-main foodex-admin-page">
@include('admin._global-header')
    <div class="header foodex-page-toolbar">
        <div>
            <h1>{{ __('notifications.campaigns') }}</h1>
            <div class="muted">{{ __('notifications.campaigns_description') }}</div>
            <div class="muted">{{ __('notifications.timezone_note') }}</div>
        </div>
        
    </div>

    @if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="errors" role="alert"><strong>{{ $errors->first() }}</strong></div>@endif

    @if($reviewCampaign && $reviewSummary)
    <section class="panel" id="campaign-review" aria-labelledby="campaign-review-title">
        <h2 id="campaign-review-title">{{ __('notifications.review_campaign') }}</h2>
        <p class="muted review-note">{{ $reviewAction === 'activate' ? __('notifications.review_before_activation') : __('notifications.review_before_send') }}</p>

        <div class="meta">
            <div><small>{{ __('notifications.effective_audience') }}</small><strong>{{ __('notifications.eligible_accounts', ['count' => $reviewSummary['count']]) }}</strong></div>
            <div><small>{{ __('notifications.audience') }}</small><strong>{{ __('notifications.audience_options.'.$reviewCampaign->audience) }}</strong></div>
            <div><small>{{ __('notifications.app') }}</small><strong>{{ __('notifications.app_options.'.$reviewCampaign->app) }}</strong></div>
            <div><small>{{ __('notifications.business_channel') }}</small><strong>{{ __('notifications.channel_options.'.$reviewCampaign->target_channel) }}</strong></div>
            <div><small>{{ __('notifications.store') }}</small><strong>{{ $reviewStoreName }}</strong></div>
            <div><small>{{ __('notifications.delivery_channel') }}</small><strong>{{ __('notifications.delivery_options.'.$reviewCampaign->delivery_channel) }}</strong></div>
        </div>

        <h3>{{ __('notifications.schedule_review') }}</h3>
        <div class="meta">
            <div><small>{{ __('notifications.timezone') }}</small><strong>{{ __('notifications.timezone_kuwait') }}</strong></div>
            <div><small>{{ __('notifications.schedule_kind') }}</small><strong>{{ __('notifications.'.$reviewCampaign->schedule_kind) }}</strong></div>
            <div><small>{{ __('notifications.starts_at') }}</small><strong>{{ $reviewCampaign->starts_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') }}</strong></div>
            <div><small>{{ __('notifications.next_run') }}</small><strong>{{ ($reviewCampaign->next_run_at ?? $reviewCampaign->starts_at)?->timezone('Asia/Kuwait')->format('Y-m-d H:i') }}</strong></div>
            <div><small>{{ __('notifications.interval_unit') }}</small><strong>@if($reviewCampaign->schedule_kind === 'recurring'){{ $reviewCampaign->interval_value }} {{ __('notifications.'.$reviewCampaign->interval_unit) }}@else{{ __('notifications.once') }}@endif</strong></div>
            <div><small>{{ __('notifications.ends_at') }}</small><strong>{{ $reviewCampaign->ends_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') ?? __('notifications.not_scheduled_to_end') }}</strong></div>
            <div><small>{{ __('notifications.max_runs') }}</small><strong>{{ $reviewCampaign->max_runs ?? __('notifications.no_run_limit') }}</strong></div>
        </div>

        <div class="preview">
            <div dir="rtl"><small>{{ __('notifications.preview_ar') }}</small><strong>{{ $reviewCampaign->title_ar }}</strong><p>{{ $reviewCampaign->body_ar }}</p></div> {{-- localization-gate: allow intentional bilingual delivery review --}}
            <div dir="ltr"><small>{{ __('notifications.preview_en') }}</small><strong>{{ $reviewCampaign->title_en }}</strong><p>{{ $reviewCampaign->body_en }}</p></div> {{-- localization-gate: allow intentional bilingual delivery review --}}
        </div>

        @if($reviewSummary['sample'] !== [])
            <div>
                <strong>{{ __('notifications.audience_sample') }}</strong>
                <ul class="recipient-list">
                    @foreach($reviewSummary['sample'] as $recipient)
                        <li>{{ $recipient['name'] }} · {{ $recipient['email'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($reviewSummary['count'] > 0)
            <div class="row review-actions">
                @if($reviewAction === 'activate')
                    <form method="post" action="{{ route('admin.notification-campaigns.state', $reviewCampaign) }}" class="js-reviewed-mutation">
                        @csrf
                        <input type="hidden" name="state" value="active">
                        <input type="hidden" name="review_token" value="{{ $reviewToken }}">
                        <button class="primary" type="submit" data-busy-label="{{ __('notifications.processing_action') }}">{{ __('notifications.confirm_activate') }}</button>
                    </form>
                @else
                    <form method="post" action="{{ route('admin.notification-campaigns.send-now', $reviewCampaign) }}" class="js-reviewed-mutation">
                        @csrf
                        <input type="hidden" name="review_token" value="{{ $reviewToken }}">
                        <button class="primary" type="submit" data-busy-label="{{ __('notifications.processing_action') }}">{{ __('notifications.confirm_send_now') }}</button>
                    </form>
                @endif
                <a class="secondary action-link" href="{{ route('admin.notification-campaigns.index') }}">{{ __('notifications.back_without_action') }}</a>
            </div>
        @else
            <div class="errors" role="alert"><strong>{{ __('notifications.empty_effective_audience') }}</strong><div>{{ __('notifications.review_empty_help') }}</div></div>
            <a class="secondary action-link" href="{{ route('admin.notification-campaigns.index') }}">{{ __('notifications.back_without_action') }}</a>
        @endif
    </section>
    @endif

    <section class="panel">
        <h2>{{ __('notifications.create_campaign') }}</h2>
        <form method="post" action="{{ route('admin.notification-campaigns.store') }}" class="js-campaign-form" enctype="multipart/form-data">
            @csrf
            <div class="grid">
                <div><label>{{ __('notifications.campaign_name') }}</label><input name="name" value="{{ old('name') }}" placeholder="{{ __('notifications.campaign_name_placeholder') }}" required></div>
                <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','van','user'] as $v)<option value="{{ $v }}">{{ __('notifications.audience_options.'.$v) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver','van'] as $v)<option value="{{ $v }}">{{ __('notifications.app_options.'.$v) }}</option>@endforeach</select></div>

                @if($canAllChannels)
                    <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2b','b2c'] as $v)<option value="{{ $v }}">{{ __('notifications.channel_options.'.$v) }}</option>@endforeach</select></div>
                @elseif($canB2b)
                    <input type="hidden" name="target_channel" value="b2b">
                    <div><label>{{ __('notifications.business_channel') }}</label><input value="{{ __('notifications.channel_options.b2b') }}" disabled></div>
                @else
                    <input type="hidden" name="target_channel" value="b2c">
                    <div><label>{{ __('notifications.business_channel') }}</label><input value="{{ __('notifications.channel_options.b2c') }}" disabled></div>
                @endif

                <div><label>{{ __('notifications.store') }}</label><select name="store_id" class="js-campaign-store" @if(!$canAllChannels && !$canB2b) required @endif>@if($canAllChannels || $canB2b)<option value="">{{ __('notifications.all_stores') }}</option>@else<option value="" disabled>{{ __('notifications.choose_store') }}</option>@endif @foreach($b2bStores as $store)<option value="{{ $store['id'] }}" data-channel="b2b">{{ $store['name'] }}</option>@endforeach @foreach($b2cStores as $store)<option value="{{ $store['id'] }}" data-channel="b2c">{{ $store['name'] }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.delivery_channel') }}</label><select name="delivery_channel">@foreach(['both','push','in_app'] as $v)<option value="{{ $v }}">{{ __('notifications.delivery_options.'.$v) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.popup_frequency') }}</label><select name="popup_frequency">@foreach(['once_per_user','once_per_session','every_open'] as $v)<option value="{{ $v }}" @selected(old('popup_frequency','once_per_session')===$v)>{{ __('notifications.popup_frequency_options.'.$v) }}</option>@endforeach</select></div>
                @include('admin._engagement-destination-builder', [
                    'destinationField' => 'popup_cta_destination',
                    'destinationId' => 'campaign-create',
                    'currentDestination' => old('popup_cta_destination', 'none'),
                ])
                <div><label>{{ __('notifications.popup_cta_label_ar') }}</label><input name="popup_cta_label_ar" value="{{ old('popup_cta_label_ar') }}" dir="rtl"></div>
                <div><label>{{ __('notifications.popup_cta_label_en') }}</label><input name="popup_cta_label_en" value="{{ old('popup_cta_label_en') }}" dir="ltr"></div>
                <div><label>{{ __('notifications.user_id') }}</label><select name="user_id"><option value="">{{ __('notifications.choose_user') }}</option>@foreach($userTargets as $targetUser)<option value="{{ $targetUser->id }}" @selected((string)old('user_id')===(string)$targetUser->id)>{{ $targetUser->name }} · {{ $targetUser->email }}</option>@endforeach</select></div>

                <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" required dir="rtl" placeholder="{{ __('notifications.title_ar_placeholder') }}"></div> {{-- localization-gate: allow explicit bilingual authoring field --}}
                <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" required dir="ltr" placeholder="{{ __('notifications.title_en_placeholder') }}"></div> {{-- localization-gate: allow explicit bilingual authoring field --}}
                <div class="full preview">
                    <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" required dir="rtl" placeholder="{{ __('notifications.body_ar_placeholder') }}"></textarea></div>
                    <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" required dir="ltr" placeholder="{{ __('notifications.body_en_placeholder') }}"></textarea></div>
                            <div><label>{{ __('notifications.campaign_image') }}</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp"></div>
                </div>

                <div><label>{{ __('notifications.schedule_kind') }}</label><select name="schedule_kind" class="js-schedule-kind"><option value="once">{{ __('notifications.once') }}</option><option value="recurring">{{ __('notifications.recurring') }}</option></select></div>
                <div><label>{{ __('notifications.starts_at') }}</label><input name="starts_at" type="datetime-local" value="{{ now('Asia/Kuwait')->addMinutes(5)->format('Y-m-d\TH:i') }}" required></div>
                <div><label>{{ __('notifications.ends_at') }}</label><input name="ends_at" type="datetime-local"></div>
                <div class="js-recurring"><label>{{ __('notifications.interval_value') }}</label><input name="interval_value" type="number" min="1" value="1" placeholder="1"></div>
                <div class="js-recurring"><label>{{ __('notifications.interval_unit') }}</label><select name="interval_unit">@foreach(['minute','hour','day','week','month'] as $v)<option value="{{ $v }}">{{ __('notifications.'.$v) }}</option>@endforeach</select></div>
                <div class="js-recurring"><label>{{ __('notifications.max_runs') }}</label><input name="max_runs" type="number" min="1" placeholder="{{ __('notifications.max_runs_placeholder') }}"></div>
                <div class="full row">
                    <button class="primary" type="submit" data-busy-label="{{ __('notifications.processing_action') }}">{{ __('notifications.save_campaign') }}</button>
                </div>
            </div>
        </form>
    </section>

    <form class="panel row" method="get">
        <input name="q" value="{{ $search }}" placeholder="{{ __('notifications.search') }}">
        <select name="status"><option value="">{{ __('notifications.all_statuses') }}</option>@foreach(['draft','active','paused','completed','cancelled'] as $v)<option value="{{ $v }}" @selected($status===$v)>{{ __('notifications.campaign_status.'.$v) }}</option>@endforeach</select>
        <button class="primary foodex-filter-action">{{ __('notifications.filter') }}</button>
    </form>

    <div class="cards">
    @forelse($campaigns as $campaign)
        <article class="card">
            <div class="row">
                <strong>{{ $campaign->name }}</strong>
                <span class="badge {{ $campaign->status }}">{{ __('notifications.campaign_status.'.$campaign->status) }}</span>
                <span class="badge">{{ __('notifications.channel_options.'.$campaign->target_channel) }}</span>
                <span class="badge">{{ __('notifications.audience_options.'.$campaign->audience) }}</span>
                <span class="badge">{{ __('notifications.delivery_options.'.$campaign->delivery_channel) }}</span>
            </div>

            <div class="preview">
                <div dir="rtl"><small>{{ __('notifications.preview_ar') }}</small><strong>{{ $campaign->title_ar }}</strong><p>{{ $campaign->body_ar }}</p></div>
                <div dir="ltr"><small>{{ __('notifications.preview_en') }}</small><strong>{{ $campaign->title_en }}</strong><p>{{ $campaign->body_en }}</p></div>
            </div>

            <div class="meta">
                <div><small>{{ __('notifications.schedule_kind') }}</small><strong>{{ __('notifications.'.$campaign->schedule_kind) }}</strong></div>
                <div><small>{{ __('notifications.next_run') }}</small><strong>{{ $campaign->next_run_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') ?? '—' }}</strong></div>
                <div><small>{{ __('notifications.last_run') }}</small><strong>{{ $campaign->last_run_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') ?? '—' }}</strong></div>
                <div><small>{{ __('notifications.run_count') }}</small><strong>{{ $campaign->run_count }}</strong></div>
                <div><small>{{ __('notifications.popup_frequency') }}</small><strong>{{ __('notifications.popup_frequency_options.'.($campaign->popup_frequency ?? 'once_per_session')) }}</strong></div>
            </div>

            @if(!in_array($campaign->status,['completed','cancelled'],true))
            <details data-foodex-operational-modal>
                            <summary style="cursor:pointer;font-weight:800">{{ __('notifications.edit_campaign') }}</summary>
                <form method="post" action="{{ route('admin.notification-campaigns.update',$campaign) }}" class="js-campaign-form" enctype="multipart/form-data" style="margin-top:12px">
                    @csrf @method('PATCH')
                    <div class="grid">
                        <div><label>{{ __('notifications.campaign_name') }}</label><input name="name" value="{{ $campaign->name }}" required></div>
                        <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','van','user'] as $v)<option value="{{ $v }}" @selected($campaign->audience===$v)>{{ __('notifications.audience_options.'.$v) }}</option>@endforeach</select></div>
                        <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver','van'] as $v)<option value="{{ $v }}" @selected($campaign->app===$v)>{{ __('notifications.app_options.'.$v) }}</option>@endforeach</select></div>

                        @if($canAllChannels)
                            <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2b','b2c'] as $v)<option value="{{ $v }}" @selected($campaign->target_channel===$v)>{{ __('notifications.channel_options.'.$v) }}</option>@endforeach</select></div>
                        @else
                            <input type="hidden" name="target_channel" value="{{ $campaign->target_channel }}">
                            <div><label>{{ __('notifications.business_channel') }}</label><input value="{{ __('notifications.channel_options.'.$campaign->target_channel) }}" disabled></div>
                        @endif

                        <div><label>{{ __('notifications.store') }}</label><select name="store_id" class="js-campaign-store" @if(!$canAllChannels && !$canB2b) required @endif>@if($canAllChannels || $canB2b)<option value="">{{ __('notifications.all_stores') }}</option>@else<option value="" disabled>{{ __('notifications.choose_store') }}</option>@endif @foreach($b2bStores as $store)<option value="{{ $store['id'] }}" data-channel="b2b" @selected((int)$campaign->store_id===$store['id'])>{{ $store['name'] }}</option>@endforeach @foreach($b2cStores as $store)<option value="{{ $store['id'] }}" data-channel="b2c" @selected((int)$campaign->store_id===$store['id'])>{{ $store['name'] }}</option>@endforeach</select></div>
                        <div><label>{{ __('notifications.delivery_channel') }}</label><select name="delivery_channel">@foreach(['both','push','in_app'] as $v)<option value="{{ $v }}" @selected($campaign->delivery_channel===$v)>{{ __('notifications.delivery_options.'.$v) }}</option>@endforeach</select></div>
                        <div><label>{{ __('notifications.popup_frequency') }}</label><select name="popup_frequency">@foreach(['once_per_user','once_per_session','every_open'] as $v)<option value="{{ $v }}" @selected(($campaign->popup_frequency ?? 'once_per_session')===$v)>{{ __('notifications.popup_frequency_options.'.$v) }}</option>@endforeach</select></div>
                        @include('admin._engagement-destination-builder', [
                            'destinationField' => 'popup_cta_destination',
                            'destinationId' => 'campaign-'.$campaign->id,
                            'currentDestination' => $campaignDestinationSelections[(int)$campaign->id] ?? 'none',
                        ])
                        <div><label>{{ __('notifications.popup_cta_label_ar') }}</label><input name="popup_cta_label_ar" value="{{ $campaign->popup_cta_label_ar }}" dir="rtl"></div>
                        <div><label>{{ __('notifications.popup_cta_label_en') }}</label><input name="popup_cta_label_en" value="{{ $campaign->popup_cta_label_en }}" dir="ltr"></div>
                        <div><label>{{ __('notifications.user_id') }}</label><select name="user_id"><option value="">{{ __('notifications.choose_user') }}</option>@foreach($userTargets as $targetUser)<option value="{{ $targetUser->id }}" @selected((string)$campaign->user_id===(string)$targetUser->id)>{{ $targetUser->name }} · {{ $targetUser->email }}</option>@endforeach</select></div>
                        <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" value="{{ $campaign->title_ar }}" required dir="rtl"></div>
                        <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" value="{{ $campaign->title_en }}" required dir="ltr"></div>
                        <div class="full preview">
                            <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" required dir="rtl" placeholder="نص الإشعار بالعربية">{{ $campaign->body_ar }}</textarea></div>
                            <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" required dir="ltr" placeholder="Notification body in English">{{ $campaign->body_en }}</textarea></div>
                            <div><label>{{ __('notifications.campaign_image') }}</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp">@if($campaign->image_path)<div style="margin-top:8px"><img src="{{ url('/'.ltrim($campaign->image_path,'/')) }}" alt="" style="max-height:120px;border-radius:12px"></div>@endif {{-- localization-gate: allow Blade image control directive --}}</div>
                        </div>
                        <div><label>{{ __('notifications.schedule_kind') }}</label><select name="schedule_kind" class="js-schedule-kind"><option value="once" @selected($campaign->schedule_kind==='once')>{{ __('notifications.once') }}</option><option value="recurring" @selected($campaign->schedule_kind==='recurring')>{{ __('notifications.recurring') }}</option></select></div>
                        <div><label>{{ __('notifications.starts_at') }}</label><input name="starts_at" type="datetime-local" value="{{ $campaign->starts_at?->timezone('Asia/Kuwait')->format('Y-m-d\TH:i') }}" required></div>
                        <div><label>{{ __('notifications.ends_at') }}</label><input name="ends_at" type="datetime-local" value="{{ $campaign->ends_at?->timezone('Asia/Kuwait')->format('Y-m-d\TH:i') }}"></div>
                        <div class="js-recurring"><label>{{ __('notifications.interval_value') }}</label><input name="interval_value" type="number" min="1" value="{{ $campaign->interval_value ?? 1 }}"></div>
                        <div class="js-recurring"><label>{{ __('notifications.interval_unit') }}</label><select name="interval_unit">@foreach(['minute','hour','day','week','month'] as $v)<option value="{{ $v }}" @selected($campaign->interval_unit===$v)>{{ __('notifications.'.$v) }}</option>@endforeach</select></div>
                        <div class="js-recurring"><label>{{ __('notifications.max_runs') }}</label><input name="max_runs" type="number" min="1" value="{{ $campaign->max_runs }}"></div>
                        <div class="full"><button class="primary" type="submit">{{ __('notifications.save') }}</button></div>
                    </div>
                </form>
            </details>
            @endif

            @if(!in_array($campaign->status,['completed','cancelled'],true) || $campaign->status!=='cancelled')
            <details class="row-actions" data-notification-campaign-actions>
                <summary aria-label="{{ __('notifications.actions') }}">⋮</summary>
                <div class="row-action-menu">
                    @if(!in_array($campaign->status,['completed','cancelled'],true))
                        <a class="primary action-link" href="{{ route('admin.notification-campaigns.index', ['review_campaign' => $campaign->id, 'review_action' => 'send_now']) }}#campaign-review">{{ __('notifications.send_now') }}</a>
                    @endif
                    @if($campaign->status==='active')
                        <form method="post" action="{{ route('admin.notification-campaigns.state',$campaign) }}">@csrf<input type="hidden" name="state" value="paused"><button class="secondary">{{ __('notifications.pause') }}</button></form>
                    @elseif(!in_array($campaign->status,['completed','cancelled'],true))
                        <a class="secondary action-link" href="{{ route('admin.notification-campaigns.index', ['review_campaign' => $campaign->id, 'review_action' => 'activate']) }}#campaign-review">{{ __('notifications.activate') }}</a>
                    @endif
                    @if($campaign->status!=='cancelled')
                        <form method="post" action="{{ route('admin.notification-campaigns.state',$campaign) }}">@csrf<input type="hidden" name="state" value="cancelled"><button class="danger">{{ __('notifications.cancel') }}</button></form>
                    @endif
                </div>
            </details>
            @endif

            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:800">{{ __('notifications.history') }}</summary>
                <div style="overflow:auto">
                <table class="history">
                    <thead><tr><th>{{ __('notifications.history_run') }}</th><th>{{ __('notifications.scheduled_for') }}</th><th>{{ __('notifications.executed_at') }}</th><th>{{ __('notifications.history_status') }}</th><th>{{ __('notifications.generated_notification') }}</th><th>{{ __('notifications.history_result') }}</th></tr></thead>
                    <tbody>
                    @forelse($campaign->runs as $run)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>{{ $run->scheduled_for?->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $run->completed_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') ?? '—' }}</td>
                            <td>{{ __("notifications.run_status.$run->status") }}</td>
                            <td>{{ $run->notification_id ? __('notifications.generated') : '—' }}</td>
                            <td>{{ $run->error_code ? __('notifications.review_run_status') : __('notifications.successful') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">—</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </details>
        </article>
    @empty
        <div class="panel">{{ __('notifications.empty_campaigns') }}</div>
    @endforelse
    </div>
    <div style="margin-top:16px">{{ $campaigns->links() }}</div>
</main>
</div>
<script>
document.querySelectorAll('.js-campaign-form').forEach((form)=>{
    const kind=form.querySelector('.js-schedule-kind');
    const target=form.querySelector('select[name="target_channel"], input[name="target_channel"]');
    const store=form.querySelector('.js-campaign-store');
    const syncSchedule=()=>form.querySelectorAll('.js-recurring').forEach((el)=>{el.style.display=kind?.value==='recurring'?'block':'none';});
    const syncStores=()=>{
        const channel=target?.value || 'all';
        store?.querySelectorAll('option[data-channel]').forEach((option)=>{
            const visible=channel==='all' || option.dataset.channel===channel;
            option.hidden=!visible;
            option.disabled=!visible;
            if(!visible && option.selected) store.value='';
        });
    };
    kind?.addEventListener('change',syncSchedule);
    if(target?.tagName==='SELECT') target.addEventListener('change',syncStores);
    syncSchedule();
    syncStores();
    form.addEventListener('submit',(event)=>{
        if(form.dataset.submitting==='1'){
            event.preventDefault();
            return;
        }
        form.dataset.submitting='1';
        form.querySelectorAll('button[type="submit"]').forEach((button)=>{
            button.disabled=true;
            button.setAttribute('aria-disabled','true');
            if(button.dataset.busyLabel) button.textContent=button.dataset.busyLabel;
        });
    });
});
document.querySelectorAll('.js-reviewed-mutation').forEach((form)=>{
    form.addEventListener('submit',(event)=>{
        if(form.dataset.submitting==='1'){
            event.preventDefault();
            return;
        }
        form.dataset.submitting='1';
        form.querySelectorAll('button[type="submit"]').forEach((button)=>{
            button.disabled=true;
            button.setAttribute('aria-disabled','true');
            if(button.dataset.busyLabel) button.textContent=button.dataset.busyLabel;
        });
    });
});
</script>
@include('admin._engagement-destination-script')
</body>
</html>
