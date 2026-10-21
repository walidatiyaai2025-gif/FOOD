@php
    $nodePrimary = $child['label_text'] ?? __($child['label']);
    $nodeSecondary = $child['label_secondary_text'] ?? __($child['label'], [], $secondaryLocale);
    $nodeActive = ($navContext ?? '') === $child['key'];
@endphp
<a class="foodex-nav-link {{ $nodeActive?'active':'' }}" href="{{ route($child['route'],$child['params']) }}" data-nav-label="{{ mb_strtolower($nodePrimary.' '.$nodeSecondary) }}" @if($nodeActive) aria-current="page" @endif>
    @include('admin._premium-icon',['name'=>$childIcon($child['key'])])
    <span class="foodex-nav-label"><strong>{{ $nodePrimary }}</strong><small lang="{{ $secondaryLocale }}">{{ $nodeSecondary }}</small></span>
</a>
@if(!empty($child['children']))
<div class="foodex-nav-subchildren">
    @foreach($child['children'] as $nestedChild)
        @include('admin._sidebar-node',['child'=>$nestedChild,'childIcon'=>$childIcon,'secondaryLocale'=>$secondaryLocale,'navContext'=>$navContext])
    @endforeach
</div>
@endif
