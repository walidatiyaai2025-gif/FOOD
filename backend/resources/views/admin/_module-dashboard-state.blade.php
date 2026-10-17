@php
    $moduleDashboardState = $state ?? 'empty';
    $allowedStates = ['loading', 'empty', 'error', 'stale', 'permission', 'disabled', 'current'];
    $moduleDashboardState = in_array($moduleDashboardState, $allowedStates, true) ? $moduleDashboardState : 'error';
    $stateTitle = $title ?? __('module_dashboard.states.'.$moduleDashboardState.'.title');
    $stateMessage = $message ?? __('module_dashboard.states.'.$moduleDashboardState.'.message');
    $stateRole = in_array($moduleDashboardState, ['error', 'permission'], true) ? 'alert' : 'status';
@endphp

<section
    class="foodex-module-state"
    data-foodex-module-state="{{ $moduleDashboardState }}"
    role="{{ $stateRole }}"
    @if($moduleDashboardState === 'loading') aria-live="polite" aria-busy="true" @endif
>
    <strong>{{ $stateTitle }}</strong>
    <p>{{ $stateMessage }}</p>

    @if(($retry_url ?? null) && $moduleDashboardState === 'error')
        <a class="foodex-action-secondary button secondary" href="{{ $retry_url }}">
            {{ $retry_label ?? __('order_operations.lookup.retry') }}
        </a>
    @endif
</section>
