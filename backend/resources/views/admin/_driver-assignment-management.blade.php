@php($assignment = $row['_assignment'] ?? null)
@if($assignment)
<div class="foodex-state"><strong>Current driver:</strong> {{ $assignment['driver_name'] }}</div>
@endif
