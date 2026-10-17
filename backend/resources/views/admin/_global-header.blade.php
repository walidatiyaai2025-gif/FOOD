@php($foodexGlobalHeaderUser = $user ?? auth()->user())
<header class="foodex-global-header" data-foodex-global-header>
    <a class="foodex-global-header-brand" href="{{ route('admin.index') }}" aria-label="FOODEX">FOODEX</a>
    <div class="foodex-global-header-search" data-foodex-dashboard-header-search>
        @include('admin._global-search', ['globalSearchVariant' => 'header'])
    </div>
    <div class="foodex-global-header-utilities">
        @include('admin._live-notifications', ['user' => $foodexGlobalHeaderUser])
    </div>
</header>
