@php($iconName = $name ?? 'dot')
<svg class="foodex-svg-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($iconName)
    @case('home')
        <path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>
        @break
    @case('orders')
        <path d="M4 5h2l2 10h9l2-7H7"/><circle cx="10" cy="19" r="1"/><circle cx="17" cy="19" r="1"/>
        @break
    @case('products')
        <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>
        @break
    @case('customers')
        <circle cx="9" cy="8" r="3"/><path d="M3.5 19c.6-4 2.5-6 5.5-6s4.9 2 5.5 6"/><path d="M16 7.5a2.5 2.5 0 0 1 0 5M16.5 14c2.2.5 3.5 2.2 4 5"/>
        @break
    @case('delivery')
        <path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>
        @break
    @case('bell')
        <path d="M6 9a6 6 0 0 1 12 0c0 6 2 6 2 7H4c0-1 2-1 2-7"/><path d="M10 20h4"/>
        @break
    @case('mobile')
        <rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10.5 5h3M11 18.5h2"/>
        @break
    @case('reports')
        <path d="M5 20V10M12 20V4M19 20v-7"/>
        @break
    @case('settings')
        <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>
        @break
    @case('inventory')
        <path d="M4 6h16v14H4zM8 6V4h8v2M4 11h16M9 15h6"/>
        @break
    @case('promotions')
        <path d="m4 12 8-8 8 8-8 8-8-8Z"/><circle cx="12" cy="12" r="2"/>
        @break
    @case('storefront')
        <path d="M4 10v10h16V10M3 10l2-6h14l2 6"/><path d="M3 10c0 2 3 2 4 0 1 2 4 2 5 0 1 2 4 2 5 0 1 2 4 2 4 0"/>
        @break
    @case('content')
        <rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 15 3-3 3 3 2-2 3 3"/><circle cx="8" cy="9" r="1"/>
        @break
    @case('more')
        <circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>
        @break
    @case('search')
        <circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>
        @break
    @case('globe')
        <circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>
        @break
    @case('revenue')
        <rect x="4" y="6" width="16" height="12" rx="2"/><path d="M8 10h8M8 14h5"/>
        @break
    @case('active-users')
        <circle cx="9" cy="9" r="3"/><path d="M4 20c.6-4 2.3-6 5-6s4.4 2 5 6"/><path d="M17 8v4M15 10h4"/>
        @break
    @default
        <circle cx="12" cy="12" r="5"/>
@endswitch
</svg>
