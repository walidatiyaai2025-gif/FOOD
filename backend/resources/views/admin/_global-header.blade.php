@php($foodexGlobalHeaderUser = $user ?? auth()->user())
@php($foodexGlobalHeaderExtraPartial = $foodexGlobalHeaderExtraPartial ?? null)
<header class="foodex-global-header {{ $foodexGlobalHeaderExtraPartial ? 'foodex-global-header--with-extra' : '' }}" data-foodex-global-header>
    <span class="foodex-global-header-spacer" aria-hidden="true"></span>
    <div class="foodex-global-header-center">
        <div class="foodex-global-header-search" data-foodex-dashboard-header-search>
            @include('admin._global-search', ['globalSearchVariant' => 'header'])
        </div>
        @if($foodexGlobalHeaderExtraPartial)
            <div class="foodex-global-header-extra" data-foodex-header-extra>
                @include($foodexGlobalHeaderExtraPartial)
            </div>
        @endif
    </div>
    <div class="foodex-global-header-utilities">
        @include('admin._live-notifications', ['user' => $foodexGlobalHeaderUser])
    </div>
</header>
