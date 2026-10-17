<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ __('smart_ordering_supply.title') }}</title>
    @include('admin._brand-components')
    @include('admin._settings-page-polish')
    <style>
        .si-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:16px}
        .si-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
        .si-kpi{padding:14px;border:1px solid var(--foodex-border);border-radius:14px}
        .si-kpi strong{display:block;font-size:1.45rem}
        .si-table-wrap{overflow:auto}
        .si-table{width:100%;border-collapse:collapse;min-width:880px}
        .si-table th,.si-table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}
        .si-note{padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-orange-soft);font-weight:650}
        .si-badge{display:inline-flex;padding:3px 9px;border-radius:999px;background:rgba(127,127,127,.12);font-size:.82rem}
        .si-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .si-muted{opacity:.72}
        .si-form{display:grid;gap:12px}
        .si-form label{display:grid;gap:6px}
        .si-danger{border:1px solid var(--foodex-red);color:var(--foodex-red);background:transparent;border-radius:10px;padding:8px 12px}
    </style>
</head>
<body>
@php
    $isAr = app()->getLocale() === 'ar';
    $localized = static function (mixed $arabic, mixed $english) use ($isAr): string {
        $primary = trim((string) ($isAr ? $arabic : $english));
        $fallback = trim((string) ($isAr ? $english : $arabic));

        return $primary !== '' ? $primary : $fallback;
    };
    $itemIndex = collect($menuItems)->keyBy('public_id');
    $displayRecommendations = array_slice($recommendations['rows'], 0, 500);
    $supportAccess = (bool) request()->integer('support_access');
@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="smart-ordering-supply">
    <aside class="sidebar">
        @include('admin._sidebar', ['navGroups' => app(\App\Support\AdminNavigation::class)->groupsFor(auth()->user()), 'navContext' => 'smart_ordering', 'user' => auth()->user()])
    </aside>
    <main class="foodex-admin-main foodex-admin-page foodex-settings-surface">
