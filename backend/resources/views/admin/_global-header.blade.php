@php($foodexGlobalHeaderUser = $user ?? auth()->user())
<header class="foodex-global-header" data-foodex-global-header>
    <span class="foodex-global-header-spacer" aria-hidden="true"></span>
    <div class="foodex-global-header-search" data-foodex-dashboard-header-search>
        @include('admin._global-search', ['globalSearchVariant' => 'header'])
    </div>
    <div class="foodex-global-header-utilities">
        @include('admin._live-notifications', ['user' => $foodexGlobalHeaderUser])
    </div>
</header>
