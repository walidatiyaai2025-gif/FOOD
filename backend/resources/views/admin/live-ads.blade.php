<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('live_ads.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
        .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}.panel,.card{padding:18px;margin-bottom:16px}.cards{display:grid;gap:14px}.actions{display:flex;gap:8px;flex-wrap:wrap}.preview{display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:center}.preview img{width:140px;height:110px;object-fit:cover;border-radius:12px;border:1px solid var(--foodex-border)}
        label{display:grid;gap:6px;font-weight:700;font-size:.82rem}.checks{display:flex;gap:18px;align-items:center}.checks label{display:flex;flex-direction:row;align-items:center}.checks input{min-height:auto!important;width:auto!important}
        @media(max-width:900px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1;border:0;border-bottom:1px solid var(--foodex-border)}.main{grid-row:2;padding:16px}.grid{grid-template-columns:1fr}.preview{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell foodex-admin-layout">
<main class="main foodex-admin-main foodex-admin-page">
    <header class="foodex-page-header"><div><h1>{{ __('live_ads.title') }}</h1><p>{{ __('live_ads.description') }}</p></div></header>
    @if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <section class="panel foodex-card">
        <h2>{{ __('live_ads.create') }}</h2>
        <form method="post" action="{{ route('admin.live-ads.store') }}" enctype="multipart/form-data" class="grid foodex-premium-auto-form">
            @csrf
            <label>{{ __('live_ads.name') }}<input name="name" required></label>
            <label>{{ __('live_ads.channel') }}<select name="channel" class="js-live-channel">@if($canB2b)<option value="b2b">{{ __('live_ads.all_wholesale') }}</option>@endif<option value="b2c">{{ __('live_ads.retail') }}</option></select></label>
            <label>{{ __('live_ads.store') }}<select name="store_id" class="js-live-store"><option value="">—</option>@foreach($retailStores as $store)<option value="{{ $store['id'] }}">{{ $store['name'] }}</option>@endforeach</select></label>
            <label>{{ __('live_ads.title_ar') }}<input name="title_ar" dir="rtl" required></label>
            <label>{{ __('live_ads.title_en') }}<input name="title_en" dir="ltr" required></label>
            <label>{{ __('live_ads.image') }}<input name="image" type="file" accept="image/png,image/jpeg,image/webp"></label>
            <label>{{ __('live_ads.body_ar') }}<textarea name="body_ar" dir="rtl"></textarea></label>
            <label>{{ __('live_ads.body_en') }}<textarea name="body_en" dir="ltr"></textarea></label>
            <label>{{ __('live_ads.cta_target') }}<input name="cta_target" placeholder="/offers"></label>
            <label>{{ __('live_ads.cta_ar') }}<input name="cta_label_ar"></label>
            <label>{{ __('live_ads.cta_en') }}<input name="cta_label_en"></label>
            <label>{{ __('live_ads.frequency') }}<select name="frequency">@foreach(['once_per_install','once_per_session','every_open'] as $v)<option value="{{ $v }}">{{ __('live_ads.'.$v) }}</option>@endforeach</select></label>
            <label>{{ __('live_ads.priority') }}<input name="priority" type="number" min="0" max="9999" value="100" required></label>
            <label>{{ __('live_ads.starts_at') }}<input name="starts_at" type="datetime-local"></label>
            <label>{{ __('live_ads.ends_at') }}<input name="ends_at" type="datetime-local"></label>
            <label>{{ __('live_ads.duration_minutes') }}<input name="duration_minutes" type="number" min="1" max="525600"></label>
            <div class="full checks"><label><input type="hidden" name="is_dismissible" value="0"><input name="is_dismissible" type="checkbox" value="1" checked> {{ __('live_ads.dismissible') }}</label><label><input type="hidden" name="is_active" value="0"><input name="is_active" type="checkbox" value="1" checked> {{ __('live_ads.active') }}</label></div>
            <div class="full"><button class="foodex-primary" type="submit">{{ __('live_ads.save') }}</button></div>
        </form>
    </section>

    <form method="get" class="panel foodex-premium-auto-form">
        <label>{{ __('live_ads.search') }}<input name="q" value="{{ $search }}"></label>
        <label>{{ __('live_ads.channel') }}<select name="channel"><option value="">{{ __('live_ads.all') }}</option><option value="b2b" @selected($channelFilter==='b2b')>B2B</option><option value="b2c" @selected($channelFilter==='b2c')>B2C</option></select></label>
        <label>{{ __('live_ads.status') }}<select name="status"><option value="">{{ __('live_ads.all') }}</option><option value="active" @selected($statusFilter==='active')>{{ __('live_ads.active') }}</option><option value="inactive" @selected($statusFilter==='inactive')>{{ __('live_ads.inactive') }}</option></select></label>
        <button class="foodex-filter-action">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button>
    </form>

    <div class="cards">
    @forelse($ads as $ad)
        @php
            $adChannelCode = strtolower((string)$ad->channel);
            $adChannelLabel = match($adChannelCode) {
                'b2b', 'wholesale' => app()->getLocale()==='ar' ? 'جملة' : 'Wholesale',
                'b2c', 'retail' => app()->getLocale()==='ar' ? 'تجزئة' : 'Retail',
                default => app()->getLocale()==='ar' ? 'قناة الإعلان' : 'Ad channel',
            };
        @endphp
        <article class="card foodex-card">
            <div class="preview">
                <div>@if($ad->image_path)<img src="{{ url('/'.ltrim($ad->image_path,'/')) }}" alt="">@else<div class="foodex-empty-state" style="min-height:110px">FOODEX</div>@endif</div>
                <div><strong>#{{ $ad->id }} · {{ $ad->name }}</strong><p>{{ app()->getLocale()==='ar'?$ad->title_ar:$ad->title_en }}</p><small>{{ $adChannelLabel }} @if($ad->store) · {{ $ad->store->name }} @endif · {{ $ad->frequency }} · {{ $ad->starts_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') }} → {{ $ad->ends_at?->timezone('Asia/Kuwait')->format('Y-m-d H:i') ?? '∞' }}</small></div>
            </div>
            <details data-foodex-operational-modal style="margin-top:12px"><summary style="cursor:pointer;font-weight:800">{{ __('live_ads.update') }}</summary>
                <form method="post" action="{{ route('admin.live-ads.update',$ad) }}" enctype="multipart/form-data" class="grid foodex-premium-auto-form" style="margin-top:12px">@csrf @method('PATCH')
                    <label>{{ __('live_ads.name') }}<input name="name" value="{{ $ad->name }}" required></label>
                    <label>{{ __('live_ads.channel') }}<select name="channel" class="js-live-channel">@if($canB2b)<option value="b2b" @selected($ad->channel==='b2b')>{{ __('live_ads.all_wholesale') }}</option>@endif<option value="b2c" @selected($ad->channel==='b2c')>{{ __('live_ads.retail') }}</option></select></label>
                    <label>{{ __('live_ads.store') }}<select name="store_id" class="js-live-store"><option value="">—</option>@foreach($retailStores as $store)<option value="{{ $store['id'] }}" @selected((int)$ad->store_id===$store['id'])>{{ $store['name'] }}</option>@endforeach</select></label>
                    <label>{{ __('live_ads.title_ar') }}<input name="title_ar" value="{{ $ad->title_ar }}" required></label>
                    <label>{{ __('live_ads.title_en') }}<input name="title_en" value="{{ $ad->title_en }}" required></label>
                    <label>{{ __('live_ads.image') }}<input name="image" type="file" accept="image/png,image/jpeg,image/webp"></label>
                    <label>{{ __('live_ads.body_ar') }}<textarea name="body_ar">{{ $ad->body_ar }}</textarea></label>
                    <label>{{ __('live_ads.body_en') }}<textarea name="body_en">{{ $ad->body_en }}</textarea></label>
                    <label>{{ __('live_ads.cta_target') }}<input name="cta_target" value="{{ $ad->cta_target }}"></label>
                    <label>{{ __('live_ads.cta_ar') }}<input name="cta_label_ar" value="{{ $ad->cta_label_ar }}"></label>
                    <label>{{ __('live_ads.cta_en') }}<input name="cta_label_en" value="{{ $ad->cta_label_en }}"></label>
                    <label>{{ __('live_ads.frequency') }}<select name="frequency">@foreach(['once_per_install','once_per_session','every_open'] as $v)<option value="{{ $v }}" @selected($ad->frequency===$v)>{{ __('live_ads.'.$v) }}</option>@endforeach</select></label>
                    <label>{{ __('live_ads.priority') }}<input name="priority" type="number" value="{{ $ad->priority }}" min="0" max="9999" required></label>
                    <label>{{ __('live_ads.starts_at') }}<input name="starts_at" type="datetime-local" value="{{ $ad->starts_at?->timezone('Asia/Kuwait')->format('Y-m-d\TH:i') }}"></label>
                    <label>{{ __('live_ads.ends_at') }}<input name="ends_at" type="datetime-local" value="{{ $ad->ends_at?->timezone('Asia/Kuwait')->format('Y-m-d\TH:i') }}"></label>
                    <label>{{ __('live_ads.duration_minutes') }}<input name="duration_minutes" type="number" value="{{ $ad->duration_minutes }}" min="1"></label>
                    <div class="full checks"><label><input type="hidden" name="is_dismissible" value="0"><input name="is_dismissible" type="checkbox" value="1" @checked($ad->is_dismissible)> {{ __('live_ads.dismissible') }}</label><label><input type="hidden" name="is_active" value="0"><input name="is_active" type="checkbox" value="1" @checked($ad->is_active)> {{ __('live_ads.active') }}</label></div>
                    <div class="full"><button class="foodex-primary">{{ __('live_ads.update') }}</button></div>
                </form>
            </details>
            <div class="actions" style="margin-top:12px">
                <form method="post" action="{{ route('admin.live-ads.toggle',$ad) }}">@csrf @method('PATCH')<button class="secondary">{{ __('live_ads.toggle') }}</button></form>
                <form method="post" action="{{ route('admin.live-ads.destroy',$ad) }}">@csrf @method('DELETE')<button class="danger">{{ __('live_ads.delete') }}</button></form>
            </div>
        </article>
    @empty
        <div class="foodex-empty-state">{{ __('live_ads.empty') }}</div>
    @endforelse
    </div>
    <div>{{ $ads->links() }}</div>
</main>
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>$navGroups,'navContext'=>$navContext])</aside>
</div>
<script>
document.querySelectorAll('form').forEach((form)=>{
  const channel=form.querySelector('.js-live-channel'); const store=form.querySelector('.js-live-store');
  const sync=()=>{if(!channel||!store)return; const retail=channel.value==='b2c'; store.required=retail; if(!retail)store.value='';};
  channel?.addEventListener('change',sync); sync();
});
</script>
</body></html>