@include('admin._global-header')
        <header class="foodex-page-toolbar">
            <div>
                <h1>{{ __('smart_ordering_supply.title') }}</h1>
                <p>{{ __('smart_ordering_supply.subtitle') }}</p>
            </div>
            <a class="foodex-action-primary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}">{{ __('smart_ordering_supply.smart_ordering_settings') }}</a>
        </header>

        @include('admin._smart-ordering-workspace-nav')

        @if(session('status'))<p class="foodex-alert success" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="foodex-alert error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="panel">
            <div class="si-note">{{ __('smart_ordering_supply.disclaimer') }}</div>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.period_scope') }}</h2>
            <form class="si-grid" method="get" action="{{ route('admin.smart-ordering.supply.index',$site) }}">
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                <label>{{ __('smart_ordering_supply.from') }}<input class="foodex-control" type="date" name="from" value="{{ $from->toDateString() }}"></label>
                <label>{{ __('smart_ordering_supply.to') }}<input class="foodex-control" type="date" name="to" value="{{ $to->toDateString() }}"></label>
                <label>{{ __('smart_ordering_supply.branch') }}
                    <select class="foodex-control" name="branch_id">
                        <option value="">{{ __('smart_ordering_supply.all_branches') }}</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected($branchId === (int)$branch->id)>{{ $localized($branch->name_ar, $branch->name_en) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="si-actions"><button class="primary" type="submit">{{ __('smart_ordering_supply.refresh_analysis') }}</button></div>
            </form>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.coverage_title') }}</h2>
            <div class="si-kpis">
                <div class="si-kpi"><span>{{ __('smart_ordering_supply.completed_orders') }}</span><strong>{{ $consumption['total_completed_orders'] }}</strong></div>
                <div class="si-kpi"><span>{{ __('smart_ordering_supply.mapped_lines') }}</span><strong>{{ $consumption['mapped_lines'] }}/{{ $consumption['total_lines'] }}</strong></div>
                <div class="si-kpi"><span>{{ __('smart_ordering_supply.coverage') }}</span><strong>{{ number_format($consumption['coverage']*100,1) }}%</strong></div>
                <div class="si-kpi"><span>{{ __('smart_ordering_supply.confidence') }}</span><strong>{{ strtoupper($consumption['confidence']) }}</strong></div>
            </div>
        </section>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering_supply.mapping_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.mapping_help') }}</p>
                <form class="si-form" method="post" action="{{ route('admin.smart-ordering.supply.mappings.store',$site) }}">
                    @csrf
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>{{ __('smart_ordering_supply.menu_item') }}
                        <select class="foodex-control" name="menu_item_public_id" required>
                            @foreach($menuItems as $item)
                                <option value="{{ $item['public_id'] }}">{{ $localized($item['name_ar'], $item['name_en']) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.optional_branch') }}
                        <select class="foodex-control" name="branch_id">
                            <option value="">{{ __('smart_ordering_supply.all_branches') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $localized($branch->name_ar, $branch->name_en) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.foodex_sku') }}
                        <select class="foodex-control" name="foodex_product_id" required>
                            @foreach($foodexProducts as $product)
                                <option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }} ({{ number_format($product['available_quantity'],3) }})</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.variant_product_id') }}
                        <select class="foodex-control" name="variant_product_id">
                            <option value="">{{ __('smart_ordering_supply.any_variant') }}</option>
                            @foreach($restaurantProducts as $product)
                                <option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="si-grid">
                        <label>{{ __('smart_ordering_supply.ingredient_quantity') }}<input class="foodex-control" type="number" step="0.000001" min="0.000001" name="quantity_per_item" required></label>
                        <label>{{ __('smart_ordering_supply.waste_factor') }}<input class="foodex-control" type="number" step="0.000001" min="0" max="1" name="waste_factor" value="0"></label>
                    </div>
                    <div class="si-grid">
                        <label>{{ __('smart_ordering_supply.effective_from') }}<input class="foodex-control" type="datetime-local" name="effective_from"></label>
                        <label>{{ __('smart_ordering_supply.effective_until') }}<input class="foodex-control" type="datetime-local" name="effective_to"></label>
                    </div>
                    <button class="primary" type="submit">{{ __('smart_ordering_supply.save_mapping') }}</button>
                </form>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering_supply.stock_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.stock_help') }}</p>
                <form class="si-form" method="post" action="{{ route('admin.smart-ordering.supply.stock',$site) }}">
                    @csrf @method('PUT')
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>{{ __('smart_ordering_supply.foodex_sku') }}
                        <select class="foodex-control" name="foodex_product_id" required>
                            @foreach($foodexProducts as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.branch') }}
                        <select class="foodex-control" name="branch_id">
                            <option value="">{{ __('smart_ordering_supply.all_branches') }}</option>
                            @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch->name_ar, $branch->name_en) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.authoritative_on_hand') }}<input class="foodex-control" type="number" step="0.000001" min="0" name="on_hand_quantity" required></label>
                    <label>{{ __('smart_ordering_supply.source') }}<input class="foodex-control" name="source" placeholder="{{ __('smart_ordering_supply.source_placeholder') }}" required></label>
                    <label>{{ __('smart_ordering_supply.counted_at') }}<input class="foodex-control" type="datetime-local" name="counted_at" value="{{ now('Asia/Kuwait')->format('Y-m-d\TH:i') }}" required></label>
                    <button class="primary" type="submit">{{ __('smart_ordering_supply.save_authoritative_stock') }}</button>
                </form>
            </section>
        </div>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering_supply.waste_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.waste_help') }}</p>
                <form class="si-form" method="post" action="{{ route('admin.smart-ordering.supply.waste',$site) }}">
                    @csrf
                    @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                    <label>{{ __('smart_ordering_supply.foodex_sku') }}
                        <select class="foodex-control" name="foodex_product_id" required>
                            @foreach($foodexProducts as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} — {{ $product['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.branch') }}
                        <select class="foodex-control" name="branch_id">
                            <option value="">{{ __('smart_ordering_supply.all_branches') }}</option>
                            @foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $localized($branch->name_ar, $branch->name_en) }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('smart_ordering_supply.waste_quantity') }}<input class="foodex-control" type="number" step="0.000001" min="0.000001" name="quantity" required></label>
                    <label>{{ __('smart_ordering_supply.waste_reason') }}<input class="foodex-control" name="reason" maxlength="160" placeholder="{{ __('smart_ordering_supply.waste_reason_placeholder') }}" required></label>
                    <label>{{ __('smart_ordering_supply.occurred_at') }}<input class="foodex-control" type="datetime-local" name="occurred_at" value="{{ now('Asia/Kuwait')->format('Y-m-d\TH:i') }}" required></label>
                    <button class="primary" type="submit">{{ __('smart_ordering_supply.record_waste') }}</button>
                </form>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering_supply.waste_history') }}</h2>
                <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-100-audit">
                    <thead><tr><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.branch') }}</th><th>{{ __('smart_ordering_supply.waste_quantity') }}</th><th>{{ __('smart_ordering_supply.waste_reason') }}</th><th>{{ __('smart_ordering_supply.occurred_at') }}</th></tr></thead>
                    <tbody>
                    @forelse($wasteEvents as $event)
                        <tr>
                            <td>{{ $event['sku'] }} — {{ $event['product_name'] }}</td>
                            <td>{{ $event['branch_name_en'] || $event['branch_name_ar'] ? $localized($event['branch_name_ar'], $event['branch_name_en']) : __('smart_ordering_supply.all_branches') }}</td>
                            <td>{{ number_format((float)$event['quantity'],6) }}</td>
                            <td>{{ $event['reason'] }}</td>
                            <td>{{ $event['occurred_at'] }}</td>
                        </tr>
                    @empty<tr><td colspan="5">{{ __('smart_ordering_supply.no_waste_events') }}</td></tr>@endforelse
                    </tbody>
                </table></div>
            </section>
        </div>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.mappings_title') }}</h2>
            <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-500-analysis-snapshot">
                <thead><tr><th>{{ __('smart_ordering_supply.menu_item') }}</th><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.branch') }}</th><th>{{ __('smart_ordering_supply.conversion') }}</th><th>{{ __('smart_ordering_supply.waste') }}</th><th>{{ __('smart_ordering_supply.validity') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($mappings as $mapping)
                    @php($menuItem = $itemIndex->get($mapping['menu_item_public_id']))
                    <tr>
                        <td>{{ $menuItem ? $localized($menuItem['name_ar'], $menuItem['name_en']) : $mapping['menu_item_public_id'] }}</td>
                        <td>{{ $mapping['foodex_sku'] }} — {{ $mapping['foodex_name'] }} @if(!$mapping['catalog_active'])<span class="si-badge">{{ __('smart_ordering_supply.inactive') }}</span>@endif</td>
                        <td>{{ $mapping['branch_id'] ? $localized($mapping['branch_name_ar'], $mapping['branch_name_en']) : __('smart_ordering_supply.all') }}</td>
                        <td>{{ number_format($mapping['quantity_per_item'],6) }}</td>
                        <td>{{ number_format($mapping['waste_factor']*100,2) }}%</td>
                        <td>{{ $mapping['effective_from'] }} → {{ $mapping['effective_to'] ?: '∞' }}<br><span class="si-badge">{{ $mapping['is_active'] ? __('smart_ordering_supply.active') : __('smart_ordering_supply.historical') }}</span></td>
                        <td>
                            @if($mapping['is_active'])
                            <form method="post" action="{{ route('admin.smart-ordering.supply.mappings.deactivate',[$site,$mapping['public_id']]) }}">@csrf @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif<button class="si-danger" type="submit">{{ __('smart_ordering_supply.end_mapping') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('smart_ordering_supply.no_mappings') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.recipe_cost_title') }}</h2>
            <p class="si-muted">{{ __('smart_ordering_supply.recipe_cost_help') }}</p>
            <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-menu-recipe-cost">
                <thead><tr><th>{{ __('smart_ordering_supply.menu_item') }}</th><th>{{ __('smart_ordering_supply.ingredient') }}</th><th>{{ __('smart_ordering_supply.estimated_recipe_cost') }}</th></tr></thead>
                <tbody>
                @forelse($recipeCosts as $recipe)
                    <tr>
                        <td>{{ $localized($recipe['name_ar'], $recipe['name_en']) }}</td>
                        <td>
                            @foreach($recipe['lines'] as $line)
                                <div>{{ $line['sku'] }} — {{ $line['product_name'] }} · {{ number_format((float)$line['quantity_per_item'],6) }} + {{ number_format((float)$line['waste_factor']*100,2) }}% · {{ $line['line_cost'] === null ? '—' : number_format((float)$line['line_cost'],3) }}</div>
                            @endforeach
                        </td>
                        <td>
                            @if($recipe['cost_complete'])
                                <strong>{{ number_format((float)$recipe['estimated_cost'],3) }}</strong>
                            @else
                                <span class="si-badge">{{ __('smart_ordering_supply.cost_incomplete') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="3">{{ __('smart_ordering_supply.no_recipe_costs') }}</td></tr>@endforelse
                </tbody>
            </table></div>
        </section>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering_supply.stock_history_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.stock_history_help') }}</p>
                <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-100-stock-history">
                    <thead><tr><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.branch') }}</th><th>{{ __('smart_ordering_supply.authoritative_on_hand') }}</th><th>{{ __('smart_ordering_supply.source') }}</th><th>{{ __('smart_ordering_supply.counted_at') }}</th></tr></thead>
                    <tbody>
                    @forelse($stockHistory as $row)
                        <tr>
                            <td>{{ $row['sku'] }} — {{ $row['product_name'] }}</td>
                            <td>{{ $row['branch_name_en'] || $row['branch_name_ar'] ? $localized($row['branch_name_ar'], $row['branch_name_en']) : __('smart_ordering_supply.all_branches') }}</td>
                            <td>{{ number_format((float)$row['on_hand_quantity'],6) }}</td>
                            <td>{{ $row['source'] }}</td>
                            <td>{{ $row['counted_at'] }}</td>
                        </tr>
                    @empty<tr><td colspan="5">{{ __('smart_ordering_supply.no_stock_history') }}</td></tr>@endforelse
                    </tbody>
                </table></div>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering_supply.variance_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.variance_help') }}</p>
                <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-stock-variance">
                    <thead><tr><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.opening') }}</th><th>{{ __('smart_ordering_supply.closing') }}</th><th>{{ __('smart_ordering_supply.theoretical') }}</th><th>{{ __('smart_ordering_supply.recorded_waste') }}</th><th>{{ __('smart_ordering_supply.variance') }}</th></tr></thead>
                    <tbody>
                    @forelse($stockVariance as $row)
                        <tr>
                            <td>{{ $row['sku'] }} — {{ $row['name'] }}</td>
                            <td>{{ $row['opening_on_hand'] === null ? '—' : number_format((float)$row['opening_on_hand'],6) }}</td>
                            <td>{{ $row['closing_on_hand'] === null ? '—' : number_format((float)$row['closing_on_hand'],6) }}</td>
                            <td>{{ number_format((float)$row['theoretical_usage'],6) }}</td>
                            <td>{{ number_format((float)$row['recorded_waste'],6) }}</td>
                            <td>
                                @if($row['variance'] === null)
                                    <span class="si-badge">{{ __('smart_ordering_supply.insufficient_snapshots') }}</span>
                                @else
                                    {{ number_format((float)$row['variance'],6) }} <span class="si-badge">{{ __('smart_ordering_supply.snapshot_delta_only') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty<tr><td colspan="6">{{ __('smart_ordering_supply.no_stock_history') }}</td></tr>@endforelse
                    </tbody>
                </table></div>
            </section>
        </div>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.consumption_title') }}</h2>
            <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-500-analysis-snapshot">
                <thead><tr><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.product') }}</th><th>{{ __('smart_ordering_supply.estimated_usage') }}</th><th>{{ __('smart_ordering_supply.daily_average') }}</th><th>{{ __('smart_ordering_supply.evidence') }}</th></tr></thead>
                <tbody>
                @forelse($displayRecommendations as $row)
                    <tr>
                        <td>{{ $row['sku'] }}</td><td>{{ $row['name'] }}</td>
                        <td>{{ number_format($row['estimated_usage'],6) }}</td>
                        <td>{{ number_format($row['daily_estimated_usage'],6) }}</td>
                        <td>
                            @if($row['authoritative_stock'])
                                <span class="si-badge">{{ __('smart_ordering_supply.authoritative_stock') }}</span>
                                {{ __('smart_ordering_supply.on_hand') }}: {{ number_format($row['authoritative_stock']['on_hand_quantity'],6) }}
                                · {{ __('smart_ordering_supply.days_of_supply') }}: {{ $row['days_of_supply'] ?? '—' }}
                            @else
                                <span class="si-badge">{{ __('smart_ordering_supply.consumption_trend_only') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty<tr><td colspan="5">{{ __('smart_ordering_supply.no_consumption') }}</td></tr>@endforelse
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <h2>{{ __('smart_ordering_supply.reorder_title') }}</h2>
            <p class="si-muted">{{ __('smart_ordering_supply.reorder_help') }}</p>
            <form method="post" action="{{ route('admin.smart-ordering.supply.draft',$site) }}">
                @csrf
                @if($supportAccess)<input type="hidden" name="support_access" value="1">@endif
                @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
                <div class="si-table-wrap"><table class="si-table" data-pagination-exempt="bounded-500-analysis-snapshot">
                    <thead><tr><th>{{ __('smart_ordering_supply.foodex_sku') }}</th><th>{{ __('smart_ordering_supply.state') }}</th><th>{{ __('smart_ordering_supply.basis') }}</th><th>{{ __('smart_ordering_supply.live_price') }}</th><th>{{ __('smart_ordering_supply.foodex_available') }}</th><th>{{ __('smart_ordering_supply.suggested_quantity') }}</th><th>{{ __('smart_ordering_supply.cost') }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse($displayRecommendations as $row)
                        <tr>
                            <td>{{ $row['sku'] }}<br><span class="si-muted">{{ $row['name'] }}</span></td>
                            <td><span class="si-badge">{{ __('smart_ordering_supply.states.'.$row['state']) }}</span></td>
                            <td>
                                {{ __('smart_ordering_supply.basis_labels.'.$row['reorder_basis']) }}<br>
                                <span class="si-muted">{{ __('smart_ordering_supply.formula_labels.'.$row['reorder_basis']) }}</span>
                            </td>
                            <td>{{ $row['current_price'] === null ? '—' : number_format($row['current_price'],3) }}</td>
                            <td>{{ number_format($row['foodex_available_quantity'],3) }}</td>
                            <td>
                                @if($row['is_executable'])
                                    <input class="foodex-control" style="min-width:110px" type="number" step="{{ max(0.001,(float)$row['ordering_increment']) }}" min="{{ $row['minimum_quantity'] }}" max="{{ $row['recommended_quantity'] }}" name="quantities[{{ $row['foodex_product_id'] }}]" value="{{ $row['recommended_quantity'] }}">
                                @else
                                    {{ number_format($row['recommended_quantity'],3) }}
                                @endif
                            </td>
                            <td>{{ $row['estimated_cost'] === null ? '—' : number_format($row['estimated_cost'],3) }}</td>
                            <td><button class="si-danger" form="dismiss-{{ $row['foodex_product_id'] }}" type="submit">{{ __('smart_ordering_supply.dismiss_7d') }}</button></td>
                        </tr>
                    @empty<tr><td colspan="8">{{ __('smart_ordering_supply.no_recommendations') }}</td></tr>@endforelse
                    </tbody>
                </table></div>
                @if(collect($displayRecommendations)->contains(fn($row)=>$row['is_executable']))
                    <button class="primary" type="submit">{{ __('smart_ordering_supply.create_draft') }}</button>
                @endif
            </form>
            @foreach($displayRecommendations as $row)
                <form id="dismiss-{{ $row['foodex_product_id'] }}" method="post" action="{{ route('admin.smart-ordering.supply.dismiss',[$site,$row['foodex_product_id']]) }}" style="display:none">
                    @csrf<input type="hidden" name="days" value="7">@if($supportAccess)<input type="hidden" name="support_access" value="1">@endif @if($branchId)<input type="hidden" name="branch_id" value="{{ $branchId }}">@endif
                </form>
            @endforeach
        </section>

        <div class="si-grid">
            <section class="panel">
                <h2>{{ __('smart_ordering_supply.commercial_title') }}</h2>
                <div class="si-kpis">
                    <div class="si-kpi"><span>{{ __('smart_ordering_supply.mapped_menu_items') }}</span><strong>{{ $commercial['mapped_menu_items'] }}</strong></div>
                    <div class="si-kpi"><span>{{ __('smart_ordering_supply.assisted_drafts') }}</span><strong>{{ $commercial['assisted_drafts'] }}</strong></div>
                    <div class="si-kpi"><span>{{ __('smart_ordering_supply.draft_value') }}</span><strong>{{ number_format($commercial['assisted_draft_value'],3) }}</strong></div>
                    <div class="si-kpi"><span>{{ __('smart_ordering_supply.converted_orders') }}</span><strong>{{ $commercial['converted_assisted_orders'] }}</strong></div>
                    <div class="si-kpi"><span>{{ __('smart_ordering_supply.attributed_sales') }}</span><strong>{{ number_format($commercial['attributed_foodex_sales_value'],3) }}</strong></div>
                </div>
                <p class="si-muted">{{ __('smart_ordering_supply.attribution_rule') }}</p>
            </section>

            <section class="panel">
                <h2>{{ __('smart_ordering_supply.related_title') }}</h2>
                <p class="si-muted">{{ __('smart_ordering_supply.related_help') }}</p>
                @forelse($relatedProducts as $product)
                    <p><strong>{{ $product['sku'] }}</strong> — {{ $product['name'] }} · {{ number_format($product['available_quantity'],3) }}</p>
                @empty
                    <p>{{ __('smart_ordering_supply.no_related') }}</p>
                @endforelse
            </section>
        </div>
    </main>
</div>
</body>
</html>
