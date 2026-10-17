<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('notifications.title') }} · FOODEX</title>
    <style>
        :root{color:#17202a;background:#f5f7fa}
        *{box-sizing:border-box}body{margin:0}.foodex-admin-layout{min-height:100vh}.notifications-main{min-width:0;padding:28px}.wrap{max-width:1500px;margin:auto}
        .top,.row,.actions{display:flex;gap:12px;align-items:center}.top{justify-content:space-between;margin-bottom:18px}
        .panel,.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px}.panel{margin-bottom:16px}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}
        input,select,textarea,button{font:inherit}input,select,textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px}
        textarea{min-height:88px}button{border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
        .primary{border:1px solid var(--foodex-green);background:var(--foodex-action-green);color:#fff}.secondary{border:1px solid var(--foodex-green);background:#fff;color:var(--foodex-green-dark)}.danger{border:1px solid #fecaca;background:#fff;color:#991b1b}
        .row-actions{position:relative;display:inline-block;margin-top:12px}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green);border-radius:50%;display:grid;place-items:center;background:var(--foodex-action-green);color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:220px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu form{margin:0 0 6px}.row-action-menu form:last-child{margin-bottom:0}.row-action-menu button,.row-action-menu .action-link{width:100%}.action-link{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:10px 0}.meta>div{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.meta small{display:block;color:var(--foodex-muted)}.recipient-list{margin:8px 0 0;padding-inline-start:20px}.review-actions{margin-top:14px}
        .cards{display:grid;gap:12px}.badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#f1f5f9;font-size:.8rem}
        .preview{display:grid;grid-template-columns:1fr 1fr;gap:12px}.preview>div{padding:12px;border:1px solid #e2e8f0;border-radius:12px}
        .flash{background:#ecfdf5;border:1px solid #a7f3d0;padding:12px 16px;border-radius:12px;margin-bottom:16px}
        label{display:block;font-size:.8rem;font-weight:700;margin-bottom:6px;color:#475569}
        @media(max-width:1023px){.notifications-main{padding:18px}.meta{grid-template-columns:1fr 1fr}}@media(max-width:850px){.notifications-main{padding:16px}.grid,.preview,.meta{grid-template-columns:1fr}.top,.row{align-items:stretch;flex-direction:column}}
    </style>
    @include('admin._brand-components')
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'notification_campaigns', 'user' => auth()->user()]) {{-- localization-gate: allow Blade include expression --}}</aside>
    <main class="notifications-main foodex-admin-main">
@include('admin._global-header')
<div class="wrap foodex-admin-page" data-foodex-utility="notifications">
    <div class="top foodex-page-toolbar">
        <div><h1>{{ __('notifications.title') }}</h1><p>{{ __('notifications.description') }}</p></div>
        <div class="actions">
            @if(auth()->user()?->hasPermission('translations.manage'))
                <a href="{{ route('admin.translations.index') }}">{{ __('admin.translations.title') }}</a>
            @endif
            <a href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a>
        </div>
    </div>
    @if(session('status'))<div class="flash foodex-state" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" role="alert"><strong>{{ $errors->first() }}</strong></div>@endif

    @if($reviewNotification && $reviewSummary)
    <section class="panel" id="notification-review" aria-labelledby="notification-review-title">
        <h2 id="notification-review-title">{{ __('notifications.review_notification') }}</h2>
        <p>{{ __('notifications.review_before_publish') }}</p>
        <div class="meta">
            <div><small>{{ __('notifications.effective_audience') }}</small><strong>{{ __('notifications.eligible_accounts', ['count' => $reviewSummary['count']]) }}</strong></div>
            <div><small>{{ __('notifications.audience') }}</small><strong>{{ __('notifications.audience_options.'.$reviewNotification->audience) }}</strong></div>
            <div><small>{{ __('notifications.app') }}</small><strong>{{ __('notifications.app_options.'.$reviewNotification->app) }}</strong></div>
            <div><small>{{ __('notifications.business_channel') }}</small><strong>{{ __('notifications.channel_options.'.$reviewNotification->target_channel) }}</strong></div>
            <div><small>{{ __('notifications.store') }}</small><strong>{{ $reviewStoreName }}</strong></div>
            <div><small>{{ __('notifications.delivery_channel') }}</small><strong>{{ __('notifications.delivery_options.'.$reviewNotification->channel) }}</strong></div>
        </div>
        <div class="preview">
            <div dir="rtl"><small>{{ __('notifications.preview_ar') }}</small><strong>{{ $reviewNotification->title_ar }}</strong><p>{{ $reviewNotification->body_ar }}</p></div> {{-- localization-gate: allow intentional bilingual publication review --}}
            <div dir="ltr"><small>{{ __('notifications.preview_en') }}</small><strong>{{ $reviewNotification->title_en }}</strong><p>{{ $reviewNotification->body_en }}</p></div> {{-- localization-gate: allow intentional bilingual publication review --}}
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
            <div class="actions review-actions">
                <form method="post" action="{{ route('admin.notifications.publish', $reviewNotification) }}" class="js-reviewed-mutation">
                    @csrf
                    <input type="hidden" name="review_token" value="{{ $reviewToken }}">
                    <button class="primary" type="submit" data-busy-label="{{ __('notifications.processing_action') }}">{{ __('notifications.confirm_publish') }}</button>
                </form>
                <a class="secondary action-link" href="{{ route('admin.notifications.index') }}">{{ __('notifications.back_without_action') }}</a>
            </div>
        @else
            <div class="panel" role="alert"><strong>{{ __('notifications.empty_effective_audience') }}</strong><div>{{ __('notifications.review_empty_help') }}</div></div>
            <a class="secondary action-link" href="{{ route('admin.notifications.index') }}">{{ __('notifications.back_without_action') }}</a>
        @endif
    </section>
    @endif

    <section class="panel">
        <h2>{{ __('notifications.create') }}</h2>
        <form method="post" action="{{ route('admin.notifications.store') }}" enctype="multipart/form-data">@csrf
            <div class="grid">
                <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" placeholder="{{ __('notifications.title_ar_placeholder') }}" required></div>
                <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" placeholder="{{ __('notifications.title_en_placeholder') }}" required></div>
                <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" required dir="rtl" placeholder="{{ __('notifications.body_ar_placeholder') }}"></textarea></div>
                <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" required dir="ltr" placeholder="{{ __('notifications.body_en_placeholder') }}"></textarea></div>
                <div class="full"><label>{{ __('notifications.notification_image') }}</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp"><small>{{ __('notifications.notification_image_hint') }}</small></div>
                <div><label>{{ __('notifications.type') }}</label><input name="type" value="general" placeholder="{{ __('notifications.type_placeholder') }}" required></div>
                <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','van','user'] as $value)<option value="{{ $value }}">{{ __('notifications.audience_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver','van'] as $value)<option value="{{ $value }}">{{ __('notifications.app_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2c','b2b'] as $value)<option value="{{ $value }}">{{ __('notifications.channel_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.delivery_channel') }}</label><select name="channel">@foreach(['in_app','push','both'] as $value)<option value="{{ $value }}">{{ __('notifications.delivery_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.user_id') }}</label><select name="user_id"><option value="">{{ __('notifications.choose_user') }}</option>@foreach($userTargets as $targetUser)<option value="{{ $targetUser->id }}" @selected((string)old('user_id')===(string)$targetUser->id)>{{ $targetUser->name }} · {{ $targetUser->email }}</option>@endforeach</select></div>
                <div class="full"><button class="primary" type="submit">{{ __('notifications.save_draft') }}</button></div>
            </div>
        </form>
    </section>

    <form class="panel row" method="get">
        <input name="q" value="{{ $search }}" placeholder="{{ __('notifications.search') }}">
        <select name="status"><option value="">{{ __('notifications.all_statuses') }}</option>@foreach(['draft','published'] as $value)<option value="{{ $value }}" @selected($status===$value)>{{ __('notifications.status_options.'.$value) }}</option>@endforeach</select>
        <button class="primary foodex-filter-action">{{ __('notifications.filter') }}</button>
    </form>

    <div class="cards">
    @forelse($notifications as $notification)
        <article class="card">
            <div class="row"><strong>{{ __('notifications.notification_reference', ['number' => $notifications->firstItem() + $loop->index]) }}</strong><span class="badge">{{ __('notifications.status_options.'.$notification->status) }}</span><span class="badge">{{ __('notifications.audience_options.'.$notification->audience) }}</span><span class="badge">{{ __('notifications.app_options.'.$notification->app) }} / {{ __('notifications.channel_options.'.$notification->target_channel) }}</span><span class="badge">{{ __('notifications.delivery_options.'.$notification->channel) }}</span></div>
            <div class="preview">
                <div dir="rtl"><small>{{ __('notifications.preview_ar') }}</small><strong>{{ $notification->title_ar }}</strong><p>{{ $notification->body_ar }}</p></div>
                <div dir="ltr"><small>{{ __('notifications.preview_en') }}</small><strong>{{ $notification->title_en }}</strong><p>{{ $notification->body_en }}</p></div>
            </div>

            <details class="edit-record" data-notification-edit data-foodex-operational-modal>
                <summary style="cursor:pointer;font-weight:800">{{ __('notifications.edit_notification') }}</summary>
            <form method="post" action="{{ route('admin.notifications.update',$notification) }}" enctype="multipart/form-data">@csrf @method('PATCH')
                <div class="grid">
                    <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" value="{{ $notification->title_ar }}" required></div>
                    <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" value="{{ $notification->title_en }}" required></div>
                    <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" dir="rtl" required placeholder="{{ __('notifications.body_ar_placeholder') }}">{{ $notification->body_ar }}</textarea></div>
                    <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" dir="ltr" required placeholder="{{ __('notifications.body_en_placeholder') }}">{{ $notification->body_en }}</textarea></div>
                    <div class="full"><label>{{ __('notifications.notification_image') }}</label><input type="file" name="image" accept="image/png,image/jpeg,image/webp">@if($notification->image_path)<div style="margin-top:8px"><img src="{{ url('/'.ltrim($notification->image_path,'/')) }}" alt="" style="max-height:120px;border-radius:12px"></div>@endif {{-- localization-gate: allow Blade image control directive --}}</div>
                    <div><label>{{ __('notifications.type') }}</label><input name="type" value="{{ $notification->type }}" required></div>
                    <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','van','user'] as $value)<option value="{{ $value }}" @selected($notification->audience===$value)>{{ __('notifications.audience_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver','van'] as $value)<option value="{{ $value }}" @selected($notification->app===$value)>{{ __('notifications.app_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2c','b2b'] as $value)<option value="{{ $value }}" @selected($notification->target_channel===$value)>{{ __('notifications.channel_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.delivery_channel') }}</label><select name="channel">@foreach(['in_app','push','both'] as $value)<option value="{{ $value }}" @selected($notification->channel===$value)>{{ __('notifications.delivery_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.user_id') }}</label><select name="user_id"><option value="">{{ __('notifications.choose_user') }}</option>@foreach($userTargets as $targetUser)<option value="{{ $targetUser->id }}" @selected((string)$notification->user_id===(string)$targetUser->id)>{{ $targetUser->name }} · {{ $targetUser->email }}</option>@endforeach</select></div>
                </div>
                <div class="actions"><button class="primary" type="submit">{{ __('notifications.save') }}</button></div>
            </form>
            </details>

            <details class="row-actions" data-notification-row-actions>
                <summary aria-label="{{ __('notifications.actions') }}">⋮</summary>
                <div class="row-action-menu">
                    @if($notification->status !== 'published')
                    <a class="secondary action-link" href="{{ route('admin.notifications.index', ['review_notification' => $notification->id]) }}#notification-review">{{ __('notifications.publish') }}</a>
                    @endif
                    <form method="post" action="{{ route('admin.notifications.destroy',$notification) }}">@csrf @method('DELETE')<button class="danger" type="submit">{{ __('notifications.delete') }}</button></form>
                </div>
            </details>
        </article>
    @empty
        <div class="panel">{{ __('notifications.empty') }}</div>
    @endforelse
    </div>
    <div>{{ $notifications->links() }}</div>
</div>
    </main>
</div>
<script>
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
</body></html>
