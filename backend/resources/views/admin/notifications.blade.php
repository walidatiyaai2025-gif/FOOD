<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('notifications.title') }} · FOODEX</title>
    <style>
        :root{color:#17202a;background:#f5f7fa}
        *{box-sizing:border-box}body{margin:0;padding:28px}.wrap{max-width:1500px;margin:auto}
        .top,.row,.actions{display:flex;gap:12px;align-items:center}.top{justify-content:space-between;margin-bottom:18px}
        .panel,.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:16px}.panel{margin-bottom:16px}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}
        input,select,textarea,button{font:inherit}input,select,textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px}
        textarea{min-height:88px}button{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
        .primary{background:#111827;color:#fff}.secondary{background:#eef2ff;color:#3730a3}.danger{background:#fee2e2;color:#991b1b}
        .cards{display:grid;gap:12px}.badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#f1f5f9;font-size:.8rem}
        .preview{display:grid;grid-template-columns:1fr 1fr;gap:12px}.preview>div{padding:12px;border:1px solid #e2e8f0;border-radius:12px}
        .flash{background:#ecfdf5;border:1px solid #a7f3d0;padding:12px 16px;border-radius:12px;margin-bottom:16px}
        label{display:block;font-size:.8rem;font-weight:700;margin-bottom:6px;color:#475569}
        @media(max-width:850px){body{padding:16px}.grid,.preview{grid-template-columns:1fr}.top,.row{align-items:stretch;flex-direction:column}}
    </style>
    @include('admin._brand-components')
</head>
<body><div class="wrap foodex-admin-page" data-foodex-utility="notifications">
    <div class="top foodex-page-header">
        <div><h1>{{ __('notifications.title') }}</h1><p>{{ __('notifications.description') }}</p></div>
        <div class="actions">
            @if(auth()->user()?->hasPermission('translations.manage'))
                <a href="{{ route('admin.translations.index') }}">{{ __('admin.translations.title') }}</a>
            @endif
            <a href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a>
        </div>
    </div>
    @if(session('status'))<div class="flash foodex-state" role="status">{{ session('status') }}</div>@endif

    <section class="panel">
        <h2>{{ __('notifications.create') }}</h2>
        <form method="post" action="{{ route('admin.notifications.store') }}">@csrf
            <div class="grid">
                <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" required></div>
                <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" required></div>
                <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" required dir="rtl"></textarea></div>
                <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" required dir="ltr"></textarea></div>
                <div><label>{{ __('notifications.type') }}</label><input name="type" value="general" required></div>
                <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','user'] as $value)<option value="{{ $value }}">{{ __('notifications.audience_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver'] as $value)<option value="{{ $value }}">{{ __('notifications.app_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2c','b2b'] as $value)<option value="{{ $value }}">{{ __('notifications.channel_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.delivery_channel') }}</label><select name="channel">@foreach(['in_app','push','both'] as $value)<option value="{{ $value }}">{{ __('notifications.delivery_options.'.$value) }}</option>@endforeach</select></div>
                <div><label>{{ __('notifications.user_id') }}</label><input name="user_id" type="number" min="1"></div>
                <div class="full"><button class="primary" type="submit">{{ __('notifications.save_draft') }}</button></div>
            </div>
        </form>
    </section>

    <form class="panel row" method="get">
        <input name="q" value="{{ $search }}" placeholder="{{ __('notifications.search') }}">
        <select name="status"><option value="">{{ __('notifications.all_statuses') }}</option>@foreach(['draft','published'] as $value)<option value="{{ $value }}" @selected($status===$value)>{{ __('notifications.status_options.'.$value) }}</option>@endforeach</select>
        <button class="primary">{{ __('notifications.filter') }}</button>
    </form>

    <div class="cards">
    @forelse($notifications as $notification)
        <article class="card">
            <div class="row"><strong>#{{ $notification->id }}</strong><span class="badge">{{ __('notifications.status_options.'.$notification->status) }}</span><span class="badge">{{ __('notifications.audience_options.'.$notification->audience) }}</span><span class="badge">{{ __('notifications.app_options.'.$notification->app) }} / {{ __('notifications.channel_options.'.$notification->target_channel) }}</span><span class="badge">{{ __('notifications.delivery_options.'.$notification->channel) }}</span></div>
            <div class="preview">
                <div dir="rtl"><small>{{ __('notifications.preview_ar') }}</small><strong>{{ $notification->title_ar }}</strong><p>{{ $notification->body_ar }}</p></div>
                <div dir="ltr"><small>{{ __('notifications.preview_en') }}</small><strong>{{ $notification->title_en }}</strong><p>{{ $notification->body_en }}</p></div>
            </div>

            <form method="post" action="{{ route('admin.notifications.update',$notification) }}">@csrf @method('PATCH')
                <div class="grid">
                    <div><label>{{ __('notifications.title_ar') }}</label><input name="title_ar" value="{{ $notification->title_ar }}" required></div>
                    <div><label>{{ __('notifications.title_en') }}</label><input name="title_en" value="{{ $notification->title_en }}" required></div>
                    <div><label>{{ __('notifications.body_ar') }}</label><textarea name="body_ar" dir="rtl" required>{{ $notification->body_ar }}</textarea></div>
                    <div><label>{{ __('notifications.body_en') }}</label><textarea name="body_en" dir="ltr" required>{{ $notification->body_en }}</textarea></div>
                    <div><label>{{ __('notifications.type') }}</label><input name="type" value="{{ $notification->type }}" required></div>
                    <div><label>{{ __('notifications.audience') }}</label><select name="audience">@foreach(['all','customer','driver','user'] as $value)<option value="{{ $value }}" @selected($notification->audience===$value)>{{ __('notifications.audience_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.app') }}</label><select name="app">@foreach(['all','customer','driver'] as $value)<option value="{{ $value }}" @selected($notification->app===$value)>{{ __('notifications.app_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.business_channel') }}</label><select name="target_channel">@foreach(['all','b2c','b2b'] as $value)<option value="{{ $value }}" @selected($notification->target_channel===$value)>{{ __('notifications.channel_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.delivery_channel') }}</label><select name="channel">@foreach(['in_app','push','both'] as $value)<option value="{{ $value }}" @selected($notification->channel===$value)>{{ __('notifications.delivery_options.'.$value) }}</option>@endforeach</select></div>
                    <div><label>{{ __('notifications.user_id') }}</label><input name="user_id" type="number" min="1" value="{{ $notification->user_id }}"></div>
                </div>
                <div class="actions"><button class="primary" type="submit">{{ __('notifications.save') }}</button></div>
            </form>

            <div class="actions">
                @if($notification->status !== 'published')
                <form method="post" action="{{ route('admin.notifications.publish',$notification) }}">@csrf<button class="secondary" type="submit">{{ __('notifications.publish') }}</button></form>
                @endif
                <form method="post" action="{{ route('admin.notifications.destroy',$notification) }}">@csrf @method('DELETE')<button class="danger" type="submit">{{ __('notifications.delete') }}</button></form>
            </div>
        </article>
    @empty
        <div class="panel">{{ __('notifications.empty') }}</div>
    @endforelse
    </div>
    <div>{{ $notifications->links() }}</div>
</div></body></html>
