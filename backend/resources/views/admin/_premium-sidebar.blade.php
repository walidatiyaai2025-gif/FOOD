@php
    $channel = $channel ?? 'b2c';
    $allChildren = collect($navGroups ?? [])->flatMap(fn (array $group) => $group['children'] ?? [])->keyBy('key');
    $mainDefinitions = $channel === 'b2c'
        ? [
            ['key'=>'b2c_dashboard','icon'=>'home'],
            ['key'=>'b2c_orders','icon'=>'orders'],
            ['key'=>'b2c_products','icon'=>'products'],
            ['key'=>'b2c_customers','icon'=>'customers'],
            ['key'=>'b2c_drivers','icon'=>'delivery'],
            ['key'=>'notifications','icon'=>'bell'],
            ['key'=>'mobile_settings','icon'=>'mobile'],
            ['key'=>'b2c_reports','icon'=>'reports'],
            ['key'=>'b2c_settings','icon'=>'settings'],
        ]
        : [
            ['key'=>'b2b_dashboard','icon'=>'home'],
            ['key'=>'b2b_orders','icon'=>'orders'],
            ['key'=>'b2b_products','icon'=>'products'],
            ['key'=>'b2b_clients','icon'=>'customers'],
            ['key'=>'b2b_drivers','icon'=>'delivery'],
            ['key'=>'notifications','icon'=>'bell'],
            ['key'=>'mobile_settings','icon'=>'mobile'],
            ['key'=>'b2b_reports','icon'=>'reports'],
            ['key'=>'b2b_settings','icon'=>'settings'],
        ];
    $moreDefinitions = $channel === 'b2c'
        ? [
            ['key'=>'b2c_inventory','icon'=>'inventory'],
            ['key'=>'b2c_promotions','icon'=>'promotions'],
            ['key'=>'b2c_storefront','icon'=>'storefront'],
            ['key'=>'b2c_content','icon'=>'content'],
            ['key'=>'security','icon'=>'settings'],
            ['key'=>'translations','icon'=>'content'],
            ['key'=>'app_versions','icon'=>'mobile'],
            ['key'=>'system_update','icon'=>'settings'],
        ]
        : [
            ['key'=>'b2b_stores','icon'=>'storefront'],
            ['key'=>'b2b_pricing','icon'=>'revenue'],
            ['key'=>'security','icon'=>'settings'],
            ['key'=>'translations','icon'=>'content'],
            ['key'=>'app_versions','icon'=>'mobile'],
            ['key'=>'system_update','icon'=>'settings'],
        ];

    $resolveItems = static function (array $definitions) use ($allChildren): array {
        $items = [];
        foreach ($definitions as $definition) {
            $child = $allChildren->get($definition['key']);
            if ($child) {
                $child['icon'] = $definition['icon'];
                $items[] = $child;
            }
        }
        return $items;
    };

    $premiumItems = $resolveItems($mainDefinitions);
    $premiumMoreItems = $resolveItems($moreDefinitions);
    $secondaryLocale = app()->getLocale() === 'ar' ? 'en' : 'ar';
