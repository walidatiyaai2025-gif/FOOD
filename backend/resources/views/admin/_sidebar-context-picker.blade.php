{{-- P160: choose an existing server-authorized workspace; no independent visibility policy. --}}
@php($workContexts = app(\App\Support\V2SidebarContextLinks::class)->fromAuthorizedGroups((array) $navGroups))
@if($workContexts !== [])
    <style>
        .foodex-work-context{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-surface)}
        .foodex-work-context summary{list-style:none;min-height:44px;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 12px;border-radius:var(--foodex-radius-control);color:var(--foodex-green-dark);font-weight:700;cursor:pointer}
        .foodex-work-context summary::-webkit-details-marker{display:none}
        .foodex-work-context summary:hover,.foodex-work-context[open] summary{background:var(--foodex-green-soft)}
        .foodex-work-context summary:focus-visible,.foodex-work-context a:focus-visible{outline:3px solid var(--foodex-green);outline-offset:2px}
        .foodex-work-context-list{display:grid;gap:3px;margin:0;padding:4px 6px 8px;list-style:none}
        .foodex-work-context-list a{display:flex;align-items:center;min-height:44px;padding:7px 10px;border-radius:var(--foodex-radius-control);color:var(--foodex-ink);font-weight:700;text-decoration:none;overflow-wrap:anywhere}
        .foodex-work-context-list a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
        aside.foodex-sidebar-collapsed .foodex-work-context summary{justify-content:center;padding:8px 4px}
        aside.foodex-sidebar-collapsed .foodex-work-context-title{display:none}
        @media(max-width:1023px){aside.foodex-sidebar-collapsed .foodex-work-context summary{justify-content:space-between;padding:8px 12px}aside.foodex-sidebar-collapsed .foodex-work-context-title{display:inline}}
    </style>
    <section class="foodex-work-context" data-foodex-work-context aria-label="{{ __('sidebar_contexts.title') }}">
        <details>
            <summary aria-label="{{ __('sidebar_contexts.title') }}">
                <span class="foodex-work-context-title">{{ __('sidebar_contexts.title') }}</span>
                <span aria-hidden="true">⌄</span>
            </summary>
            <nav aria-label="{{ __('sidebar_contexts.title') }}">
                <ul class="foodex-work-context-list">
                    @foreach($workContexts as $context)
                        <li><a data-work-context="{{ $context['context'] }}" href="{{ route($context['route'], $context['params']) }}">{{ __('sidebar_contexts.'.$context['context']) }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </details>
    </section>
@endif
