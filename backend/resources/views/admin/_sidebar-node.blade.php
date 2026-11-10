@php
    $nodePrimary = $child['label_text'] ?? __($child['label']);
    $nodeSecondary = $child['label_secondary_text'] ?? __($child['label'], [], $secondaryLocale);
    $nodeActive = ($navContext ?? '') === $child['key'];
    // $child is produced by the canonical authorized AdminNavigation tree.
    $itemStatus = app(\App\Support\V2SidebarItemStatus::class)->forAuthorizedItem($child);
@endphp
<a class="foodex-nav-link {{ $nodeActive?'active':'' }}" href="{{ route($child['route'],$child['params']) }}" data-nav-item="{{ $child['key'] }}" data-nav-label="{{ mb_strtolower($nodePrimary.' '.$nodeSecondary) }}" @if($nodeActive) aria-current="page" @endif>
    @include('admin._premium-icon',['name'=>$childIcon($child['key'])])
    <span class="foodex-nav-label">
        <strong>
            <span>{{ $nodePrimary }}</span>
            @if($itemStatus !== null)
                <span class="foodex-nav-item-status" data-status-item="{{ $child['key'] }}" data-item-status="{{ $itemStatus }}" aria-label="{{ __('sidebar_item_status.'.$itemStatus) }}">{{ __('sidebar_item_status.'.$itemStatus) }}</span>
            @endif
        </strong>
        <small lang="{{ $secondaryLocale }}">{{ $nodeSecondary }}</small>
    </span>
</a>
@if(!empty($child['children']))
<div class="foodex-nav-subchildren">
    @foreach($child['children'] as $nestedChild)
        @include('admin._sidebar-node',['child'=>$nestedChild,'childIcon'=>$childIcon,'secondaryLocale'=>$secondaryLocale,'navContext'=>$navContext])
    @endforeach
</div>
@endif
