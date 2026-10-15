@php
    $pickerName = $name ?? 'source_product_id';
    $pickerId = $id ?? ('so-product-'.uniqid());
    $pickerResultsId = $pickerId.'-results';
    $selectedId = $selectedId ?? null;
    $selectedLabel = $selectedLabel ?? '';
@endphp
<div class="so-product-picker"
     data-product-picker
     data-foodex-entity-picker
     data-entity-search-url="{{ route('admin.smart-ordering.menu.products', ['store_id'=>$site->store_id] + ($supportAccess ? ['support_access'=>1] : [])) }}"
     data-entity-result-key="products"
     data-entity-selection-mode="value"
     data-entity-meta-fields="sku,barcode,display_price,currency"
     data-entity-option-class="so-product-result"
     data-entity-state-class="so-product-result"
     data-entity-selected-label="{{ $selectedLabel }}"
     data-entity-selected-id="{{ $selectedId }}"
     data-entity-loading="{{ __('smart_ordering_menu.lookup.loading') }}"
     data-entity-no-results="{{ __('smart_ordering_menu.lookup.empty') }}"
     data-entity-error="{{ __('smart_ordering_menu.lookup.error') }}"
     data-entity-retry="{{ __('smart_ordering_menu.lookup.retry') }}"
     data-entity-selected="{{ __('smart_ordering_menu.lookup.selected') }}"
     data-entity-cleared="{{ __('smart_ordering_menu.lookup.cleared') }}"
     data-entity-inactive="{{ __('smart_ordering_menu.lookup.inactive') }}"
     data-entity-load-more="{{ __('smart_ordering_menu.lookup.load_more') }}">
    <label for="{{ $pickerId }}">{{ __('smart_ordering_menu.lookup.label') }}</label>
    <input class="foodex-control" id="{{ $pickerId }}" type="search" autocomplete="off"
           role="combobox" aria-autocomplete="list" aria-controls="{{ $pickerResultsId }}" aria-expanded="false"
           data-product-search data-foodex-entity-search value="{{ $selectedLabel }}"
           placeholder="{{ __('smart_ordering_menu.lookup.placeholder') }}">
    <input type="hidden" name="{{ $pickerName }}" data-product-id data-foodex-entity-id value="{{ $selectedId }}">
    <div id="{{ $pickerResultsId }}" class="so-product-results" role="listbox" data-product-results data-foodex-entity-results hidden></div>
    <div class="so-actions">
        <small>{{ __('smart_ordering_menu.lookup.hint') }}</small>
        <button type="button" class="foodex-secondary" data-foodex-entity-clear>{{ __('smart_ordering_menu.lookup.clear') }}</button>
    </div>
</div>