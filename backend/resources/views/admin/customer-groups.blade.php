@php $direction = app()->getLocale() === 'ar' ? 'rtl' : 'ltr'; @endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('customer_groups.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
        .cg-layout{display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;direction:ltr}
        .cg-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface)}
        .cg-layout>main{grid-column:1;grid-row:1;min-width:0;padding:18px;direction:rtl}
        html[dir=ltr] .cg-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .cg-layout>.sidebar{grid-column:1;direction:ltr}
        html[dir=ltr] .cg-layout>main{grid-column:2;direction:ltr}
        .cg-card{background:var(--foodex-surface);padding:16px;border:1px solid var(--foodex-border);border-radius:16px;margin-bottom:14px}
        .cg-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .cg-row>*{min-width:0}.cg-card label{display:grid;gap:5px;font-weight:700}
        .cg-card input,.cg-card select{border:1px solid var(--foodex-border);border-radius:9px;padding:10px;background:#fff;max-width:100%;box-sizing:border-box}
        .cg-card select[multiple]{min-width:230px;max-width:100%;height:120px}
        .cg-card button{background:var(--foodex-action-green);color:#fff;border:0;border-radius:9px;padding:11px 18px;font-weight:800;cursor:pointer}
        .cg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:12px}
        .cg-muted{color:var(--foodex-muted)}.cg-error{color:var(--foodex-danger-text,firebrick)}
        @media(max-width:850px){.cg-layout,.cg-layout[dir]{display:block}.cg-layout>.sidebar{position:relative}.cg-layout>main{padding:12px}}
    </style>
</head>
<body>
<div class="foodex-admin-layout cg-layout" data-customer-group-management>
    <aside class="sidebar">@include('admin._sidebar', ['user'=>$user,'navGroups'=>$navGroups,'navContext'=>$navContext])</aside>
    <main class="foodex-admin-main foodex-admin-page">
        @include('admin._global-header')
        <header class="foodex-page-toolbar cg-card">
            <h1>{{ __('customer_groups.title') }}</h1>
            <p class="cg-muted">{{ __('customer_groups.description') }}</p>
            <a href="{{ route('admin.'.(($groupRoutePrefix === 'admin.b2c.customer-groups') ? 'b2c' : 'b2b').'.module', ['module'=>'customers']) }}">{{ __('customer_groups.back_to_customers') }}</a>
        </header>
        @if(session('status'))<div class="cg-card" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="cg-card cg-error" role="alert">{{ $errors->first() }}</div>@endif
        @if($canManage)
        <section class="cg-card">
            <h2>{{ __('customer_groups.create') }}</h2>
            <form method="post" action="{{ route($groupRoutePrefix.'.store') }}" class="cg-row">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <label>{{ __('customer_groups.name') }}<input name="name" required maxlength="255"></label>
                <label>{{ __('customer_groups.priority') }}<input type="number" name="priority" value="0" min="-1000" max="1000"></label>
                <button type="submit">{{ __('customer_groups.create') }}</button>
            </form>
        </section>
        @endif
        <section class="cg-card">
            <form method="get" class="cg-row">
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <label>{{ __('customer_groups.search') }}<input name="q" value="{{ request('q') }}"></label>
                <button type="submit">{{ __('customer_groups.search') }}</button>
            </form>
        </section>
        <div class="cg-grid">
        @forelse($groups as $group)
            @php
                $selectedMemberIds = ($members[$group->id] ?? collect())->pluck('customer_id')->map(fn($id)=>(int)$id)->all();
                $visibleMemberNames = $customers->filter(fn($customer) => in_array((int)$customer->id, $selectedMemberIds, true))->pluck('name')->take(12)->all();
            @endphp
            <section class="cg-card" data-customer-group-id="{{ $group->id }}">
                <h2>{{ $group->name }}</h2>
                <p class="cg-muted">{{ __('customer_groups.members_count', ['count' => $counts[$group->id] ?? 0]) }}</p>
                @if($visibleMemberNames !== [])
                    <p class="cg-muted" data-customer-group-members>{{ implode('، ', $visibleMemberNames) }}</p>
                @endif
                @if($canManage)
                <form method="post" action="{{ route($groupRoutePrefix.'.update', ['group'=>$group->id]) }}" class="cg-row">
                    @csrf @method('PATCH')
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    <label>{{ __('customer_groups.name') }}<input name="name" value="{{ $group->name }}" required maxlength="255"></label>
                    <label>{{ __('customer_groups.priority') }}<input type="number" name="priority" value="{{ $group->priority }}" min="-1000" max="1000"></label>
                    <label>{{ __('customer_groups.status') }}
                        <select name="is_active"><option value="1" @selected($group->is_active)>{{ __('customer_groups.active') }}</option><option value="0" @selected(!$group->is_active)>{{ __('customer_groups.inactive') }}</option></select>
                    </label>
                    <button type="submit">{{ __('customer_groups.save') }}</button>
                </form>
                <form method="post" action="{{ route($groupRoutePrefix.'.members', ['group'=>$group->id]) }}">
                    @csrf
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    <label>{{ __('customer_groups.select_customers') }}
                        <select name="customer_ids[]" multiple size="5" aria-label="{{ __('customer_groups.select_customers') }}">
                            @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} · {{ $customer->email }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="cg-muted">{{ __('customer_groups.selection_hint') }}</p>
                    <div class="cg-row">
                        <button type="submit" name="operation" value="add">{{ __('customer_groups.add') }}</button>
                        <button type="submit" name="operation" value="remove">{{ __('customer_groups.remove') }}</button>
                    </div>
                </form>
                @endif
            </section>
        @empty
            <div class="cg-card">{{ __('customer_groups.empty') }}</div>
        @endforelse
        </div>
        {{ $groups->links() }}
    </main>
</div>
</body>
</html>
