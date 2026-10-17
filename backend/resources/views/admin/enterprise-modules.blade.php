<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('enterprise_modules.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.module-page{padding:clamp(18px,2.5vw,36px)}
.module-header{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-block:18px 24px}
.module-header h1{margin:4px 0 8px;font-size:clamp(1.55rem,2.5vw,2.2rem)}
.module-header p{margin:0;color:var(--foodex-muted);max-width:760px;line-height:1.55}
.module-back{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding-inline:14px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;color:var(--foodex-green-dark);text-decoration:none;font-weight:800}
.module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.module-card{background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:18px;box-shadow:var(--foodex-shadow-sm)}
.module-card h2,.module-card h3{margin:0}
.module-card__head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.module-state{display:inline-flex;align-items:center;min-height:30px;padding-inline:10px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;font-size:.85rem}
.module-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-top:16px}
.module-meta>div,.module-block{padding:12px;border:1px solid var(--foodex-border);border-radius:10px;background:var(--foodex-background)}
.module-meta span,.module-block>span{display:block;color:var(--foodex-muted);font-size:.82rem;margin-bottom:5px}
.module-list{margin:6px 0 0;padding-inline-start:20px}
.module-entitlements{display:flex;flex-wrap:wrap;gap:7px;margin-top:7px}
.module-chip{display:inline-flex;align-items:center;min-height:30px;padding-inline:9px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;font-size:.84rem}
.module-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.module-actions form{margin:0}
.module-action,.module-open{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding-inline:13px;border-radius:10px;border:1px solid var(--foodex-green);background:var(--foodex-green);color:#fff;text-decoration:none;font:inherit;font-weight:800;cursor:pointer}
.module-action.secondary,.module-open{background:#fff;color:var(--foodex-green-dark)}
.module-selected{margin-bottom:18px;border:2px solid var(--foodex-green)}
.module-state-message{margin-bottom:14px;padding:12px;border-radius:10px;background:#fff;border:1px solid var(--foodex-border)}
.module-empty{padding:24px;background:#fff;border:1px dashed var(--foodex-border);border-radius:12px;color:var(--foodex-muted)}
.module-filters{margin-block:16px;padding:16px;background:#fff;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card)}
.module-filters label{display:grid;gap:6px;font-weight:700;min-width:0}
.module-filters input,.module-filters select{width:100%;min-height:44px;border:1px solid var(--foodex-border);border-radius:10px;padding-inline:10px;font:inherit;background:#fff}
.module-filter-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.module-reset{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding-inline:13px;border:1px solid var(--foodex-border);border-radius:10px;color:var(--foodex-green-dark);text-decoration:none;font-weight:800;background:#fff}
.module-columns{min-width:0}
.module-columns>summary{min-height:44px;display:flex;align-items:center;cursor:pointer;font-weight:800}
.module-columns__items{display:grid;gap:7px;padding-top:8px}
.module-columns__items label{display:flex;align-items:center;gap:7px;font-weight:600}
.module-result-summary{margin:12px 0;color:var(--foodex-muted);font-size:.9rem}
@media(max-width:900px){.module-grid{grid-template-columns:1fr}.module-meta{grid-template-columns:1fr}.module-header{flex-direction:column}.module-back{width:100%}.module-card__head{flex-direction:column}}
</style>
</head>
<body>
<div class="foodex-admin-layout" data-foodex-mobile-web-page="enterprise-modules">
<aside class="sidebar">@include('admin._sidebar', ['navGroups' => $navGroups, 'navContext' => $navContext, 'user' => $user])</aside> {{-- localization-gate: allow Blade include expression --}}
<main class="foodex-admin-main foodex-admin-page module-page" data-enterprise-module-control-plane="true">
@include('admin._global-header')
@include('admin._admin-center-switcher',['active'=>'settings'])
<div class="module-header foodex-page-toolbar">
<div>
<div class="foodex-subtitle">FOODEX · {{ __('enterprise_modules.eyebrow') }}</div>
<h1>{{ __('enterprise_modules.title') }}</h1>
<p>{{ __('enterprise_modules.description') }}</p>
</div>
<a class="module-back" href="{{ route('admin.settings.index') }}">{{ __('enterprise_modules.back') }}</a>
</div>

@if (session('status')) <div class="module-state-message foodex-state" role="status">{{ session('status') }}</div> @endif
@if ($errors->any()) <div class="module-state-message foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif

@include('admin._enterprise-saved-views', [
    'surfaceKey' => 'enterprise.modules',
    'scopeType' => 'platform',
    'scopeId' => null,
    'stateToken' => $savedViewStateToken,
    'savedViews' => $savedViews,
    'applyRoute' => route('admin.enterprise-modules.index'),
])

<form method="get" action="{{ route('admin.enterprise-modules.index') }}" class="foodex-ops-toolbar module-filters" data-enterprise-grid-filters>
    <label>
        <span>{{ __('enterprise_grid.search') }}</span>
        <input type="search" name="q" value="{{ $gridState->search ?? '' }}" maxlength="120" placeholder="{{ __('enterprise_grid.search_placeholder') }}" autocomplete="off">
    </label>
    <label>
        <span>{{ __('enterprise_grid.state') }}</span>
        <select name="state">
            <option value="">{{ __('enterprise_grid.all_states') }}</option>
            @foreach($moduleStates as $state)
                <option value="{{ $state }}" @selected(($gridState->filters['state'] ?? null) === $state)>{{ __('enterprise_modules.states.'.$state) }}</option>
            @endforeach
        </select>
    </label>
    <label>
        <span>{{ __('enterprise_grid.sort') }}</span>
        <select name="sort">
            <option value="">{{ __('enterprise_grid.sort_default') }}</option>
            <option value="name" @selected($gridState->sortField === 'name')>{{ __('enterprise_grid.sort_name') }}</option>
            <option value="state" @selected($gridState->sortField === 'state')>{{ __('enterprise_grid.sort_state') }}</option>
        </select>
    </label>
    <label>
        <span>{{ __('enterprise_grid.direction') }}</span>
        <select name="direction">
            <option value="asc" @selected($gridState->sortDirection === 'asc')>{{ __('enterprise_grid.ascending') }}</option>
            <option value="desc" @selected($gridState->sortDirection === 'desc')>{{ __('enterprise_grid.descending') }}</option>
        </select>
    </label>
    <label>
        <span>{{ __('enterprise_grid.per_page') }}</span>
        <select name="per_page">
            @foreach([25,50,100] as $size)<option value="{{ $size }}" @selected($gridState->perPage === $size)>{{ $size }}</option>@endforeach
        </select>
    </label>
    <details class="module-columns">
        <summary>{{ __('enterprise_grid.columns') }}</summary>
        <div class="module-columns__items">
            @foreach([
                'category' => 'enterprise_grid.column_category',
                'state' => 'enterprise_grid.column_state',
                'dependencies' => 'enterprise_grid.column_dependencies',
                'entitlements' => 'enterprise_grid.column_entitlements',
                'capabilities' => 'enterprise_grid.column_capabilities',
            ] as $column => $label)
                <label><input type="checkbox" name="columns[]" value="{{ $column }}" @checked(in_array($column, $visibleColumns, true))> <span>{{ __($label) }}</span></label>
            @endforeach
        </div>
    </details>
    <div class="module-filter-actions">
        <button class="foodex-primary" type="submit">{{ __('enterprise_grid.apply') }}</button>
        <a class="module-reset" href="{{ route('admin.enterprise-modules.index') }}">{{ __('enterprise_grid.reset') }}</a>
    </div>
</form>

@if($selected)
<section class="module-card module-selected" aria-labelledby="selected-module-title">
<div class="module-card__head">
<div><div class="foodex-subtitle">{{ __('enterprise_modules.details') }}</div><h2 id="selected-module-title">{{ $selected['name'] }}</h2></div>
<div><div class="foodex-subtitle">{{ __('enterprise_modules.state') }}</div><span class="module-state">{{ __('enterprise_modules.state') }} · {{ __('enterprise_modules.states.'.$selected['state']) }}</span></div>
</div>
<div class="module-meta">
<div><span>{{ __('enterprise_modules.category') }}</span><strong>{{ $selected['category'] }}</strong></div>
<div><span>{{ __('enterprise_modules.manifest_version') }}</span><strong>{{ $selected['manifest_version'] }}</strong></div>
<div><span>{{ __('enterprise_modules.capabilities') }} / {{ __('enterprise_modules.permissions') }}</span><strong>{{ $selected['capability_count'] }} / {{ $selected['permission_count'] }}</strong></div>
</div>
<div class="module-grid" style="margin-top:12px">
<div class="module-block"><span>{{ __('enterprise_modules.dependencies') }}</span>
@if($selected['dependencies'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <ul class="module-list">@foreach($selected['dependencies'] as $dependency)<li>{{ $dependency }}</li>@endforeach</ul>@endif
</div>
<div class="module-block"><span>{{ __('enterprise_modules.integrations') }}</span>
@if($selected['optional_integrations'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <ul class="module-list">@foreach($selected['optional_integrations'] as $integration)<li>{{ $integration }}</li>@endforeach</ul>@endif
</div>
</div>
<div class="module-block" style="margin-top:12px"><span>{{ __('enterprise_modules.entitlements') }}</span>
@if($selected['entitlements'] === []) <strong>{{ __('enterprise_modules.none') }}</strong>
@else <div class="module-entitlements">@foreach($selected['entitlements'] as $entitlement)
<span class="module-chip">{{ __("enterprise_modules.scope_types.{$entitlement['scope_type']}") }} · {{ $entitlement['enabled'] ? __('enterprise_modules.enabled') : __('enterprise_modules.denied') }} · {{ $entitlement['count'] }}</span>
@endforeach</div>@endif
</div>
@if($selected['actions'] !== [])
<div class="module-actions" aria-label="{{ __('enterprise_modules.actions') }}">
@foreach($selected['actions'] as $action)
<form method="post" action="{{ route('admin.enterprise-modules.lifecycle', ['module'=>$selected['code']]) }}">
@csrf
<input type="hidden" name="action" value="{{ $action }}">
<button class="module-action {{ $action === 'enable' ? '' : 'secondary' }}" type="submit">{{ __('enterprise_modules.action.'.$action) }}</button>
</form>
@endforeach
</div>
@endif
</section>
@endif

<h2>{{ __('enterprise_modules.all_modules') }}</h2>
<p class="module-result-summary">{{ __('enterprise_grid.showing', ['count' => $modules->count(), 'total' => $modules->total()]) }}</p>
@if($modules->count() === 0)
<p class="module-empty foodex-empty-state">{{ __('enterprise_modules.empty') }}</p>
@else
<div class="module-grid">
@foreach($modules as $module)
<article class="module-card">
<div class="module-card__head">
<div><h3>{{ $module['name'] }}</h3>@if(in_array('category',$visibleColumns,true))<div class="foodex-subtitle">{{ $module['category'] }}</div>@endif</div>
@if(in_array('state',$visibleColumns,true))<div><div class="foodex-subtitle">{{ __('enterprise_modules.state') }}</div><span class="module-state">{{ __('enterprise_modules.states.'.$module['state']) }}</span></div>@endif
</div>
@if(array_intersect(['dependencies','entitlements','capabilities'],$visibleColumns) !== [])
<div class="module-meta">
@if(in_array('dependencies',$visibleColumns,true))<div><span>{{ __('enterprise_modules.dependencies') }}</span><strong>{{ count($module['dependencies']) }}</strong></div>@endif
@if(in_array('entitlements',$visibleColumns,true))<div><span>{{ __('enterprise_modules.entitlements') }}</span><strong>{{ collect($module['entitlements'])->sum('count') }}</strong></div>@endif
@if(in_array('capabilities',$visibleColumns,true))<div><span>{{ __('enterprise_modules.capabilities') }}</span><strong>{{ $module['capability_count'] }}</strong></div>@endif
</div>
@endif
<div class="module-actions">
<a class="module-open" href="{{ route('admin.enterprise-modules.show', ['module'=>$module['code']]) }}?{{ http_build_query($gridState->toQuery()) }}">{{ __('enterprise_modules.open') }}</a>
</div>
</article>
@endforeach
</div>
<div class="foodex-pagination">{{ $modules->links() }}</div>
@endif
</main>
</div>
</body>
</html>
