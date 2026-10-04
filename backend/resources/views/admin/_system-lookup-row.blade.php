@php
    $ar = app()->getLocale() === 'ar';
    $isTier = $record instanceof \App\Models\B2bPriceTier;
    $labelAr = $isTier ? ($record->name_ar ?? $record->name) : $record->label_ar;
    $labelEn = $isTier ? ($record->name_en ?? $record->name) : $record->label_en;
    $sort = $isTier ? $record->priority : $record->sort_order;
    $typeKey = (string) request()->query('type', 'payment-operation-types');
    $lookupUser = auth()->user();
    $canManage = $lookupUser?->hasRole('SUPER_ADMIN') === true
        && $lookupUser?->hasPermission('lookups.manage') === true;
@endphp
<tr>
<td>{{ $record->id }}</td>
<td><span class="code">{{ $record->code }}</span><span class="immutable">{{ $ar ? 'ثابت' : 'immutable' }}</span></td>
<td>{{ $labelAr }}</td>
<td>{{ $labelEn }}</td>
<td>{{ $sort }}</td>
<td>
@if ($record->is_active)
<span class="badge on">{{ $ar ? 'نشط' : 'Active' }}</span>
@else
<span class="badge off">{{ $ar ? 'غير نشط' : 'Inactive' }}</span>
@endif
</td>
@if ($canManage)
<td>
<form method="post" action="{{ route('admin.operations.lookups.update', ['type' => $typeKey, 'lookup' => $record->id]) }}">
@csrf
@method('PATCH')
<div class="actions">
<input name="label_ar" value="{{ $labelAr }}" required aria-label="{{ $ar ? 'الاسم بالعربية' : 'Arabic label' }}">
<input name="label_en" value="{{ $labelEn }}" required aria-label="{{ $ar ? 'الاسم بالإنجليزية' : 'English label' }}">
<input type="number" name="sort_order" min="0" value="{{ $sort }}" required aria-label="{{ $ar ? 'الترتيب' : 'Sort order' }}">
<label><input type="checkbox" name="is_active" value="1" @checked($record->is_active)> {{ $ar ? 'نشط' : 'Active' }}</label>
<button class="btn">{{ $ar ? 'حفظ' : 'Save' }}</button>
</div>
</form>
</td>
@endif
</tr>