@endphp
<style id="foodex-premium-sidebar">
    .premium-sidebar-inner{padding:var(--foodex-space-5) var(--foodex-space-4);min-height:100%;background:var(--foodex-surface)}
    .premium-brand{display:flex;align-items:center;justify-content:center;gap:9px;min-height:52px;margin-bottom:var(--foodex-space-3);font-family:var(--foodex-font-en);font-size:1.42rem;font-weight:var(--foodex-font-weight-bold);letter-spacing:.025em;color:var(--foodex-green-dark)}
    .premium-brand .brand-mark{position:relative;width:24px;height:24px;display:inline-block}
    .premium-brand .brand-mark::before,.premium-brand .brand-mark::after{content:"";position:absolute;border-radius:100% 0 100% 0;transform:rotate(-30deg)}
    .premium-brand .brand-mark::before{width:15px;height:20px;inset:0 auto auto 6px;background:var(--foodex-green)}
    .premium-brand .brand-mark::after{width:10px;height:14px;inset:10px auto auto 0;background:var(--foodex-orange)}
    .premium-nav{display:grid;gap:4px}
    .premium-nav-item{min-height:56px;display:grid;grid-template-columns:24px minmax(0,1fr) 12px;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--foodex-radius-control);color:var(--foodex-ink);transition:background .16s ease,color .16s ease,box-shadow .16s ease}
    .premium-nav-item:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
    .premium-nav-item.active{background:var(--foodex-green);color:#fff;box-shadow:0 8px 18px rgba(21,138,58,.16)}
    .premium-nav-copy{min-width:0}.premium-nav-copy strong{display:block;font-size:.92rem;font-weight:var(--foodex-font-weight-bold);line-height:1.15}.premium-nav-copy small{display:block;margin-top:3px;font-family:var(--foodex-font-en);font-size:.68rem;font-weight:var(--foodex-font-weight-regular);line-height:1.15;color:var(--foodex-muted)}
    html[dir=ltr] .premium-nav-copy small{font-family:var(--foodex-font-ar)}
    .premium-nav-item.active .premium-nav-copy small{color:rgba(255,255,255,.78)}
    .premium-nav-chevron{font-size:.8rem;opacity:.65}
    .foodex-svg-icon{width:22px;height:22px;display:block}
    .premium-more{margin-top:var(--foodex-space-2);border-top:1px solid var(--foodex-border);padding-top:var(--foodex-space-2)}
    .premium-more summary{list-style:none;cursor:pointer}.premium-more summary::-webkit-details-marker{display:none}
    .premium-more-list{display:grid;gap:3px;margin-top:4px}
    @media(max-width:860px){.premium-sidebar-inner{padding:var(--foodex-space-3)}}
</style>

<div class="premium-sidebar-inner" data-premium-sidebar="{{ $channel }}">
    <a class="premium-brand" href="{{ route('admin.index') }}"><span class="brand-mark" aria-hidden="true"></span><span>FOODEX</span></a>

    <nav class="premium-nav" aria-label="{{ __('admin.navigation') }}">
        @foreach($premiumItems as $item)
            <a class="premium-nav-item {{ ($navContext ?? '') === $item['key'] ? 'active' : '' }}" href="{{ route($item['route'], $item['params']) }}" @if(($navContext ?? '') === $item['key']) aria-current="page" @endif>
                @include('admin._premium-icon',['name'=>$item['icon']])
                <span class="premium-nav-copy">
                    <strong>{{ __($item['label']) }}</strong>
                    <small lang="{{ $secondaryLocale }}">{{ __($item['label'], [], $secondaryLocale) }}</small>
                </span>
                <span class="premium-nav-chevron" aria-hidden="true">‹</span>
            </a>
        @endforeach

        @if($premiumMoreItems !== [])
            <details class="premium-more">
                <summary class="premium-nav-item">
                    @include('admin._premium-icon',['name'=>'more'])
                    <span class="premium-nav-copy"><strong>{{ app()->getLocale()==='ar' ? 'المزيد' : 'More' }}</strong><small lang="{{ $secondaryLocale }}">{{ app()->getLocale()==='ar' ? 'More' : 'المزيد' }}</small></span>
                    <span class="premium-nav-chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="premium-more-list">
                    @foreach($premiumMoreItems as $item)
                        <a class="premium-nav-item {{ ($navContext ?? '') === $item['key'] ? 'active' : '' }}" href="{{ route($item['route'], $item['params']) }}" @if(($navContext ?? '') === $item['key']) aria-current="page" @endif>
                            @include('admin._premium-icon',['name'=>$item['icon']])
                            <span class="premium-nav-copy"><strong>{{ __($item['label']) }}</strong><small lang="{{ $secondaryLocale }}">{{ __($item['label'], [], $secondaryLocale) }}</small></span>
                            <span class="premium-nav-chevron" aria-hidden="true">‹</span>
                        </a>
                    @endforeach
                </div>
            </details>
        @endif
    </nav>
</div>
