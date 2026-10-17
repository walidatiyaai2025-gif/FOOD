@php
$chartRows = collect($chartRows ?? [])->take(12)->values();
$maxValue = max(0.0, (float) $chartRows->max(fn($row) => (float) ($row['value'] ?? 0)));
@endphp
<section class="foodex-viz-card foodex-module-chart">
<div class="foodex-viz-header">
<div><h2 class="foodex-viz-title">{{ $chartTitle }}</h2></div>
@if($drilldownUrl ?? null)<a class="erp-link" href="{{ $drilldownUrl }}">{{ __('enterprise_procurement.view') }}</a>@endif
</div>
@if($chartRows->isEmpty())
<div class="foodex-viz-empty">{{ __('enterprise_procurement.empty') }}</div>
@else
<div class="foodex-viz-canvas" role="img" aria-label="{{ $chartTitle }}">
<div class="foodex-viz-bars" aria-hidden="true">
@foreach($chartRows as $row)
@php($height=$maxValue > 0 ? max(8,min(100,((float)($row['value']??0)/$maxValue)*100)) : 8)
<span class="foodex-viz-bar" style="height:{{ number_format($height,1,'.','') }}%" title="{{ $row['label'] }} · {{ $row['value'] }}"></span>
@endforeach
</div>
</div>
<div class="foodex-module-chart-table-wrap">
<table class="foodex-module-chart-table">
<thead><tr><th>{{ __('enterprise_procurement.dashboard.table.label') }}</th><th>{{ __('enterprise_procurement.dashboard.table.value') }}</th><th>{{ __('enterprise_procurement.dashboard.table.secondary') }}</th></tr></thead>
<tbody>
@foreach($chartRows as $row)
<tr><td>{{ $row['label'] }}</td><td class="foodex-number">{{ $row['value'] }}</td><td>{{ $row['secondary'] ?? '—' }}</td></tr>
@endforeach
</tbody>
</table>
</div>
@endif
</section>
