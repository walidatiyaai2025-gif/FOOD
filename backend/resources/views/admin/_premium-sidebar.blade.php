{{-- Compatibility wrapper only; the authoritative navigation is _sidebar. --}}
<div data-premium-sidebar="{{ $channel ?? 'b2c' }}">
    @include('admin._sidebar')
</div>
