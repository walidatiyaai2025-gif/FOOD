@php
    $assignment = $row['_assignment'] ?? null;
    $withdrawRoute = $isB2bOrder ? 'admin.b2b.drivers.assignments.withdraw' : 'admin.b2c.drivers.assignments.withdraw';
@endphp
@if($assignment)
<div class="foodex-state"><strong>Current driver:</strong> {{ $assignment['driver_name'] }} · {{ $assignment['status'] }}</div>
<form method="post" action="{{ route($withdrawRoute,['assignment'=>$assignment['id']]) }}" class="links module-inline-form">
    @csrf
    @if(!$isB2bOrder)
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
    @endif
    <input name="note" maxlength="1000" placeholder="Assignment note">
    <button class="danger btn" type="submit">Remove assignment</button>
</form>
@endif
