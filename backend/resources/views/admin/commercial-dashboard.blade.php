@php
    $ar = app()->getLocale() === 'ar';
    $scope = ['store_id' => $storeId] + ($supportAccess ? ['support_access' => 1] : []);
    $featureFlags = $featureFlags ?? [];
    $canManageFeatureFlags = (bool) ($canManageFeatureFlags ?? false);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $section === 'sales-control' ? __('commercial.sales.title') : __('commercial.flash.title') }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0;overflow-x:hidden;background:var(--foodex-background);color:var(--foodex-ink)}
        a{color:inherit}
        .commercial-admin-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
        .commercial-admin-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-inline-start:1px solid var(--foodex-border);min-height:100vh;position:relative;z-index:12}
        .commercial-admin-layout>.commercial-shell{grid-column:1;grid-row:1;direction:rtl;min-width:0}
        html[dir=ltr] .commercial-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
        html[dir=ltr] .commercial-admin-layout>.sidebar{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
        html[dir=ltr] .commercial-admin-layout>.commercial-shell{grid-column:2;direction:ltr}
        .commercial-shell{width:100%;max-width:none!important;margin:0;padding:var(--foodex-space-6) clamp(var(--foodex-space-4),2vw,var(--foodex-space-8)) var(--foodex-space-8);display:grid;gap:var(--foodex-space-4)}
        .commercial-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);flex-wrap:wrap}
        .commercial-page-header h1{margin:3px 0 6px;font-size:clamp(1.55rem,2.2vw,2rem);font-weight:800;line-height:1.2}
        .commercial-page-header p{margin:0;color:var(--foodex-muted);max-width:760px}
        .commercial-eyebrow{font-size:.76rem;font-weight:800;color:var(--foodex-green-dark);letter-spacing:.02em}
        .commercial-tabs{display:flex;gap:8px;flex-wrap:wrap;align-items:center;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:8px;box-shadow:var(--foodex-shadow-sm)}
        .commercial-tabs a{min-height:40px;padding:0 14px;border:1px solid transparent;border-radius:var(--foodex-radius-control);text-decoration:none;font-weight:800;display:inline-flex;align-items:center;justify-content:center;color:var(--foodex-muted)}
        .commercial-tabs a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .commercial-tabs a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green);box-shadow:0 8px 18px rgba(21,138,58,.14)}
        .contract-banner{padding:14px 16px;border:1px solid #cfe5d6;border-radius:var(--foodex-radius-card);background:linear-gradient(135deg,var(--foodex-green-soft),#fff);box-shadow:var(--foodex-shadow-sm);display:grid;grid-template-columns:auto minmax(0,1fr);gap:10px 14px;align-items:center}
        .contract-banner strong{margin:0;color:var(--foodex-green-dark)}
        .contract-banner.pending{border-style:dashed;background:#fffaf1}
        .muted{color:var(--foodex-muted)}
        .commercial-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
        .control-list{display:grid;gap:10px;margin-top:12px}
        .control-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--foodex-border);flex-wrap:wrap}
        .disabled-action{opacity:.55;cursor:not-allowed}
        .commercial-card{padding:var(--foodex-space-5);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
        .commercial-card h2,.commercial-card h3{margin-top:0}
        .feature-flags-card{display:grid;gap:14px}
        .feature-flags-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
        .feature-flags-head h2{margin:0;font-size:1.05rem}
        .feature-flag-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        .feature-flag{display:flex;align-items:center;gap:9px;min-height:54px;padding:10px 12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd;font-weight:700}
        .feature-flag input{width:18px;height:18px;accent-color:var(--foodex-green)}
        .feature-flag .flag-copy{display:grid;gap:2px}.feature-flag small{color:var(--foodex-muted);font-weight:500}
        .feature-save{display:flex;justify-content:flex-end;margin-top:10px}
        .commercial-product-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px}
        .commercial-product{padding:0;overflow:hidden}
        .commercial-product-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;border-bottom:1px solid var(--foodex-border);background:linear-gradient(145deg,#fff,#fbfcfd)}
        .commercial-product-head h3{margin:0 0 4px;font-size:1rem}.commercial-product-body{padding:18px;display:grid;gap:16px}
        .commercial-status{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-size:.72rem;font-weight:800}
        .policy-section{border:1px solid var(--foodex-border);border-radius:12px;padding:14px;background:#fff;display:grid;gap:12px}
        .policy-section-title{display:flex;justify-content:space-between;gap:10px;align-items:center;font-weight:800;font-size:.88rem}
        .commercial-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .commercial-form-grid.five{grid-template-columns:repeat(5,minmax(0,1fr))}
        .commercial-form-grid label,.policy-section>label{display:grid;gap:6px;font-weight:700;font-size:.8rem}
        .commercial-form-grid input,.commercial-form-grid select,.policy-section input,.policy-section select,.policy-section textarea{width:100%}
        .commercial-choice-grid{display:flex;gap:8px;flex-wrap:wrap}
        .commercial-choice{display:inline-flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:7px!important;padding:8px 10px;border:1px solid var(--foodex-border);border-radius:10px;background:#fbfcfd;font-size:.78rem!important}
        .commercial-choice input{width:16px!important;height:16px!important;accent-color:var(--foodex-green)}
        .commercial-toggles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .commercial-advanced{border:1px dashed var(--foodex-border);border-radius:12px;background:#fbfcfd}
        .commercial-advanced summary{cursor:pointer;padding:12px 14px;font-weight:800;color:var(--foodex-green-dark)}
        .commercial-advanced-body{padding:0 14px 14px;display:grid;gap:12px}
        .commercial-advanced textarea{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.76rem;min-height:110px}
        .structured-editor{display:grid;gap:10px}
        .structured-list{display:grid;gap:10px}
        .structured-row{padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd;display:grid;gap:10px}
        .structured-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}
        .structured-grid label{display:grid;gap:6px;font-weight:700;font-size:.78rem}
        .structured-choice{display:flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:7px!important;min-height:42px}
        .structured-choice input{width:16px!important;height:16px!important}
        .structured-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
        .structured-toolbar h4{margin:0;font-size:.9rem}
        .structured-add,.structured-remove{min-height:38px;padding:0 12px;border-radius:10px;font-weight:800;cursor:pointer}
        .structured-add{border:1px solid var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        .structured-remove{border:1px solid #f2b8b5;background:#fff;color:#a61b1b}
        .flash-form-section{border:1px solid var(--foodex-border);border-radius:12px;padding:14px;background:#fff;display:grid;gap:12px}
        .flash-form-section h3{margin:0;font-size:.92rem}
        .flash-multi{min-height:112px}
        .flash-product-builder{display:grid;gap:10px}
        .flash-product-row{display:grid;grid-template-columns:2fr 1.35fr 1fr 1fr auto;gap:10px;align-items:end;padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd}
        .flash-product-row label{display:grid;gap:6px;font-weight:700;font-size:.8rem}
        .flash-product-row select,.flash-product-row input{width:100%}
        .flash-row-remove{min-height:42px;border:1px solid #f2b8b5;border-radius:10px;background:#fff;color:#a61b1b;font-weight:800;cursor:pointer;padding:0 12px}
        .flash-form-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
        .flash-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 14px;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;text-decoration:none;font-weight:800;color:var(--foodex-green-dark)}
        .commercial-empty{min-height:220px;display:grid;place-items:center;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-card);background:linear-gradient(145deg,#fff,#fbfcfd);padding:var(--foodex-space-6)}
        .commercial-empty strong{display:block;font-size:1.05rem;margin-bottom:6px}.commercial-empty p{margin:0;color:var(--foodex-muted)}
        .commercial-table{width:100%;border-collapse:collapse}.commercial-table th,.commercial-table td{padding:11px;border-bottom:1px solid var(--foodex-border);text-align:start}
        .commercial-policy-form.foodex-premium-auto-form,.feature-flags-card form.foodex-premium-auto-form{display:grid!important;grid-template-columns:1fr!important;background:transparent!important;border:0!important;padding:0!important;box-shadow:none!important;gap:12px!important}
        @media(max-width:1100px){.feature-flag-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.commercial-form-grid.five{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:1023px){.commercial-admin-layout,.commercial-admin-layout:has(>.sidebar.foodex-sidebar-collapsed){grid-template-columns:1fr!important}.commercial-admin-layout>.sidebar,.commercial-admin-layout>.commercial-shell{grid-column:1!important;grid-row:auto!important}.commercial-admin-layout>.sidebar{min-height:auto}.commercial-shell{padding:14px}.commercial-product-grid{grid-template-columns:1fr}}
        @media(max-width:820px){.flash-product-row{grid-template-columns:1fr 1fr}.flash-product-row .flash-row-remove{grid-column:1/-1}.structured-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:680px){.feature-flag-grid,.commercial-form-grid,.commercial-form-grid.five,.commercial-toggles,.flash-product-row,.structured-grid{grid-template-columns:1fr}.contract-banner{grid-template-columns:1fr}.commercial-card{padding:14px}.commercial-product-head{align-items:flex-start;flex-direction:column}.flash-product-row .flash-row-remove{grid-column:auto}}
    </style>
</head>
<body>
<div class="foodex-admin-layout commercial-admin-layout" data-commercial-page="{{ $section }}">
    <aside class="sidebar">
        @include('admin._sidebar', ['user' => $user, 'navGroups' => $navGroups, 'navContext' => $navContext])
    </aside>
    <main class="foodex-admin-main foodex-admin-page commercial-shell">
        <header class="commercial-page-header">
            <div>
                <span class="commercial-eyebrow">FOODEX · {{ __('commercial.eyebrow') }}</span>
                <h1>{{ $section === 'sales-control' ? __('commercial.sales.title') : __('commercial.flash.title') }}</h1>
                <p>{{ $section === 'sales-control' ? __('commercial.sales.description') : __('commercial.flash.description') }}</p>
            </div>
            @include('admin._live-notifications', ['user' => $user])
        </header>
    <div class="commercial-tabs" aria-label="{{ __('commercial.tabs.aria') }}">
        <a class="{{ $section === 'sales-control' ? 'active' : '' }}" href="{{ route('admin.commercial.sales-control', $scope) }}">{{ __('commercial.tabs.sales_control') }}</a>
        <a class="{{ $section === 'flash-offers' ? 'active' : '' }}" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ __('commercial.tabs.flash_offers') }}</a>
        <a href="{{ route('admin.b2c.module', ['module' => $section === 'sales-control' ? 'products' : 'promotions'] + $scope) }}">{{ __('commercial.tabs.back_store') }}</a>
    </div>

    <section class="contract-banner {{ $contractReady ? '' : 'pending' }}" data-contract-ready="{{ $contractReady ? '1' : '0' }}">
        <strong>{{ $contractReady ? __('commercial.contract.ready_title') : __('commercial.contract.pending_title') }}</strong>
        <span class="muted">
            {{ $contractReady ? __('commercial.contract.ready_description') : __('commercial.contract.pending_description') }}
        </span>
    </section>

    <section class="commercial-card feature-flags-card" data-commercial-feature-flags>
        <div class="feature-flags-head">
            <div>
                <h2>{{ __('commercial.flags.title') }}</h2>
                <p class="muted">{{ __('commercial.flags.description') }}</p>
            </div>
            <span class="commercial-status">{{ __('commercial.flags.central_policy') }}</span>
        </div>
        @if($canManageFeatureFlags)
            <form method="post" action="{{ route('admin.commercial.feature-flags.save', $scope) }}" class="control-list">
                @csrf @method('put')
                <div class="feature-flag-grid">
                @foreach([
                    'commercial_rules_enabled' => ['commercial.flags.commercial_rules.label', 'commercial.flags.commercial_rules.description'],
                    'flash_offers_enabled' => ['commercial.flags.flash_offers.label', 'commercial.flags.flash_offers.description'],
                    'customer_flash_popup_enabled' => ['commercial.flags.customer_flash_popup.label', 'commercial.flags.customer_flash_popup.description'],
                    'van_offers_enabled' => ['commercial.flags.van_offers.label', 'commercial.flags.van_offers.description'],
                ] as $flagKey => $flagMeta)
                    <label class="feature-flag">
                        <input type="hidden" name="{{ $flagKey }}" value="0">
                        <input type="checkbox" name="{{ $flagKey }}" value="1" @checked((bool)($featureFlags[$flagKey] ?? false))>
                        <span class="flag-copy"><span>{{ __($flagMeta[0]) }}</span><small>{{ __($flagMeta[1]) }}</small></span>
                    </label>
                @endforeach
                </div>
                <div class="feature-save"><button type="submit" class="foodex-primary">{{ __('commercial.flags.save') }}</button></div>
            </form>
        @else
            <div class="feature-flag-grid">
                @foreach([
                    'commercial_rules_enabled' => ['commercial.flags.commercial_rules.label', 'commercial.flags.commercial_rules.description'],
                    'flash_offers_enabled' => ['commercial.flags.flash_offers.label', 'commercial.flags.flash_offers.description'],
                    'customer_flash_popup_enabled' => ['commercial.flags.customer_flash_popup.label', 'commercial.flags.customer_flash_popup.description'],
                    'van_offers_enabled' => ['commercial.flags.van_offers.label', 'commercial.flags.van_offers.description'],
                ] as $flagKey => $flagMeta)
                    <div class="feature-flag"><span class="commercial-status">{{ ($featureFlags[$flagKey] ?? false) ? __('commercial.flags.on') : __('commercial.flags.off') }}</span><span class="flag-copy"><span>{{ __($flagMeta[0]) }}</span><small>{{ __($flagMeta[1]) }}</small></span></div>
                @endforeach
            </div>
        @endif
    </section>

    @if($section === 'sales-control')
        <section class="commercial-card">
            <div class="feature-flags-head">
                <div>
                    <h2>{{ __('commercial.sales.policies_title') }}</h2>
                    <p class="muted">{{ __('commercial.sales.policies_description') }}</p>
                </div>
                <span class="commercial-status">{{ __('commercial.sales.products_count', ['count' => $products->count()]) }}</span>
            </div>
        </section>
        <div class="commercial-product-grid">
            @foreach($products as $product)
                <article class="commercial-card commercial-product" data-product-id="{{ $product->id }}">
                    <div class="commercial-product-head">
                        <div><h3>{{ $product->name }}</h3><div class="muted">{{ $product->sku ?: '—' }}</div></div>
                        <span class="commercial-status">{{ $product->is_active ? __('commercial.common.active') : __('commercial.common.inactive') }}</span>
                    </div>
                    <div class="commercial-product-body">
                    @php
                        $policy = $policies->get($product->id);
                        $units = $sellingUnits->get($product->id, collect())->map(fn($u)=>[
                            'unit_id'=>$u->unit_id,'code'=>$u->code,'name'=>$u->name,'conversion_factor'=>(float)$u->conversion_factor,
                            'price'=>$u->price,'sku'=>$u->sku,'barcode'=>$u->barcode,'is_base'=>(bool)$u->is_base,'is_active'=>(bool)$u->is_active,
                        ])->values();
                        $windows = $availabilityWindows->get($product->id, collect())->map(fn($w)=>[
                            'recurrence'=>$w->recurrence,'starts_at'=>$w->starts_at,'ends_at'=>$w->ends_at,
                            'start_month'=>$w->start_month,'start_day'=>$w->start_day,'end_month'=>$w->end_month,'end_day'=>$w->end_day,'is_active'=>(bool)$w->is_active,
                        ])->values();
                        $rules = $commercialRules->get($product->id, collect())->map(fn($r)=>[
                            'customer_id'=>$r->customer_id,'customer_group_id'=>$r->customer_group_id,'channel'=>$r->channel,'is_allowed'=>$r->is_allowed === null ? null : (bool)$r->is_allowed,
                            'max_per_order'=>$r->max_per_order,'max_per_day'=>$r->max_per_day,'max_per_week'=>$r->max_per_week,'max_per_month'=>$r->max_per_month,'max_lifetime'=>$r->max_lifetime,
                        ])->values();
                    @endphp
                    <form method="post" action="{{ route('admin.commercial.sales-control.save', ['product'=>$product->id] + $scope) }}" class="commercial-policy-form">
                        @csrf @method('put')
                        @php($selectedChannels = collect(json_decode((string)($policy->channels ?? '["customer","van","admin","api"]'), true) ?: []))
                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ __('commercial.sales.availability_channels') }}</span></div>
                            <div class="commercial-form-grid">
                                <label>{{ __('commercial.sales.sales_status') }}<select name="status">@foreach(['OPEN','RESTRICTED','CLOSED'] as $status)<option value="{{ $status }}" @selected(($policy->status ?? 'OPEN')===$status)>{{ __('commercial.sales.statuses.'.strtolower($status)) }}</option>@endforeach</select></label>
                                <label>{{ __('commercial.sales.business_timezone') }}<input name="business_timezone" value="{{ $policy->business_timezone ?? 'Asia/Kuwait' }}"></label>
                            </div>
                            <div class="commercial-choice-grid" data-commercial-channel-picker>
                                @foreach(['customer','van','admin','api'] as $channel)
                                    <label class="commercial-choice"><input type="checkbox" value="{{ $channel }}" data-commercial-channel @checked($selectedChannels->contains($channel))><span>{{ __('commercial.channels.'.$channel) }}</span></label>
                                @endforeach
                                <input type="hidden" name="channels_json" data-commercial-channels-json value="{{ $policy->channels ?? '[&quot;customer&quot;,&quot;van&quot;,&quot;admin&quot;,&quot;api&quot;]' }}">
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ __('commercial.sales.selling_break_pack') }}</span></div>
                            <div class="commercial-form-grid">
                                <label>{{ __('commercial.sales.break_pack_policy') }}
                                    <select name="break_pack_policy">
                                        @foreach([
                                            'mixed' => 'commercial.sales.break_pack_modes.mixed',
                                            'full-pack-only' => 'commercial.sales.break_pack_modes.full_pack_only',
                                            'loose-only' => 'commercial.sales.break_pack_modes.loose_only',
                                            'one-unit-type' => 'commercial.sales.break_pack_modes.one_unit_type',
                                        ] as $mode => $modeLabel)
                                            <option value="{{ $mode }}" @selected(($policy->break_pack_policy ?? 'mixed') === $mode)>{{ __($modeLabel) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>{{ __('commercial.sales.one_unit_code') }}
                                    <select name="break_pack_unit_code" data-break-pack-selling-unit>
                                        <option value="">{{ __('commercial.sales.select_selling_unit') }}</option>
                                        @foreach($units->where('is_active', true) as $unit)
                                            <option value="{{ $unit->code }}" @selected(($policy->break_pack_unit_code ?? '') === $unit->code)>{{ $unit->name }} · {{ $unit->code }} · ×{{ rtrim(rtrim(number_format((float)$unit->conversion_factor, 3, '.', ''), '0'), '.') }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ __('commercial.sales.default_quotas') }}</span><span class="muted">{{ __('commercial.sales.no_limit_hint') }}</span></div>
                            <div class="commercial-form-grid five">
                                @foreach([
                                    'default_max_per_order'=>'commercial.sales.quotas.per_order',
                                    'default_max_per_day'=>'commercial.sales.quotas.per_day',
                                    'default_max_per_week'=>'commercial.sales.quotas.per_week',
                                    'default_max_per_month'=>'commercial.sales.quotas.per_month',
                                    'default_max_lifetime'=>'commercial.sales.quotas.lifetime',
                                ] as $field=>$label)
                                    <label>{{ __($label) }}<input type="number" step="0.001" min="0" name="{{ $field }}" value="{{ $policy?->{$field} }}"></label>
                                @endforeach
                            </div>
                            <div class="commercial-form-grid">
                                <label>{{ __('commercial.sales.week_starts') }}<input type="number" min="0" max="6" name="week_starts_on" value="{{ $policy->week_starts_on ?? 1 }}"></label>
                            </div>
                        </section>

                        <section class="policy-section">
                            <div class="policy-section-title"><span>{{ __('commercial.sales.close_override') }}</span></div>
                            <div class="commercial-toggles">
                                <label class="commercial-choice"><input type="checkbox" name="hide_when_closed" value="1" @checked((bool)($policy->hide_when_closed ?? false))><span>{{ __('commercial.sales.hide_when_closed') }}</span></label>
                                <label class="commercial-choice"><input type="checkbox" name="override_allowed" value="1" @checked((bool)($policy->override_allowed ?? false))><span>{{ __('commercial.sales.override_allowed') }}</span></label>
                            </div>
                        </section>

                        @if($canManageFeatureFlags)
                            <details class="commercial-advanced" data-privileged-commercial-json>
                                <summary>{{ __('commercial.sales.advanced_privileged_title') }}</summary>
                                <div class="commercial-advanced-body">
                                    <p class="muted">{{ __('commercial.sales.advanced_privileged_hint') }}</p>
                                    <label>{{ __('commercial.sales.selling_units_json') }}<textarea name="selling_units_json" rows="5">{{ $units->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                                    <label>{{ __('commercial.sales.availability_windows_json') }}<textarea name="availability_windows_json" rows="5">{{ $windows->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                                    <label>{{ __('commercial.sales.targeting_rules_json') }}<textarea name="rules_json" rows="5">{{ $rules->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</textarea></label>
                                </div>
                            </details>
                        @else
                            <input type="hidden" name="selling_units_json" value="{{ e($units->toJson(JSON_UNESCAPED_SLASHES)) }}">
                            <input type="hidden" name="availability_windows_json" value="{{ e($windows->toJson(JSON_UNESCAPED_SLASHES)) }}">
                            <input type="hidden" name="rules_json" value="{{ e($rules->toJson(JSON_UNESCAPED_SLASHES)) }}">
                        @endif
                        <button type="submit" class="foodex-primary">{{ __('commercial.sales.save_policy') }}</button>
                    </form>
                    </div>
                </article>
            @endforeach
        </div>
        @if($products->isEmpty())
            <div class="commercial-empty">
                <div>
                    <strong>{{ __('commercial.sales.empty_title') }}</strong>
                    <p>{{ __('commercial.sales.empty_description') }}</p>
                    <div style="margin-top:14px"><a class="foodex-primary" href="{{ route('admin.b2c.module', ['module'=>'products'] + $scope) }}">{{ __('commercial.sales.open_products') }}</a></div>
                </div>
            </div>
        @endif
    @else
        @php
            $selectedFlashChannels = collect(old('channels', $editingOffer ? (json_decode((string)$editingOffer->channels, true) ?: []) : ['customer','van']));
            $selectedCustomerIds = collect(old('audience_customer_ids', $editingOffer ? (json_decode((string)$editingOffer->audience_customer_ids, true) ?: []) : []))->map(fn($id)=>(int)$id);
            $selectedGroupIds = collect(old('audience_customer_group_ids', $editingOffer ? (json_decode((string)$editingOffer->audience_customer_group_ids, true) ?: []) : []))->map(fn($id)=>(int)$id);
            $selectedRegions = collect(old('audience_regions', $editingOffer ? (json_decode((string)$editingOffer->audience_regions, true) ?: []) : []));
            $selectedRoutes = collect(old('audience_routes', $editingOffer ? (json_decode((string)$editingOffer->audience_routes, true) ?: []) : []));
            $editingStarts = $editingOffer ? \Illuminate\Support\Carbon::parse($editingOffer->starts_at)->format('Y-m-d\TH:i') : '';
            $editingEnds = $editingOffer ? \Illuminate\Support\Carbon::parse($editingOffer->ends_at)->format('Y-m-d\TH:i') : '';
            $offerProductRows = collect(old('products', $editingProducts->map(fn($row)=>[
                'product_id'=>(int)$row->product_id,
                'selling_unit_code'=>$row->selling_unit_code,
                'flash_price'=>$row->flash_price,
                'allocation_base'=>$row->allocation_base,
            ])->all()));
            if($offerProductRows->isEmpty()) {
                $offerProductRows = collect([['product_id'=>'','selling_unit_code'=>'','flash_price'=>'','allocation_base'=>'']]);
            }
            $unitCatalog = $flashSellingUnits->mapWithKeys(fn($items,$productId)=>[(string)$productId=>$items->map(fn($unit)=>[
                'code'=>$unit->code,
                'name'=>$unit->name,
                'factor'=>(float)$unit->conversion_factor,
            ])->values()->all()]);
        @endphp

        <section class="commercial-card" data-flash-offer-form>
            <div class="feature-flags-head">
                <div>
                    <h2>{{ $editingOffer ? __('commercial.flash.edit_title') : __('commercial.flash.create_title') }}</h2>
                    <p class="muted">{{ __('commercial.flash.form_description') }}</p>
                </div>
                @if($editingOffer)
                    <a class="flash-secondary" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ __('commercial.flash.create_new') }}</a>
                @endif
            </div>
            @if($storeId > 0)
            <form method="post" action="{{ route('admin.commercial.flash-offers.save', $scope) }}" class="control-list">
                @csrf
                @if($editingOffer)<input type="hidden" name="offer_id" value="{{ $editingOffer->id }}">@endif

                <section class="flash-form-section">
                    <h3>{{ __('commercial.flash.basics') }}</h3>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.name') }}<input name="name" value="{{ old('name', $editingOffer->name ?? '') }}" required></label>
                        <label>{{ __('commercial.flash.status') }}
                            <select name="status">
                                @foreach(['draft','scheduled','active','paused','sold_out','expired','cancelled','completed'] as $status)
                                    <option value="{{ $status }}" @selected(old('status', $editingOffer->status ?? 'draft') === $status)>{{ __('commercial.flash.statuses.'.$status) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.title_ar') }}<input name="title_ar" value="{{ old('title_ar', $editingOffer->title_ar ?? '') }}" required></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                        <label>{{ __('commercial.flash.title_en') }}<input name="title_en" value="{{ old('title_en', $editingOffer->title_en ?? '') }}" required></label> {{-- localization-gate: allow explicit bilingual authoring field --}}
                    </div>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.body_ar') }}<textarea name="body_ar">{{ old('body_ar', $editingOffer->body_ar ?? '') }}</textarea></label>
                        <label>{{ __('commercial.flash.body_en') }}<textarea name="body_en">{{ old('body_en', $editingOffer->body_en ?? '') }}</textarea></label>
                    </div>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.starts') }}<input type="datetime-local" name="starts_at" value="{{ old('starts_at', $editingStarts) }}" required></label>
                        <label>{{ __('commercial.flash.ends') }}<input type="datetime-local" name="ends_at" value="{{ old('ends_at', $editingEnds) }}" required></label>
                        <label>{{ __('commercial.flash.timezone') }}<input name="timezone" value="{{ old('timezone', $editingOffer->timezone ?? 'Asia/Kuwait') }}" required></label>
                    </div>
                </section>

                <section class="flash-form-section">
                    <h3>{{ __('commercial.flash.channels') }}</h3>
                    <div class="commercial-choice-grid">
                        @foreach(['customer','van'] as $channel)
                            <label class="commercial-choice"><input type="checkbox" name="channels[]" value="{{ $channel }}" @checked($selectedFlashChannels->contains($channel))><span>{{ __('commercial.channels.'.$channel) }}</span></label>
                        @endforeach
                    </div>
                </section>

                <section class="flash-form-section">
                    <h3>{{ __('commercial.flash.audience') }}</h3>
                    <p class="muted">{{ __('commercial.flash.audience_hint') }}</p>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.customers') }}
                            <select class="flash-multi" name="audience_customer_ids[]" multiple>
                                @foreach($audienceCustomers as $customer)
                                    <option value="{{ $customer->id }}" @selected($selectedCustomerIds->contains((int)$customer->id))>{{ $customer->name }}{{ $customer->email ? ' · '.$customer->email : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('commercial.flash.customer_groups') }}
                            <select class="flash-multi" name="audience_customer_group_ids[]" multiple>
                                @foreach($audienceGroups as $group)
                                    <option value="{{ $group->id }}" @selected($selectedGroupIds->contains((int)$group->id))>{{ $group->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('commercial.flash.regions') }}
                            <select class="flash-multi" name="audience_regions[]" multiple>
                                @foreach($audienceRegions as $region)
                                    <option value="{{ $region }}" @selected($selectedRegions->contains($region))>{{ $region }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('commercial.flash.routes') }}
                            <select class="flash-multi" name="audience_routes[]" multiple>
                                @foreach($audienceRoutes as $route)
                                    <option value="{{ $route }}" @selected($selectedRoutes->contains($route))>{{ $route }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </section>

                <section class="flash-form-section">
                    <div class="feature-flags-head">
                        <div><h3>{{ __('commercial.flash.products') }}</h3><p class="muted">{{ __('commercial.flash.products_hint') }}</p></div>
                        <button type="button" class="flash-secondary" data-flash-product-add>{{ __('commercial.flash.add_product') }}</button>
                    </div>
                    <div class="flash-product-builder" data-flash-product-builder>
                        @foreach($offerProductRows as $index=>$row)
                            <div class="flash-product-row" data-flash-product-row>
                                <label>{{ __('commercial.flash.product') }}
                                    <select name="products[{{ $index }}][product_id]" data-flash-product required>
                                        <option value="">{{ __('commercial.flash.select_product') }}</option>
                                        @foreach($flashProducts as $product)
                                            <option value="{{ $product->id }}" @selected((int)($row['product_id'] ?? 0)===(int)$product->id)>{{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>{{ __('commercial.flash.selling_unit') }}
                                    <select name="products[{{ $index }}][selling_unit_code]" data-flash-unit data-selected-unit="{{ $row['selling_unit_code'] ?? '' }}" required>
                                        <option value="">{{ __('commercial.flash.select_selling_unit') }}</option>
                                    </select>
                                </label>
                                <label>{{ __('commercial.flash.flash_price') }}<input type="number" min="0" step="0.001" name="products[{{ $index }}][flash_price]" value="{{ $row['flash_price'] ?? '' }}" required></label>
                                <label>{{ __('commercial.flash.allocation') }}<input type="number" min="0" step="0.001" name="products[{{ $index }}][allocation_base]" value="{{ $row['allocation_base'] ?? '' }}"></label>
                                <button type="button" class="flash-row-remove" data-flash-product-remove>{{ __('commercial.flash.remove_product') }}</button>
                            </div>
                        @endforeach
                    </div>
                    <script type="application/json" data-flash-unit-catalog>@json($unitCatalog, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)</script>
                    <template data-flash-product-template>
                        <div class="flash-product-row" data-flash-product-row>
                            <label>{{ __('commercial.flash.product') }}
                                <select name="products[__INDEX__][product_id]" data-flash-product required>
                                    <option value="">{{ __('commercial.flash.select_product') }}</option>
                                    @foreach($flashProducts as $product)<option value="{{ $product->id }}">{{ $product->name }}{{ $product->sku ? ' · '.$product->sku : '' }}</option>@endforeach
                                </select>
                            </label>
                            <label>{{ __('commercial.flash.selling_unit') }}<select name="products[__INDEX__][selling_unit_code]" data-flash-unit required><option value="">{{ __('commercial.flash.select_selling_unit') }}</option></select></label>
                            <label>{{ __('commercial.flash.flash_price') }}<input type="number" min="0" step="0.001" name="products[__INDEX__][flash_price]" required></label>
                            <label>{{ __('commercial.flash.allocation') }}<input type="number" min="0" step="0.001" name="products[__INDEX__][allocation_base]"></label>
                            <button type="button" class="flash-row-remove" data-flash-product-remove>{{ __('commercial.flash.remove_product') }}</button>
                        </div>
                    </template>
                </section>

                <section class="flash-form-section">
                    <h3>{{ __('commercial.flash.rules') }}</h3>
                    <div class="commercial-grid">
                        <label>{{ __('commercial.flash.allocation_mode') }}<select name="allocation_mode"><option value="shared" @selected(old('allocation_mode', $editingOffer->allocation_mode ?? 'shared')==='shared')>{{ __('commercial.flash.shared') }}</option><option value="reserved" @selected(old('allocation_mode', $editingOffer->allocation_mode ?? 'shared')==='reserved')>{{ __('commercial.flash.reserved') }}</option></select></label>
                        <label>{{ __('commercial.flash.total_allocation') }}<input type="number" step="0.001" min="0" name="total_allocation_base" value="{{ old('total_allocation_base', $editingOffer->total_allocation_base ?? '') }}"></label>
                        <label>{{ __('commercial.flash.per_customer_limit') }}<input type="number" step="0.001" min="0" name="per_customer_limit_base" value="{{ old('per_customer_limit_base', $editingOffer->per_customer_limit_base ?? '') }}"></label>
                        <label>{{ __('commercial.flash.reservation_seconds') }}<input type="number" name="reservation_seconds" value="{{ old('reservation_seconds', $editingOffer->reservation_seconds ?? 300) }}" min="30" required></label>
                        <label>{{ __('commercial.flash.retry_count') }}<input type="number" name="retry_count" value="{{ old('retry_count', $editingOffer->retry_count ?? 0) }}" min="0" required></label>
                        <label>{{ __('commercial.flash.cooldown_seconds') }}<input type="number" name="cooldown_seconds" value="{{ old('cooldown_seconds', $editingOffer->cooldown_seconds ?? 0) }}" min="0" required></label>
                        <label>{{ __('commercial.flash.priority') }}<input type="number" name="priority" value="{{ old('priority', $editingOffer->priority ?? 0) }}" required></label>
                        <label>{{ __('commercial.flash.popup_frequency') }}<select name="popup_frequency"><option value="once_per_session" @selected(old('popup_frequency', $editingOffer->popup_frequency ?? 'once_per_session')==='once_per_session')>{{ __('commercial.flash.once_per_session') }}</option><option value="once_per_day" @selected(old('popup_frequency', $editingOffer->popup_frequency ?? '')==='once_per_day')>{{ __('commercial.flash.once_per_day') }}</option><option value="always" @selected(old('popup_frequency', $editingOffer->popup_frequency ?? '')==='always')>{{ __('commercial.flash.always') }}</option></select></label>
                    </div>
                    <div class="commercial-choice-grid">
                        <label class="commercial-choice"><input type="checkbox" name="counts_toward_normal_quota" value="1" @checked((bool)old('counts_toward_normal_quota', $editingOffer->counts_toward_normal_quota ?? true))><span>{{ __('commercial.flash.counts_quota') }}</span></label>
                        <label class="commercial-choice"><input type="checkbox" name="stackable" value="1" @checked((bool)old('stackable', $editingOffer->stackable ?? false))><span>{{ __('commercial.flash.stackable') }}</span></label>
                        <label class="commercial-choice"><input type="checkbox" name="kill_switch" value="1" @checked((bool)old('kill_switch', $editingOffer->kill_switch ?? false))><span>{{ __('commercial.flash.kill_switch') }}</span></label>
                    </div>
                </section>

                <div class="flash-form-actions">
                    @if($editingOffer)<a class="flash-secondary" href="{{ route('admin.commercial.flash-offers', $scope) }}">{{ __('commercial.flash.cancel_edit') }}</a>@endif
                    <button type="submit" class="foodex-primary">{{ $editingOffer ? __('commercial.flash.save_changes') : __('commercial.flash.create_offer') }}</button>
                </div>
            </form>
            @else
                <p class="muted">{{ __('commercial.flash.store_required') }}</p>
            @endif
        </section>

        <section class="commercial-card">
            <h2>{{ __('commercial.flash.current_offers') }}</h2>
            @forelse($flashOffers as $offer)
                <div class="control-row">
                    <span>{{ $offer->name }} · <strong>{{ __('commercial.flash.statuses.'.$offer->status) }}</strong> · {{ $offer->starts_at }} → {{ $offer->ends_at }}</span>
                    <details class="foodex-ops-actions" data-flash-offer-actions>
                        <summary aria-label="{{ __('commercial.flash.actions_menu') }}">⋮</summary>
                        <div class="foodex-ops-menu">
                            <a href="{{ route('admin.commercial.flash-offers', ['edit'=>$offer->id] + $scope) }}">{{ __('commercial.flash.edit') }}</a>
                            <a href="{{ route('admin.commercial.flash-offers.preview', ['offer'=>$offer->id] + $scope) }}">{{ __('commercial.flash.preview') }}</a>
                            <a href="{{ route('admin.commercial.flash-offers.analytics', ['offer'=>$offer->id] + $scope) }}">{{ __('commercial.flash.analytics') }}</a>
                            <form method="post" action="{{ route('admin.commercial.flash-offers.action', ['offer'=>$offer->id] + $scope) }}">@csrf
                                <select name="action" aria-label="{{ __('commercial.flash.action') }}">@foreach(['schedule','activate','pause','resume','end','cancel','kill_on','kill_off'] as $action)<option value="{{ $action }}">{{ __('commercial.flash.actions.'.$action) }}</option>@endforeach</select>
                                <button type="submit">{{ __('commercial.flash.apply') }}</button>
                            </form>
                        </div>
                    </details>
                </div>
            @empty
                <p class="muted">{{ __('commercial.flash.no_offers') }}</p>
            @endforelse
        </section>

        <section class="commercial-card">
            <h2>{{ __('commercial.flash.existing_promotions') }}</h2>
            <div style="overflow:auto">
                <table class="commercial-table">
                    <thead><tr><th>{{ __('commercial.flash.promotion_name') }}</th><th>{{ __('commercial.flash.promotion_type') }}</th><th>{{ __('commercial.flash.promotion_value') }}</th><th>{{ __('commercial.flash.promotion_period') }}</th><th>{{ __('commercial.flash.status') }}</th></tr></thead>
                    <tbody>
                    @forelse($existingPromotions as $promotion)
                        <tr>
                            <td>{{ $promotion->name }}</td>
                            <td>{{ $promotion->type }}</td>
                            <td>{{ $promotion->value ?? '—' }}</td>
                            <td>{{ $promotion->starts_at ?: '—' }} → {{ $promotion->ends_at ?: '—' }}</td>
                            <td>{{ $promotion->is_active ? __('commercial.flash.promotion_active') : __('commercial.flash.promotion_inactive') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('commercial.flash.no_existing_promotions') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    </main>
</div>
<script>
(() => {
    document.querySelectorAll('[data-commercial-channel-picker]').forEach((picker) => {
        const output = picker.querySelector('[data-commercial-channels-json]');
        const boxes = [...picker.querySelectorAll('[data-commercial-channel]')];
        const sync = () => {
            if (output) output.value = JSON.stringify(boxes.filter((box) => box.checked).map((box) => box.value));
        };
        boxes.forEach((box) => box.addEventListener('change', sync));
        sync();
    });

    const builder = document.querySelector('[data-flash-product-builder]');
    const template = document.querySelector('[data-flash-product-template]');
    const addButton = document.querySelector('[data-flash-product-add]');
    const catalogNode = document.querySelector('[data-flash-unit-catalog]');
    let unitCatalog = {};
    try { unitCatalog = catalogNode ? JSON.parse(catalogNode.textContent || '{}') : {}; } catch (_) { unitCatalog = {}; }
    let nextProductIndex = builder ? builder.querySelectorAll('[data-flash-product-row]').length : 0;

    const syncSellingUnits = (row, preserve = true) => {
        const product = row.querySelector('[data-flash-product]');
        const unit = row.querySelector('[data-flash-unit]');
        if (!product || !unit) return;
        const wanted = preserve ? (unit.dataset.selectedUnit || unit.value) : '';
        unit.replaceChildren();
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = @json(__('commercial.flash.select_selling_unit'));
        unit.appendChild(placeholder);
        (unitCatalog[String(product.value)] || []).forEach((item) => {
            const option = document.createElement('option');
            option.value = item.code;
            option.textContent = item.name + ' · ' + item.code + ' · ×' + item.factor;
            option.selected = item.code === wanted;
            unit.appendChild(option);
        });
        delete unit.dataset.selectedUnit;
    };

    const setupProductRow = (row) => {
        const product = row.querySelector('[data-flash-product]');
        product?.addEventListener('change', () => syncSellingUnits(row, false));
        row.querySelector('[data-flash-product-remove]')?.addEventListener('click', () => {
            if (!builder || builder.querySelectorAll('[data-flash-product-row]').length <= 1) return;
            row.remove();
        });
        syncSellingUnits(row, true);
    };

    builder?.querySelectorAll('[data-flash-product-row]').forEach(setupProductRow);
    addButton?.addEventListener('click', () => {
        if (!builder || !template) return;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextProductIndex++));
        const row = wrapper.firstElementChild;
        if (!row) return;
        builder.appendChild(row);
        setupProductRow(row);
    });
})();
</script>
</body>
</html>
