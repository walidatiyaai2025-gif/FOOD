@php
    $isB2bOrder = $channel === 'b2b';
    $orderStoreRoute = $isB2bOrder ? 'admin.b2b.orders.store' : 'admin.b2c.orders.store';
    $orderQuoteRoute = $isB2bOrder ? 'admin.b2b.orders.quote' : 'admin.b2c.orders.quote';
    $currentStoreId = $isB2bOrder ? null : ($storeId ?? null);
    $isArOrder = app()->getLocale() === 'ar';
@endphp

<details class="foodex-card" open style="margin:16px 0">
    <summary style="cursor:pointer;font-weight:800">
        {{ $isArOrder ? 'إنشاء طلب جديد · متعدد المنتجات' : 'Create new order · Multi-line' }}
    </summary>
    <form method="post"
          action="{{ route($orderStoreRoute) }}"
          class="workspace-inline-form module-inline-form js-dashboard-order-form"
          style="margin-top:14px"
          data-order-channel="{{ $channel }}"
          data-order-quote-url="{{ route($orderQuoteRoute) }}">
        @csrf
        @if($isB2bOrder)
            <select name="warehouse_id" class="js-order-warehouse" required>
                <option value="">{{ $isArOrder?'اختر مخزن الصرف':'Select source warehouse' }}</option>
                @foreach($moduleData['warehouses'] as $warehouse)
                    <option value="{{ $warehouse['id'] }}">{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>
                @endforeach
            </select>
        @else
            <input type="hidden" name="store_id" value="{{ $currentStoreId }}">
            @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
        @endif

        <select name="customer_id" class="js-order-customer" required>
            <option value="">{{ $isArOrder?'اختر العميل':'Select customer' }}</option>
            @foreach($moduleData['customers'] as $customer)
                <option value="{{ $customer['id'] }}"
                    @if($isB2bOrder)
                        data-price-tier-id="{{ $customer['price_tier_id'] ?? '' }}"
                        data-platform-fallback="{{ !empty($customer['platform_fallback']) ? '1' : '0' }}"
                    @endif
                >{{ $customer['name'] }}</option>
            @endforeach
        </select>

        <select name="address_id" class="js-order-address">
            <option value="">{{ $isArOrder?'بدون عنوان / استلام':'No address / pickup' }}</option>
            @foreach($moduleData['addresses'] as $address)
                <option value="{{ $address['id'] }}" data-customer-id="{{ $address['customer_id'] }}">{{ $address['label'] }}</option>
            @endforeach
        </select>

        <select name="payment_method" required>
            @foreach($moduleData['payment_methods'] as $method)
                <option value="{{ $method }}" @selected($method===config('checkout.default_payment_method'))>{{ $method }}</option>
            @endforeach
        </select>

        <input name="coupon_code" maxlength="80" autocomplete="off" placeholder="{{ $isArOrder?'كود كوبون اختياري':'Optional coupon code' }}">
        <input name="customer_note" maxlength="1000" placeholder="{{ $isArOrder?'ملاحظة الطلب':'Order note' }}">

        <div class="js-order-lines" style="display:grid;gap:8px;flex:1 1 100%">
            <div class="js-order-line" style="display:flex;gap:8px;flex-wrap:wrap">
                <select name="items[0][product_id]" class="js-order-product" required style="min-width:260px">
                    <option value="">{{ $isArOrder?'اختر المنتج':'Select product' }}</option>
                    @foreach($moduleData['products'] as $product)
                        <option value="{{ $product['id'] }}"
                            @if($isB2bOrder)
                                data-warehouse-ids="{{ implode(',', $product['warehouse_ids'] ?? []) }}"
                                data-price-tier-ids="{{ implode(',', $product['price_tier_ids'] ?? []) }}"
                                data-fallback-price="{{ !empty($product['has_fallback_price']) ? '1' : '0' }}"
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
                <input name="items[0][quantity]" type="number" min="0.001" step="0.001" value="1" required placeholder="{{ $isArOrder?'الكمية':'Quantity' }}">
                <button type="button" class="js-remove-order-line" style="display:none">{{ $isArOrder?'حذف':'Remove' }}</button>
            </div>
        </div>

        <div class="js-order-quote" role="status" aria-live="polite" style="flex:1 1 100%;padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface)">
            {{ $isArOrder?'أكمل بيانات الطلب لعرض التسعير المعتمد من السيرفر.':'Complete the order to load the server-authoritative quote.' }}
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="js-add-order-line">{{ $isArOrder?'إضافة صنف':'Add item' }}</button>
            <button class="foodex-primary js-submit-order" type="submit" disabled>{{ $isArOrder?'إنشاء الطلب':'Create order' }}</button>
        </div>
    </form>
</details>

@once
<script>
document.addEventListener('DOMContentLoaded', () => {
    const labels = {
        loading: @json($isArOrder ? 'جاري احتساب التسعير المعتمد…' : 'Loading authoritative quote…'),
        incomplete: @json($isArOrder ? 'أكمل العميل وطريقة الدفع والمخزن/المنتجات والكميات.' : 'Complete customer, payment, warehouse/products and quantities.'),
        unavailable: @json($isArOrder ? 'يوجد صنف غير متاح أو كمية غير مسموحة.' : 'One or more items are unavailable or have an invalid quantity.'),
        quoteFailed: @json($isArOrder ? 'تعذر احتساب التسعير.' : 'Unable to calculate the quote.'),
        subtotal: @json($isArOrder ? 'الإجمالي الفرعي' : 'Subtotal'),
        discount: @json($isArOrder ? 'الخصم' : 'Discount'),
        delivery: @json($isArOrder ? 'التوصيل' : 'Delivery'),
        tax: @json($isArOrder ? 'الضريبة' : 'Tax'),
        total: @json($isArOrder ? 'الإجمالي النهائي' : 'Grand total'),
        available: @json($isArOrder ? 'متاح' : 'available'),
    };

    const money = (value) => Number(value || 0).toFixed(3);
    const quoteBox = (form) => form.querySelector('.js-order-quote');
    const submitButton = (form) => form.querySelector('.js-submit-order');

    const markDirty = (form, message = labels.incomplete) => {
        form.dataset.quoteReady = '0';
        const button = submitButton(form);
        if (button) button.disabled = true;
        const box = quoteBox(form);
        if (box) box.textContent = message;
    };

    const refresh = (form) => {
        const isB2b = form.dataset.orderChannel === 'b2b';
        const warehouse = form.querySelector('.js-order-warehouse')?.value || '';
        const store = form.querySelector('input[name="store_id"]')?.value || '';
        const customerSelect = form.querySelector('.js-order-customer');
        const customer = customerSelect?.value || '';
        const customerOption = customerSelect?.selectedOptions?.[0];
        const customerPriceTier = customerOption?.dataset.priceTierId || '';
        const customerPlatformFallback = customerOption?.dataset.platformFallback === '1';
        const productSelects = [...form.querySelectorAll('.js-order-product')];
        const selectedProducts = productSelects.map((select) => select.value).filter(Boolean);

        productSelects.forEach((select, selectIndex) => {
            select.querySelectorAll('option[value]').forEach((option) => {
            if (!option.value) return;
            let visible = true;
            if (isB2b) {
                const warehouseIds = (option.dataset.warehouseIds || '').split(',').filter(Boolean);
                const priceTierIds = (option.dataset.priceTierIds || '').split(',').filter(Boolean);
                const hasApprovedTierPrice = customerPriceTier !== '' && priceTierIds.includes(customerPriceTier);
                const hasPlatformFallback = customerPlatformFallback && option.dataset.fallbackPrice === '1';
                const pricingEligible = customer === '' || hasApprovedTierPrice || hasPlatformFallback;
                visible = warehouse !== '' && warehouseIds.includes(warehouse) && pricingEligible;
            } else if (option.dataset.storeId) {
                visible = !store || option.dataset.storeId === store;
            }
            const selectedElsewhere = selectedProducts.some(
                (value, index) => index !== selectIndex && value === option.value,
            );
            option.hidden = !visible || selectedElsewhere;
            option.disabled = !visible || selectedElsewhere;
            if (!visible && option.selected) option.selected = false;
            });
        });

        form.querySelectorAll('.js-order-address option[data-customer-id]').forEach((option) => {
            const visible = !customer || option.dataset.customerId === customer;
            option.hidden = !visible;
            option.disabled = !visible;
            if (!visible && option.selected) option.selected = false;
        });
    };

    const reindex = (form) => {
        const lines = form.querySelectorAll('.js-order-line');
        lines.forEach((line, index) => {
            const product = line.querySelector('.js-order-product');
            const quantity = line.querySelector('input[type="number"]');
            if (product) product.name = `items[${index}][product_id]`;
            if (quantity) quantity.name = `items[${index}][quantity]`;
            const remove = line.querySelector('.js-remove-order-line');
            if (remove) remove.style.display = lines.length > 1 ? '' : 'none';
        });
        refresh(form);
        markDirty(form);
        scheduleQuote(form);
    };

    const canQuote = (form) => {
        if (!form.querySelector('.js-order-customer')?.value) return false;
        if (!form.querySelector('select[name="payment_method"]')?.value) return false;
        if (form.dataset.orderChannel === 'b2b' && !form.querySelector('.js-order-warehouse')?.value) return false;

        const lines = [...form.querySelectorAll('.js-order-line')];
        return lines.length > 0 && lines.every((line) => {
            const product = line.querySelector('.js-order-product')?.value;
            const quantity = Number(line.querySelector('input[type="number"]')?.value || 0);
            return product && quantity > 0;
        });
    };

    const firstError = (payload) => {
        if (payload?.errors) {
            for (const messages of Object.values(payload.errors)) {
                if (Array.isArray(messages) && messages.length) return messages[0];
            }
        }
        return payload?.message || labels.quoteFailed;
    };

    const renderQuote = (form, quote) => {
        const box = quoteBox(form);
        if (!box) return;
        const currency = quote.currency || 'EGP';
        const lines = (quote.items || []).map((line) => {
            const available = line.available_quantity === null || line.available_quantity === undefined
                ? ''
                : ` · ${labels.available}: ${money(line.available_quantity)}`;
            const state = line.is_available ? '✓' : '⚠';
            return `<li>${state} ${line.sku || ''} · ${line.name || ''} — ${money(line.quantity)} × ${money(line.unit_price)} = ${money(line.line_total)} ${currency}${available}</li>`;
        }).join('');

        box.innerHTML = `
            <div style="display:grid;gap:6px">
                <strong>${quote.has_unavailable_items ? labels.unavailable : '✓ ' + (quote.pricing_source || '')}</strong>
                <ul style="margin:0;padding-inline-start:20px">${lines}</ul>
                <div><strong>${labels.subtotal}:</strong> ${money(quote.subtotal)} ${currency} ·
                <strong>${labels.discount}:</strong> ${money(quote.discount_total)} ${currency} ·
                <strong>${labels.delivery}:</strong> ${money(quote.delivery_total)} ${currency} ·
                <strong>${labels.tax}:</strong> ${money(quote.tax_total)} ${currency}</div>
                <div style="font-size:18px"><strong>${labels.total}: ${money(quote.grand_total)} ${currency}</strong></div>
            </div>`;

        form.dataset.quoteReady = quote.has_unavailable_items ? '0' : '1';
        const button = submitButton(form);
        if (button) button.disabled = quote.has_unavailable_items;
    };

    const runQuote = async (form) => {
        if (!canQuote(form)) {
            markDirty(form);
            return;
        }

        const box = quoteBox(form);
        if (box) box.textContent = labels.loading;
        form.dataset.quoteReady = '0';
        const button = submitButton(form);
        if (button) button.disabled = true;

        form._quoteAbort?.abort();
        const controller = new AbortController();
        form._quoteAbort = controller;
        const data = new FormData(form);
        data.delete('_method');

        try {
            const response = await fetch(form.dataset.orderQuoteUrl, {
                method: 'POST',
                body: data,
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(firstError(payload));
            renderQuote(form, payload.data || {});
        } catch (error) {
            if (error.name === 'AbortError') return;
            markDirty(form, error.message || labels.quoteFailed);
        }
    };

    const scheduleQuote = (form) => {
        clearTimeout(form._quoteTimer);
        form._quoteTimer = setTimeout(() => runQuote(form), 280);
    };

    document.querySelectorAll('.js-dashboard-order-form').forEach((form) => {
        if (!form.dataset.orderQuoteUrl) return;
        refresh(form);
        markDirty(form);

        form.addEventListener('input', () => {
            refresh(form);
            markDirty(form);
            scheduleQuote(form);
        });
        form.addEventListener('change', () => {
            refresh(form);
            markDirty(form);
            scheduleQuote(form);
        });

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

        form.addEventListener('submit', (event) => {
            if (form.dataset.quoteReady === '1') return;
            event.preventDefault();
            runQuote(form);
        });

        scheduleQuote(form);
    });
});
</script>
@endonce
