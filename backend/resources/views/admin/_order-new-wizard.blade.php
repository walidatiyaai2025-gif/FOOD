@if(count($newOrderWizard['channels'] ?? []))
<style>
.new-order-backdrop{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.62);display:none;align-items:center;justify-content:center;padding:20px}
.new-order-backdrop[data-open="1"]{display:flex}.new-order-dialog{width:min(980px,100%);max-height:92vh;overflow:auto;background:var(--foodex-surface,#fff);border-radius:22px;border:1px solid var(--foodex-border);box-shadow:0 24px 80px rgba(0,0,0,.28)}
.new-order-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;padding:22px 24px;border-bottom:1px solid var(--foodex-border)}.new-order-head h2{margin:0}.new-order-close{font-size:24px;line-height:1;border:0;background:transparent;cursor:pointer}
.new-order-progress{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;padding:16px 24px;border-bottom:1px solid var(--foodex-border)}.new-order-progress span{padding:8px 6px;border-radius:10px;text-align:center;font-size:.78rem;font-weight:800;background:rgba(0,0,0,.05)}.new-order-progress span[data-active="1"]{outline:2px solid currentColor}.new-order-progress span[data-complete="1"]{background:rgba(16,185,129,.13)}
.new-order-body{padding:24px}.new-order-step{display:none}.new-order-step[data-active="1"]{display:block}.new-order-step h3{margin-top:0}.new-order-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.new-order-field{display:grid;gap:6px;font-weight:700}.new-order-field select,.new-order-field input,.new-order-field textarea{width:100%;box-sizing:border-box}.new-order-lines{display:grid;gap:10px}.new-order-line{display:grid;grid-template-columns:minmax(260px,1fr) 150px auto;gap:8px;align-items:end;padding:12px;border:1px solid var(--foodex-border);border-radius:12px}.new-order-quote,.new-order-review{padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:rgba(0,0,0,.025);display:grid;gap:8px}.new-order-review-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.new-order-review-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.new-order-error{display:none;margin-bottom:14px;padding:12px;border-radius:10px;border:1px solid #dc2626}.new-order-error[data-visible="1"]{display:block}.new-order-actions{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:18px 24px;border-top:1px solid var(--foodex-border)}.new-order-actions-right{display:flex;gap:8px}.new-order-actions button[disabled]{opacity:.45;cursor:not-allowed}
@media(max-width:760px){.new-order-progress{grid-template-columns:repeat(3,1fr)}.new-order-grid,.new-order-review-grid{grid-template-columns:1fr}.new-order-line{grid-template-columns:1fr 120px}.new-order-line .js-wizard-remove-line{grid-column:1/-1}.new-order-body{padding:16px}.new-order-head,.new-order-actions{padding-inline:16px}}
</style>

<div class="new-order-backdrop" data-new-order-modal data-open="0" aria-hidden="true">
<section class="new-order-dialog" role="dialog" aria-modal="true" aria-labelledby="new-order-title">
<div class="new-order-head">
<div><h2 id="new-order-title">{{ $isAr ? 'إنشاء طلب جديد' : 'Create New Order' }}</h2><p style="margin:6px 0 0">{{ $isAr ? 'أكمل الخطوات بالترتيب. لا يتم إنشاء أي طلب قبل التأكيد النهائي.' : 'Complete each step in order. Nothing is created until final confirmation.' }}</p></div>
<button type="button" class="new-order-close" data-new-order-cancel aria-label="{{ $isAr ? 'إغلاق' : 'Close' }}">×</button>
</div>

<div class="new-order-progress" aria-label="{{ $isAr ? 'خطوات إنشاء الطلب' : 'Order creation steps' }}">
@foreach([$isAr?'المتجر':'Store',$isAr?'العميل':'Customer',$isAr?'المنتجات':'Products',$isAr?'العنوان':'Address',$isAr?'الدفع':'Payment',$isAr?'المراجعة':'Review'] as $stepLabel)
<span data-wizard-progress="{{ $loop->index }}" data-active="{{ $loop->first?'1':'0' }}" data-complete="0">{{ $loop->iteration }}. {{ $stepLabel }}</span>
@endforeach
</div>

<form method="post" action="{{ route('admin.operations.orders.store') }}" data-new-order-form data-quote-url="{{ route('admin.operations.orders.quote') }}">
@csrf
<div class="new-order-body">
<div class="new-order-error" data-wizard-error role="alert"></div>

<section class="new-order-step" data-wizard-step="0" data-active="1">
<h3>{{ $isAr?'1. المتجر والقناة':'1. Store & channel' }}</h3>
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr?'القناة':'Channel' }}</span><select name="channel" data-wizard-channel required><option value="">{{ $isAr?'اختر القناة':'Select channel' }}</option></select></label>
<label class="new-order-field"><span>{{ $isAr?'المتجر':'Store' }}</span><select name="store_id" data-wizard-store required><option value="">{{ $isAr?'اختر المتجر':'Select store' }}</option></select></label>
</div>
<label class="new-order-field" data-wizard-warehouse-wrap style="margin-top:14px;display:none"><span>{{ $isAr?'مخزن الصرف':'Source warehouse' }}</span><select name="warehouse_id" data-wizard-warehouse><option value="">{{ $isAr?'اختر المخزن':'Select warehouse' }}</option></select></label>
</section>

