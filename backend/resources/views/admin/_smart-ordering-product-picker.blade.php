@php
    $pickerName = $name ?? 'source_product_id';
    $pickerId = $id ?? ('so-product-'.uniqid());
    $selectedId = $selectedId ?? null;
    $selectedLabel = $selectedLabel ?? '';
@endphp
<div class="so-product-picker" data-product-picker>
    <label for="{{ $pickerId }}">{{ app()->getLocale()==='ar' ? 'منتج FOODEX المرتبط' : 'Linked FOODEX product' }}</label>
    <input class="foodex-control" id="{{ $pickerId }}" type="search" autocomplete="off"
           role="combobox" aria-autocomplete="list" aria-expanded="false"
           data-product-search value="{{ $selectedLabel }}"
           placeholder="{{ app()->getLocale()==='ar' ? 'ابحث بالاسم أو SKU أو الباركود' : 'Search name, SKU or barcode' }}">
    <input type="hidden" name="{{ $pickerName }}" data-product-id value="{{ $selectedId }}">
    <div class="so-product-results" role="listbox" data-product-results hidden></div>
    <small>{{ app()->getLocale()==='ar' ? 'اتركه فارغًا لإنشاء صنف مستقل بسعر صريح.' : 'Leave empty for a standalone menu item with an explicit price.' }}</small>
</div>
