@php
    $isB2bOrder = $channel === 'b2b';
    $orderStoreRoute = $isB2bOrder ? 'admin.b2b.orders.store' : 'admin.b2c.orders.store';
    $currentStoreId = $isB2bOrder ? null : ($storeId ?? null);
@endphp

<details class="foodex-card" open style="margin:16px 0">
    <summary style="cursor:pointer;font-weight:800">
        {{ app()->getLocale()==='ar' ? 'إنشاء طلب جديد' : 'Create new order' }}
    </summary>
    <form method="post" action="{{ route($orderStoreRoute) }}" class="workspace-inline-form module-inline-form js-dashboard-order-form" style="margin-top:14px" data-order-channel="{{ $channel }}">
        @csrf
        @if($isB2bOrder)
            <select name="warehouse_id" class="js-order-warehouse" required>
                <option value="">{{ app()->getLocale()==='ar'?'اختر مخزن الصرف':'Select source warehouse' }}</option>
                @foreach($moduleData['warehouses'] as $warehouse)
                    <option value="{{ $warehouse['id'] }}">{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $currentStoreId }}">
            @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
        @endif

        <select name="customer_id" class="js-order-customer" required>
            <option value="">{{ app()->getLocale()==='ar'?'اختر العميل':'Select customer' }}</option>
            @foreach($moduleData['customers'] as $customer)
                <option value="{{ $customer['id'] }}">{{ $customer['name'] }}</option>
            @endforeach
        </select>

        <select name="address_id" class="js-order-address">
            <option value="">{{ app()->getLocale()==='ar'?'بدون عنوان / استلام':'No address / pickup' }}</option>
            @foreach($moduleData['addresses'] as $address)
                <option value="{{ $address['id'] }}" data-customer-id="{{ $address['customer_id'] }}">{{ $address['label'] }}</option>
            @endforeach
        </select>

        <select name="payment_method" required>
            @foreach($moduleData['payment_methods'] as $method)
                <option value="{{ $method }}" @selected($method===config('checkout.default_payment_method'))>{{ $method }}</option>
            @endforeach
        </select>

        <input name="discount_total" type="number" min="0" step="0.001" value="0" placeholder="{{ app()->getLocale()==='ar'?'الخصم':'Discount' }}">
        <input name="delivery_total" type="number" min="0" step="0.001" value="{{ number_format((float)config('checkout.delivery_fee',0),3,'.','') }}" placeholder="{{ app()->getLocale()==='ar'?'التوصيل':'Delivery' }}">
        <input name="customer_note" maxlength="1000" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة الطلب':'Order note' }}">

        <div class="js-order-lines" style="display:grid;gap:8px;flex:1 1 100%">
            <div class="js-order-line" style="display:flex;gap:8px;flex-wrap:wrap">
                <select name="items[0][product_id]" class="js-order-product" required style="min-width:260px">
                    <option value="">{{ app()->getLocale()==='ar'?'اختر المنتج':'Select product' }}</option>
                    @foreach($moduleData['products'] as $product)
                        <option value="{{ $product['id'] }}"
                            @if($isB2bOrder)
                                data-warehouse-ids="{{ implode(',', $product['warehouse_ids'] ?? []) }}"
                            @else
                                data-store-id="{{ $product['store_id'] }}"
                            @endif
                        >
                            {{ $product['sku'] }} · {{ $product['name'] }}
                            @if(array_key_exists('price',$product))
                                · {{ number_format((float)$product['price'],3) }} EGP
                            @endif
                        </option>
                    @endforeach
                </select>
                <input name="items[0][quantity]" type="number" min="0.001" step="0.001" value="1" required placeholder="{{ app()->getLocale()==='ar'?'الكمية':'Quantity' }}">
                <button type="button" class="js-remove-order-line" style="display:none">{{ app()->getLocale()==='ar'?'حذف':'Remove' }}</button>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="js-add-order-line">{{ app()->getLocale()==='ar'?'إضافة صنف':'Add item' }}</button>
            <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إنشاء الطلب':'Create order' }}</button>
        </div>
    </form>
</details>

@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const refresh = (form) => {
        const isB2b = form.dataset.orderChannel === 'b2b';
        const warehouse = form.querySelector('.js-order-warehouse')?.value || '';
        const store = form.querySelector('.js-order-store')?.value || form.querySelector('input[name="store_id"]')?.value || '';
        const customer = form.querySelector('.js-order-customer')?.value || '';

        form.querySelectorAll('.js-order-product option[value]').forEach((option) => {
            if (!option.value) return;
            let visible = true;
            if (isB2b) {
                const warehouseIds = (option.dataset.warehouseIds || '').split(',').filter(Boolean);
                visible = warehouse !== '' && warehouseIds.includes(warehouse);
            } else if (option.dataset.storeId) {
                visible = !store || option.dataset.storeId === store;
            }
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) option.selected = false;
        });

        form.querySelectorAll('.js-order-address option[data-customer-id]').forEach((option) => {
            const visible = !customer || option.dataset.customerId === customer;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) option.selected = false;
        });
    };

    const reindex = (form) => {
        form.querySelectorAll('.js-order-line').forEach((line, index) => {
            const product = line.querySelector('.js-order-product');
            const quantity = line.querySelector('input[type="number"]');
            if (product) product.name = `items[${index}][product_id]`;
            if (quantity) quantity.name = `items[${index}][quantity]`;
            const remove = line.querySelector('.js-remove-order-line');
            if (remove) remove.style.display = form.querySelectorAll('.js-order-line').length > 1 ? '' : 'none';
        });
        refresh(form);
    };

    document.querySelectorAll('.js-dashboard-order-form').forEach((form) => {
        refresh(form);
        form.querySelector('.js-order-warehouse')?.addEventListener('change', () => refresh(form));
        form.querySelector('.js-order-store')?.addEventListener('change', () => refresh(form));
        form.querySelector('.js-order-customer')?.addEventListener('change', () => refresh(form));

        form.querySelector('.js-add-order-line')?.addEventListener('click', () => {
            const container = form.querySelector('.js-order-lines');
            const source = container?.querySelector('.js-order-line');
            if (!container || !source) return;
            const clone = source.cloneNode(true);
            const product = clone.querySelector('.js-order-product');
            const quantity = clone.querySelector('input[type="number"]');
            if (product) product.value = '';
            if (quantity) quantity.value = '1';
            container.appendChild(clone);
            reindex(form);
        });

        form.addEventListener('click', (event) => {
            const button = event.target.closest('.js-remove-order-line');
            if (!button) return;
            const lines = form.querySelectorAll('.js-order-line');
            if (lines.length <= 1) return;
            button.closest('.js-order-line')?.remove();
            reindex(form);
        });
    });
});
</script>
@endonce