<section class="new-order-step" data-wizard-step="1" data-active="0">
<h3>{{ $isAr?'2. العميل':'2. Customer' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العميل':'Customer' }}</span><select name="customer_id" data-wizard-customer required><option value="">{{ $isAr?'اختر العميل':'Select customer' }}</option></select></label>
</section>

<section class="new-order-step" data-wizard-step="2" data-active="0">
<h3>{{ $isAr?'3. المنتجات والكميات':'3. Products & quantities' }}</h3>
<div class="new-order-lines" data-wizard-lines></div>
<button type="button" class="btn secondary" data-wizard-add-line style="margin-top:10px">+ {{ $isAr?'إضافة صنف':'Add item' }}</button>
</section>

<section class="new-order-step" data-wizard-step="3" data-active="0">
<h3>{{ $isAr?'4. عنوان التوصيل':'4. Delivery address' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العنوان':'Address' }}</span><select name="address_id" data-wizard-address><option value="">{{ $isAr?'بدون عنوان / استلام':'No address / pickup' }}</option></select></label>
<small>{{ $isAr?'العناوين المعروضة تخص العميل المختار فقط.':'Only addresses belonging to the selected customer are shown.' }}</small>
</section>

<section class="new-order-step" data-wizard-step="4" data-active="0">
<h3>{{ $isAr?'5. الدفع والملاحظات':'5. Payment & notes' }}</h3>
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr?'طريقة الدفع':'Payment method' }}</span><select name="payment_method" data-wizard-payment required><option value="">{{ $isAr?'اختر طريقة الدفع':'Select payment method' }}</option>@foreach($newOrderWizard['payment_methods'] as $method)<option value="{{ $method['code'] }}">{{ $method['label'] }}</option>@endforeach</select></label>
<label class="new-order-field"><span>{{ $isAr?'كود كوبون اختياري':'Optional coupon code' }}</span><input name="coupon_code" maxlength="80" autocomplete="off"></label>
</div>
<label class="new-order-field" style="margin-top:14px"><span>{{ $isAr?'ملاحظة الطلب':'Order note' }}</span><textarea name="customer_note" maxlength="1000" rows="3"></textarea></label>
</section>

<section class="new-order-step" data-wizard-step="5" data-active="0">
<h3>{{ $isAr?'6. المراجعة والتأكيد':'6. Review & create' }}</h3>
<div class="new-order-review">
<div class="new-order-review-grid">
<div class="new-order-review-item"><strong>{{ $isAr?'القناة والمتجر':'Channel & store' }}</strong><div data-review-store>—</div></div>
<div class="new-order-review-item"><strong>{{ $isAr?'العميل':'Customer' }}</strong><div data-review-customer>—</div></div>
<div class="new-order-review-item"><strong>{{ $isAr?'العنوان':'Address' }}</strong><div data-review-address>—</div></div>
<div class="new-order-review-item"><strong>{{ $isAr?'الدفع':'Payment' }}</strong><div data-review-payment>—</div></div>
</div>
<div class="new-order-review-item"><strong>{{ $isAr?'الأصناف':'Lines' }}</strong><div data-review-lines></div></div>
<div class="new-order-quote" data-wizard-quote role="status" aria-live="polite">{{ $isAr?'جاري التحقق من السعر والمخزون من السيرفر…':'Validating price and stock with the server…' }}</div>
</div>
</section>
</div>

<div class="new-order-actions">
<button type="button" class="btn secondary" data-new-order-cancel>{{ $isAr?'إلغاء':'Cancel' }}</button>
<div class="new-order-actions-right">
<button type="button" class="btn secondary" data-wizard-back disabled>{{ $isAr?'السابق':'Back' }}</button>
<button type="button" class="foodex-primary" data-wizard-next>{{ $isAr?'التالي':'Next' }}</button>
<button type="submit" class="foodex-primary" data-wizard-create style="display:none" disabled>{{ $isAr?'إنشاء الطلب':'Create order' }}</button>
</div>
</div>
</form>
</section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const source = @json($newOrderWizard);
    const modal = document.querySelector('[data-new-order-modal]');
    const form = modal?.querySelector('[data-new-order-form]');
    if (!modal || !form) return;

    const isAr = @json($isAr);
    const t = {
        channel: isAr ? 'اختر القناة.' : 'Select a channel.',
        store: isAr ? 'اختر المتجر.' : 'Select a store.',
        warehouse: isAr ? 'اختر مخزن الصرف.' : 'Select a source warehouse.',
        customer: isAr ? 'اختر العميل.' : 'Select a customer.',
        lines: isAr ? 'أضف صنفًا واحدًا على الأقل بكمية صحيحة.' : 'Add at least one item with a valid quantity.',
        duplicate: isAr ? 'لا يمكن تكرار نفس المنتج.' : 'The same product cannot be added twice.',
        payment: isAr ? 'اختر طريقة الدفع.' : 'Select a payment method.',
        quoteLoading: isAr ? 'جاري التحقق من السعر والمخزون من السيرفر…' : 'Validating price and stock with the server…',
        quoteFailed: isAr ? 'تعذر التحقق من الطلب.' : 'Unable to validate the order.',
        unavailable: isAr ? 'يوجد صنف غير متاح أو كمية غير مسموحة.' : 'One or more items are unavailable or have an invalid quantity.',
        subtotal: isAr ? 'الإجمالي الفرعي' : 'Subtotal',
        discount: isAr ? 'الخصم' : 'Discount',
        delivery: isAr ? 'التوصيل' : 'Delivery',
        tax: isAr ? 'الضريبة' : 'Tax',
        total: isAr ? 'الإجمالي النهائي' : 'Grand total',
        pickup: isAr ? 'بدون عنوان / استلام' : 'No address / pickup',
    };

    const channelSelect = form.querySelector('[data-wizard-channel]');
    const storeSelect = form.querySelector('[data-wizard-store]');
    const warehouseWrap = form.querySelector('[data-wizard-warehouse-wrap]');
    const warehouseSelect = form.querySelector('[data-wizard-warehouse]');
    const customerSelect = form.querySelector('[data-wizard-customer]');
    const addressSelect = form.querySelector('[data-wizard-address]');
    const paymentSelect = form.querySelector('[data-wizard-payment]');
    const linesBox = form.querySelector('[data-wizard-lines]');
    const errorBox = form.querySelector('[data-wizard-error]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const backButton = form.querySelector('[data-wizard-back]');
    const createButton = form.querySelector('[data-wizard-create]');
    const quoteBox = form.querySelector('[data-wizard-quote]');
    let step = 0;
    let quoteReady = false;

    const makeOption = (value, label) => {
        const node = document.createElement('option');
        node.value = String(value);
        node.textContent = label;
        return node;
    };
    const selectedText = (select) => select?.selectedOptions?.[0]?.textContent?.trim() || '—';
    const activeChannel = () => (source.channels || []).find((item) => item.code === channelSelect.value) || null;
    const activeStore = () => (activeChannel()?.stores || []).find((item) => String(item.id) === storeSelect.value) || null;
    const showError = (message = '') => { errorBox.textContent = message; errorBox.dataset.visible = message ? '1' : '0'; };
    const invalidateQuote = () => { quoteReady = false; createButton.disabled = true; };

    const setStep = (target) => {
        step = Math.max(0, Math.min(5, target));
        form.querySelectorAll('[data-wizard-step]').forEach((node) => { node.dataset.active = Number(node.dataset.wizardStep) === step ? '1' : '0'; });
        form.querySelectorAll('[data-wizard-progress]').forEach((node) => {
            const index = Number(node.dataset.wizardProgress);
            node.dataset.active = index === step ? '1' : '0';
            node.dataset.complete = index < step ? '1' : '0';
        });
        backButton.disabled = step === 0;
        nextButton.style.display = step === 5 ? 'none' : '';
        createButton.style.display = step === 5 ? '' : 'none';
        showError('');
        if (step === 5) loadReviewAndQuote();
    };

    const refillStores = () => {
        const previous = storeSelect.value;
        storeSelect.innerHTML = '';
        storeSelect.appendChild(makeOption('', isAr ? 'اختر المتجر' : 'Select store'));
        (activeChannel()?.stores || []).forEach((item) => storeSelect.appendChild(makeOption(item.id, item.name)));
        if ([...storeSelect.options].some((node) => node.value === previous)) storeSelect.value = previous;
        if (!storeSelect.value && storeSelect.options.length === 2) storeSelect.selectedIndex = 1;
        refillStoreData();
    };

    const refillStoreData = () => {
        const currentStore = activeStore();
        const isB2b = channelSelect.value === 'b2b';
        warehouseWrap.style.display = isB2b ? '' : 'none';
        warehouseSelect.required = isB2b;
        warehouseSelect.innerHTML = '';
        warehouseSelect.appendChild(makeOption('', isAr ? 'اختر المخزن' : 'Select warehouse'));
        (currentStore?.warehouses || []).forEach((item) => warehouseSelect.appendChild(makeOption(item.id, item.code + ' · ' + item.name)));
        if (isB2b && warehouseSelect.options.length === 2) warehouseSelect.selectedIndex = 1;

        customerSelect.innerHTML = '';
        customerSelect.appendChild(makeOption('', isAr ? 'اختر العميل' : 'Select customer'));
        (currentStore?.customers || []).forEach((item) => customerSelect.appendChild(makeOption(item.id, item.name)));
        if (customerSelect.options.length === 2) customerSelect.selectedIndex = 1;

        linesBox.innerHTML = '';
        addLine();
        refillAddresses();
        invalidateQuote();
    };

    const refillAddresses = () => {
        const previous = addressSelect.value;
        addressSelect.innerHTML = '';
        addressSelect.appendChild(makeOption('', t.pickup));
        const customerId = customerSelect.value;
        (activeStore()?.addresses || []).filter((item) => !customerId || String(item.customer_id) === customerId).forEach((item) => addressSelect.appendChild(makeOption(item.id, item.label)));
        if ([...addressSelect.options].some((node) => node.value === previous)) addressSelect.value = previous;
    };

    const reindexLines = () => {
        linesBox.querySelectorAll('[data-wizard-line]').forEach((row, index) => {
            row.querySelector('[data-wizard-product]').name = 'items[' + index + '][product_id]';
            row.querySelector('[data-wizard-quantity]').name = 'items[' + index + '][quantity]';
        });
    };

    const addLine = () => {
        const row = document.createElement('div');
        row.className = 'new-order-line';
        row.dataset.wizardLine = '1';

        const productLabel = document.createElement('label');
        productLabel.className = 'new-order-field';
        const productCaption = document.createElement('span');
        productCaption.textContent = isAr ? 'المنتج' : 'Product';
        const productSelect = document.createElement('select');
        productSelect.dataset.wizardProduct = '1';
        productSelect.required = true;
        productSelect.appendChild(makeOption('', isAr ? 'اختر المنتج' : 'Select product'));
        (activeStore()?.products || []).forEach((item) => {
            const price = item.price === null || item.price === undefined ? '' : ' · ' + Number(item.price).toFixed(3);
            productSelect.appendChild(makeOption(item.id, item.sku + ' · ' + item.name + price));
        });
        productLabel.append(productCaption, productSelect);

        const quantityLabel = document.createElement('label');
        quantityLabel.className = 'new-order-field';
        const quantityCaption = document.createElement('span');
        quantityCaption.textContent = isAr ? 'الكمية' : 'Quantity';
        const quantity = document.createElement('input');
        quantity.type = 'number';
        quantity.min = '0.001';
        quantity.step = '0.001';
        quantity.value = '1';
        quantity.required = true;
        quantity.dataset.wizardQuantity = '1';
        quantityLabel.append(quantityCaption, quantity);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn secondary js-wizard-remove-line';
        remove.textContent = isAr ? 'حذف' : 'Remove';
        remove.addEventListener('click', () => {
            if (linesBox.querySelectorAll('[data-wizard-line]').length <= 1) return;
            row.remove();
            reindexLines();
            invalidateQuote();
        });

        row.append(productLabel, quantityLabel, remove);
        linesBox.appendChild(row);
        reindexLines();
    };

    const validateStep = () => {
        if (step === 0) {
            if (!channelSelect.value) return t.channel;
            if (!storeSelect.value) return t.store;
            if (channelSelect.value === 'b2b' && !warehouseSelect.value) return t.warehouse;
        }
        if (step === 1 && !customerSelect.value) return t.customer;
        if (step === 2) {
            const rows = [...linesBox.querySelectorAll('[data-wizard-line]')];
            if (!rows.length || rows.some((row) => !row.querySelector('[data-wizard-product]').value || Number(row.querySelector('[data-wizard-quantity]').value) <= 0)) return t.lines;
            const ids = rows.map((row) => row.querySelector('[data-wizard-product]').value);
            if (new Set(ids).size !== ids.length) return t.duplicate;
        }
        if (step === 4 && !paymentSelect.value) return t.payment;
        return '';
    };

    const firstApiError = (payload) => {
        for (const messages of Object.values(payload?.errors || {})) {
            if (Array.isArray(messages) && messages.length) return String(messages[0]);
        }
        return payload?.message ? String(payload.message) : t.quoteFailed;
    };

    const renderReview = () => {
        let storeText = selectedText(channelSelect) + ' · ' + selectedText(storeSelect);
        if (channelSelect.value === 'b2b') storeText += ' · ' + selectedText(warehouseSelect);
        form.querySelector('[data-review-store]').textContent = storeText;
        form.querySelector('[data-review-customer]').textContent = selectedText(customerSelect);
        form.querySelector('[data-review-address]').textContent = selectedText(addressSelect) || t.pickup;
        form.querySelector('[data-review-payment]').textContent = selectedText(paymentSelect);
        const lineReview = form.querySelector('[data-review-lines]');
        lineReview.innerHTML = '';
        linesBox.querySelectorAll('[data-wizard-line]').forEach((row) => {
            const node = document.createElement('div');
            node.textContent = selectedText(row.querySelector('[data-wizard-product]')) + ' × ' + row.querySelector('[data-wizard-quantity]').value;
            lineReview.appendChild(node);
        });
    };

    const renderQuote = (quote) => {
        quoteBox.innerHTML = '';
        const currency = quote.currency || 'EGP';
        const lines = document.createElement('div');
        (quote.items || []).forEach((line) => {
            const item = document.createElement('div');
            item.textContent = (line.is_available ? '✓ ' : '⚠ ') + (line.sku || '') + ' · ' + (line.name || '') + ' — ' + Number(line.quantity || 0).toFixed(3) + ' × ' + Number(line.unit_price || 0).toFixed(3) + ' = ' + Number(line.line_total || 0).toFixed(3) + ' ' + currency;
            lines.appendChild(item);
        });
        const totals = document.createElement('div');
        totals.textContent = t.subtotal + ': ' + Number(quote.subtotal || 0).toFixed(3) + ' ' + currency + ' · ' + t.discount + ': ' + Number(quote.discount_total || 0).toFixed(3) + ' ' + currency + ' · ' + t.delivery + ': ' + Number(quote.delivery_total || 0).toFixed(3) + ' ' + currency + ' · ' + t.tax + ': ' + Number(quote.tax_total || 0).toFixed(3) + ' ' + currency;
        const grand = document.createElement('strong');
        grand.textContent = t.total + ': ' + Number(quote.grand_total || 0).toFixed(3) + ' ' + currency;
        quoteBox.append(lines, totals, grand);
        quoteReady = !quote.has_unavailable_items;
        createButton.disabled = !quoteReady;
        if (!quoteReady) showError(t.unavailable);
    };

    const loadReviewAndQuote = async () => {
        renderReview();
        invalidateQuote();
        quoteBox.textContent = t.quoteLoading;
        showError('');
        try {
            const response = await fetch(form.dataset.quoteUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(firstApiError(payload));
            renderQuote(payload.data || {});
        } catch (error) {
            quoteBox.textContent = t.quoteFailed;
            showError(error.message || t.quoteFailed);
        }
    };

    const reset = () => {
        form.reset();
        channelSelect.innerHTML = '';
        channelSelect.appendChild(makeOption('', isAr ? 'اختر القناة' : 'Select channel'));
        (source.channels || []).forEach((item) => channelSelect.appendChild(makeOption(item.code, item.label)));
        if (channelSelect.options.length === 2) channelSelect.selectedIndex = 1;
        refillStores();
        if (paymentSelect.options.length === 2) paymentSelect.selectedIndex = 1;
        invalidateQuote();
        setStep(0);
    };

    const open = () => {
        reset();
        modal.dataset.open = '1';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        channelSelect.focus();
    };
    const close = () => {
        modal.dataset.open = '0';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        reset();
    };

    document.querySelectorAll('[data-new-order-open]').forEach((button) => button.addEventListener('click', open));
    modal.querySelectorAll('[data-new-order-cancel]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && modal.dataset.open === '1') close(); });

    channelSelect.addEventListener('change', () => { refillStores(); invalidateQuote(); });
    storeSelect.addEventListener('change', () => { refillStoreData(); invalidateQuote(); });
    customerSelect.addEventListener('change', () => { refillAddresses(); invalidateQuote(); });
    form.addEventListener('input', invalidateQuote);
    form.addEventListener('change', invalidateQuote);
    form.querySelector('[data-wizard-add-line]').addEventListener('click', () => { addLine(); invalidateQuote(); });

    nextButton.addEventListener('click', () => {
        const error = validateStep();
        if (error) { showError(error); return; }
        setStep(step + 1);
    });
    backButton.addEventListener('click', () => setStep(step - 1));

    form.addEventListener('submit', (event) => {
        if (step !== 5) {
            event.preventDefault();
            const error = validateStep();
            if (error) {
                showError(error);
                return;
            }
            setStep(step + 1);
            return;
        }

        if (!quoteReady) {
            event.preventDefault();
            loadReviewAndQuote();
        }
    });

    reset();
});
</script>
@endif
