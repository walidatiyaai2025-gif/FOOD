<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('smart_ordering_qr.page_title') }}</title>
@include('admin._brand-components')
<style>
.so-qr-shell{display:grid;gap:var(--foodex-space-4);min-width:0}
.so-qr-shell .foodex-page-toolbar{margin-bottom:0}
.so-qr-toolbar{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);flex-wrap:wrap}
.so-qr-filter-grid,.so-qr-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:var(--foodex-space-3)}
.so-qr-filter-grid label,.so-qr-form-grid label{display:grid;gap:6px;font-size:var(--foodex-text-sm);font-weight:var(--foodex-font-weight-medium)}
.so-qr-filter-grid input,.so-qr-filter-grid select,.so-qr-form-grid input,.so-qr-form-grid select,.so-qr-form-grid textarea{width:100%;min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:10px 12px;background:var(--foodex-surface);color:inherit;font:inherit;box-sizing:border-box}
.so-qr-form-grid textarea{min-height:90px;resize:vertical}
.so-qr-span-2{grid-column:span 2}
.so-qr-card{padding:var(--foodex-space-4)}
.so-qr-table-wrap{width:100%;overflow:auto}
.so-qr-table{width:100%;border-collapse:collapse}
.so-qr-table th,.so-qr-table td{padding:12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}
.so-qr-table th{font-size:var(--foodex-text-xs);color:var(--foodex-muted);background:var(--foodex-background)}
.so-qr-meta{display:grid;gap:4px;font-size:var(--foodex-text-xs);color:var(--foodex-muted)}
.so-qr-url{display:block;max-width:270px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;text-align:left}
.so-qr-preview{display:block;width:96px;height:96px;object-fit:contain;margin-bottom:8px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);padding:6px}
.so-qr-funnel{display:grid;grid-template-columns:repeat(2,minmax(72px,1fr));gap:6px}
.so-qr-kpi{padding:7px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-background)}
.so-qr-kpi strong{display:block;font-size:var(--foodex-text-sm)}
.so-qr-badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.so-qr-badge.off{background:var(--foodex-background);color:var(--foodex-muted)}
.so-qr-actions summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;justify-content:center;min-width:44px;min-height:44px;border-radius:var(--foodex-radius-control);background:var(--foodex-green);color:var(--foodex-surface);font-weight:700}
.so-qr-actions summary::-webkit-details-marker{display:none}
.so-qr-actions-menu{display:grid;gap:6px;min-width:210px;margin-top:6px;padding:8px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);box-shadow:var(--foodex-shadow)}
.so-qr-actions-menu button,.so-qr-actions-menu a{display:flex;align-items:center;min-height:40px;border:0;border-radius:var(--foodex-radius-control);padding:8px 10px;background:transparent;color:inherit;text-decoration:none;cursor:pointer;font:inherit;text-align:start}
.so-qr-actions-menu button:hover,.so-qr-actions-menu a:hover{background:var(--foodex-green-soft)}
.so-qr-dialog{border:0;border-radius:var(--foodex-radius-card);padding:0;width:min(780px,calc(100vw - 24px));max-height:90vh;box-shadow:var(--foodex-shadow)}
.so-qr-dialog-inner{padding:var(--foodex-space-5);display:grid;gap:var(--foodex-space-4);max-height:90vh;overflow:auto}
.so-qr-dialog-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
.so-qr-dialog-head h2{margin:0}
.so-qr-dialog-close{min-width:44px;min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);cursor:pointer}
.so-qr-help{margin:0;color:var(--foodex-muted);font-size:var(--foodex-text-xs);line-height:1.5}
.so-qr-quick-setup,.so-qr-context-box,.so-qr-create-summary,.so-qr-advanced{border:1px solid var(--foodex-border);border-radius:14px;padding:12px;background:var(--foodex-background)}
.so-qr-create-summary{font-weight:700;color:var(--foodex-green-dark);background:var(--foodex-green-soft)}
.so-qr-advanced>summary{cursor:pointer;font-weight:700}
.so-qr-advanced>.so-qr-form-grid{margin-top:12px}
.so-qr-status{padding:12px 16px}
.so-qr-empty{text-align:center!important;padding:30px!important;color:var(--foodex-muted)}
@media(max-width:767px){
 .so-qr-span-2{grid-column:span 1}
 .so-qr-card{padding:var(--foodex-space-3)}
 .so-qr-table-wrap{overflow:visible}
}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-smart-ordering-qr-manager="true">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
@include('admin._global-header')
        <header class="foodex-page-toolbar">
            <div>
                <span class="foodex-subtitle">{{ __('smart_ordering_qr.nav_title') }}</span>
                <h1>{{ __('smart_ordering_qr.heading') }}</h1>
                <p>{{ __('smart_ordering_qr.description') }}</p>
            </div>
            <div class="foodex-header-actions">
                
                @if($canCreate)
                    <button type="button" class="foodex-action-primary" data-open-dialog="so-qr-create">{{ __('smart_ordering_qr.create') }}</button>
                @endif
            </div>
        </header>

        @include('admin._smart-ordering-workspace-nav')

        <div class="so-qr-shell">
            @if(session('status'))<div class="foodex-card so-qr-status" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())
                <div class="foodex-card so-qr-status" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <section class="foodex-card so-qr-card">
                <div class="so-qr-toolbar"><strong>{{ __('smart_ordering_qr.filters') }}</strong></div>
                <form method="get" class="so-qr-filter-grid" style="margin-top:12px">
                    <label>{{ __('smart_ordering_qr.store') }}
                        <select name="store_id">
                            <option value="">{{ __('smart_ordering_qr.all_stores') }}</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }} · {{ $store->code }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_qr.type') }}
                        <select name="type">
                            <option value="">{{ __('smart_ordering_qr.all_types') }}</option>
                            @foreach($types as $type)<option value="{{ $type }}" @selected(($filters['type']??'')===$type)>{{ __('smart_ordering_qr.types.'.$type) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_qr.state') }}
                        <select name="state">
                            <option value="">{{ __('smart_ordering_qr.all_states') }}</option>
                            @foreach(['enabled','disabled','scheduled','expired'] as $state)<option value="{{ $state }}" @selected(($filters['state']??'')===$state)>{{ __('smart_ordering_qr.states.'.$state) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_qr.search') }}
                        <input name="q" value="{{ $filters['q']??'' }}" placeholder="{{ __('smart_ordering_qr.search_placeholder') }}">
                    </label>
                    <div style="align-self:end"><button class="foodex-action-primary" type="submit">{{ __('smart_ordering_qr.search') }}</button></div>
                </form>
            </section>

            <section class="foodex-card so-qr-card">
                <div class="so-qr-table-wrap">
                    <table class="foodex-table so-qr-table">
                        <thead><tr>
                            <th>{{ __('smart_ordering_qr.label') }}</th>
                            <th>{{ __('smart_ordering_qr.context') }}</th>
                            <th>{{ __('smart_ordering_qr.state') }}</th>
                            <th>{{ __('smart_ordering_qr.public_url') }}</th>
                            <th>{{ __('smart_ordering_qr.metrics') }}</th>
                            <th>{{ __('smart_ordering_qr.actions') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse($qrs as $qr)
                            @php
                                $m=$metrics[$qr->id]??['scans'=>0,'unique_visits'=>0,'carts'=>0,'orders'=>0,'conversion'=>0,'revenue'=>0];
                                $expired=$qr->valid_until && !$qr->valid_until->isFuture();
                                $scheduled=$qr->valid_from && $qr->valid_from->isFuture();
                                $state=!$qr->is_enabled?'disabled':($expired?'expired':($scheduled?'scheduled':'enabled'));
                                $canManage=$user->hasRole('SUPER_ADMIN') || $user->hasPermission('smart_ordering.qr.manage',(int)$qr->store_id);
                            @endphp
                            <tr>
                                <td data-label="{{ __('smart_ordering_qr.label') }}">
                                    <strong>{{ $qr->label }}</strong>
                                    <div class="so-qr-meta"><span>{{ __('smart_ordering_qr.types.'.$qr->type) }}</span><span>{{ $qr->store->name }}</span></div>
                                </td>
                                @php
                                    $contextBranch = $qr->branch_reference
                                        ? __('smart_ordering_qr.context_branch', ['value' => $qr->branch_reference])
                                        : null;
                                    $contextTable = $qr->table_reference
                                        ? __('smart_ordering_qr.context_table', ['value' => $qr->table_reference])
                                        : null;
                                    $contextCampaign = $qr->campaign
                                        ? __('smart_ordering_qr.context_campaign', ['value' => $qr->campaign])
                                        : null;
                                    $contextPromotion = $qr->promotion
                                        ? __('smart_ordering_qr.context_promotion', ['value' => $qr->promotion->name])
                                        : null;
                                @endphp
                                <td data-label="{{ __('smart_ordering_qr.context') }}">
                                    <div class="so-qr-meta">
                                        @if($contextBranch)<span>{{ $contextBranch }}</span>@endif
                                        @if($contextTable)<span>{{ $contextTable }}</span>@endif
                                        @if($contextCampaign)<span>{{ $contextCampaign }}</span>@endif
                                        @if($contextPromotion)<span>{{ $contextPromotion }}</span>@endif
                                    </div>
                                </td>
                                <td data-label="{{ __('smart_ordering_qr.state') }}"><span class="so-qr-badge {{ $state==='enabled'?'':'off' }}">{{ __('smart_ordering_qr.states.'.$state) }}</span></td>
                                <td data-label="{{ __('smart_ordering_qr.public_url') }}">
                                    @if($qr->public_url)
                                        <img class="so-qr-preview" src="{{ route('admin.smart-ordering.qr.svg',$qr) }}" alt="{{ $qr->label }}">
                                        <code class="so-qr-url">{{ $qr->public_url }}</code>
                                    @endif
                                </td>
                                <td data-label="{{ __('smart_ordering_qr.metrics') }}">
                                    <div class="so-qr-funnel">
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ $m['scans'] }}</strong><span>{{ __('smart_ordering_qr.scans') }}</span></div>
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ $m['unique_visits'] }}</strong><span>{{ __('smart_ordering_qr.unique_visits') }}</span></div>
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ $m['carts'] }}</strong><span>{{ __('smart_ordering_qr.carts') }}</span></div>
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ $m['orders'] }}</strong><span>{{ __('smart_ordering_qr.orders') }}</span></div>
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ number_format($m['conversion'],2) }}%</strong><span>{{ __('smart_ordering_qr.conversion') }}</span></div>
                                        <div class="so-qr-kpi"><strong class="foodex-number">{{ number_format($m['revenue'],3) }}</strong><span>{{ __('smart_ordering_qr.revenue') }}</span></div>
                                    </div>
                                </td>
                                <td data-label="{{ __('smart_ordering_qr.actions') }}">
                                    <details class="so-qr-actions" data-smart-ordering-qr-actions>
                                        <summary aria-label="{{ __('smart_ordering_qr.actions') }}">⋯</summary>
                                        <div class="so-qr-actions-menu">
                                            @if($qr->public_url)<button type="button" data-copy-url="{{ $qr->public_url }}">{{ __('smart_ordering_qr.copy_url') }}</button>@endif
                                            <a href="{{ route('admin.smart-ordering.qr.svg',[$qr,'download'=>1]) }}">{{ __('smart_ordering_qr.download_svg') }}</a>
                                            <a href="{{ route('admin.smart-ordering.qr.pdf',$qr) }}">{{ __('smart_ordering_qr.download_pdf') }}</a>
                                            @if($canManage)
                                                <button type="button" data-open-dialog="so-qr-edit-{{ $qr->public_id }}">{{ __('smart_ordering_qr.edit') }}</button>
                                                <button type="button" data-open-dialog="so-qr-rotate-{{ $qr->public_id }}">{{ __('smart_ordering_qr.rotate_token') }}</button>
                                                <form method="post" action="{{ route('admin.smart-ordering.qr.toggle',$qr) }}">@csrf<button type="submit" data-submit-once>{{ __('smart_ordering_qr.toggle') }}</button></form>
                                            @endif
                                        </div>
                                    </details>
                                </td>
                            </tr>

                            @if($canManage)
                            <dialog class="so-qr-dialog" id="so-qr-edit-{{ $qr->public_id }}">
                                <form method="post" action="{{ route('admin.smart-ordering.qr.update',$qr) }}" class="so-qr-dialog-inner">
                                    @csrf @method('PATCH')
                                    <div class="so-qr-dialog-head"><h2>{{ __('smart_ordering_qr.edit') }}</h2><button class="so-qr-dialog-close" type="button" data-close-dialog aria-label="{{ __('smart_ordering_qr.cancel') }}">×</button></div>
                                    <div class="so-qr-form-grid">@include('admin._smart-ordering-qr-fields',['editingQr'=>$qr])</div>
                                    <div class="foodex-header-actions"><button class="foodex-action-primary" type="submit" data-submit-once>{{ __('smart_ordering_qr.save') }}</button></div>
                                </form>
                            </dialog>
                            <dialog class="so-qr-dialog" id="so-qr-rotate-{{ $qr->public_id }}">
                                <form method="post" action="{{ route('admin.smart-ordering.qr.rotate',$qr) }}" class="so-qr-dialog-inner">
                                    @csrf
                                    <div class="so-qr-dialog-head"><h2>{{ __('smart_ordering_qr.rotate_token') }}</h2><button class="so-qr-dialog-close" type="button" data-close-dialog aria-label="{{ __('smart_ordering_qr.cancel') }}">×</button></div>
                                    <p class="so-qr-help">{{ __('smart_ordering_qr.rotate_hint') }}</p>
                                    <label><input type="hidden" name="keep_previous" value="0"><input type="checkbox" name="keep_previous" value="1" checked> {{ __('smart_ordering_qr.keep_previous') }}</label>
                                    <label>{{ __('smart_ordering_qr.legacy_expires_at') }}<input type="datetime-local" name="legacy_expires_at"></label>
                                    <div><button class="foodex-action-primary" type="submit" data-submit-once>{{ __('smart_ordering_qr.rotate_token') }}</button></div>
                                </form>
                            </dialog>
                            @endif
                        @empty
                            <tr><td colspan="6" class="so-qr-empty">{{ __('smart_ordering_qr.no_results') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:16px">{{ $qrs->links() }}</div>
            </section>
        </div>
    </main>
</div>

@if($canCreate)
<dialog class="so-qr-dialog" id="so-qr-create">
    <form method="post" action="{{ route('admin.smart-ordering.qr.store') }}" class="so-qr-dialog-inner">
        @csrf
        <div class="so-qr-dialog-head"><h2>{{ __('smart_ordering_qr.create') }}</h2><button class="so-qr-dialog-close" type="button" data-close-dialog aria-label="{{ __('smart_ordering_qr.cancel') }}">×</button></div>
        <div class="so-qr-form-grid">
            <label>{{ __('smart_ordering_qr.store') }}
                <select name="store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }} · {{ $store->code }}</option>@endforeach</select>
            </label>
            @include('admin._smart-ordering-qr-fields',['editingQr'=>null])
            <input type="hidden" name="is_enabled" value="1">
        </div>
        <div class="foodex-header-actions"><button class="foodex-action-primary" type="submit" data-submit-once>{{ __('smart_ordering_qr.save') }}</button></div>
    </form>
</dialog>
@endif

<script>
(() => {
    const form = document.querySelector('#so-qr-create form');
    if (!form) return;

    const preset = form.querySelector('[data-qr-preset]');
    const type = form.querySelector('[data-qr-type]');
    const advancedType = form.querySelector('[data-qr-advanced-type]');
    const tableFields = form.querySelector('[data-qr-table-fields]');
    const campaignFields = form.querySelector('[data-qr-campaign-fields]');
    const summary = form.querySelector('[data-qr-create-summary]');
    const tableInput = form.querySelector('[data-qr-table-input]');
    const campaignInput = form.querySelector('[data-qr-campaign-input]');

    const summaries = {
        generic: @json(__('smart_ordering_qr.summary_menu')),
        table: @json(__('smart_ordering_qr.summary_table')),
        campaign: @json(__('smart_ordering_qr.summary_campaign')),
    };

    const sync = (value) => {
        value = value || 'generic';
        if (type) type.value = value;
        if (advancedType && advancedType.value !== value) advancedType.value = value;
        if (preset && ['generic','table','campaign'].includes(value) && preset.value !== value) preset.value = value;

        if (tableFields) tableFields.hidden = value !== 'table';
        if (campaignFields) campaignFields.hidden = value !== 'campaign';
        if (tableInput) tableInput.required = value === 'table';
        if (campaignInput) campaignInput.required = false;
        if (summary) summary.textContent = summaries[value] || summaries.generic;
    };

    preset?.addEventListener('change', () => sync(preset.value));
    advancedType?.addEventListener('change', () => sync(advancedType.value));
    sync(type?.value || preset?.value || 'generic');
})();

document.addEventListener('click', async (event) => {
    const opener=event.target.closest('[data-open-dialog]');
    if(opener){document.getElementById(opener.dataset.openDialog)?.showModal();return;}
    const closer=event.target.closest('[data-close-dialog]');
    if(closer){closer.closest('dialog')?.close();return;}
    const copy=event.target.closest('[data-copy-url]');
    if(copy){
        try{await navigator.clipboard.writeText(copy.dataset.copyUrl);copy.textContent=@json(__('smart_ordering_qr.copied'));}
        catch(error){window.prompt(@json(__('smart_ordering_qr.copy_failed')),copy.dataset.copyUrl);}
    }
});
document.addEventListener('submit',(event)=>{
    const button=event.target.querySelector('[data-submit-once]');
    if(button){button.disabled=true;button.setAttribute('aria-busy','true');}
});
</script>
@include('admin._new-order-popup')
@include('admin._whatsapp-inbound-popup')
</body>
</html>
