@if(count($newOrderWizard['channels'] ?? []))
<style>
.new-order-backdrop{position:fixed;inset:0;z-index:1200;background:rgba(15,23,42,.62);display:none;align-items:center;justify-content:center;padding:20px}
.new-order-backdrop[data-open="1"]{display:flex}.new-order-dialog{width:min(980px,100%);max-height:92vh;overflow:auto;background:var(--foodex-surface,#fff);border-radius:22px;border:1px solid var(--foodex-border);box-shadow:0 24px 80px rgba(0,0,0,.28)}
.new-order-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;padding:22px 24px;border-bottom:1px solid var(--foodex-border)}.new-order-head h2{margin:0}.new-order-close{font-size:24px;line-height:1;border:0;background:transparent;cursor:pointer}
.new-order-progress{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;padding:16px 24px;border-bottom:1px solid var(--foodex-border)}.new-order-progress span{padding:8px 6px;border-radius:10px;text-align:center;font-size:.78rem;font-weight:800;background:rgba(0,0,0,.05)}.new-order-progress span[data-active="1"]{outline:2px solid currentColor}.new-order-progress span[data-complete="1"]{background:rgba(16,185,129,.13)}
.new-order-body{padding:24px}.new-order-step{display:none}.new-order-step[data-active="1"]{display:block}.new-order-step h3{margin-top:0}.new-order-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.new-order-field{display:grid;gap:6px;font-weight:700}.new-order-field select,.new-order-field input,.new-order-field textarea{width:100%;box-sizing:border-box}.new-order-lines{display:grid;gap:10px}.new-order-line{display:grid;grid-template-columns:minmax(260px,1fr) 150px auto;gap:8px;align-items:end;padding:12px;border:1px solid var(--foodex-border);border-radius:12px}.new-order-quote,.new-order-review{padding:14px;border:1px solid var(--foodex-border);border-radius:14px;background:rgba(0,0,0,.025);display:grid;gap:8px}.new-order-review-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.new-order-review-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.new-order-error{display:none;margin-bottom:14px;padding:12px;border-radius:10px;border:1px solid #dc2626}.new-order-error[data-visible="1"]{display:block}.new-order-discard{display:none;position:sticky;bottom:0;z-index:4;margin:0 24px 18px;padding:16px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface,#fff);box-shadow:0 16px 42px rgba(15,23,42,.18)}.new-order-discard[data-visible="1"]{display:grid;gap:12px}.new-order-discard h3,.new-order-discard p{margin:0}.new-order-discard-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.new-order-actions{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:18px 24px;border-top:1px solid var(--foodex-border)}.new-order-actions-right{display:flex;gap:8px}.new-order-actions button[disabled]{opacity:.45;cursor:not-allowed}
@media(max-width:760px){.new-order-progress{grid-template-columns:repeat(3,1fr)}.new-order-grid,.new-order-review-grid{grid-template-columns:1fr}.new-order-line{grid-template-columns:1fr 120px}.new-order-line .js-wizard-remove-line{grid-column:1/-1}.new-order-body{padding:16px}.new-order-head,.new-order-actions{padding-inline:16px}.new-order-discard{margin-inline:16px}.new-order-discard-actions{display:grid}.new-order-discard-actions button{width:100%;min-height:44px}}
</style>

<div class="new-order-backdrop" data-new-order-modal data-open="0" aria-hidden="true">
<section class="new-order-dialog" role="dialog" aria-modal="true" aria-labelledby="new-order-title">
<div class="new-order-head">
<div><h2 id="new-order-title">{{ $isAr ? 'إنشاء طلب جديد' : 'Create new order' }}</h2><p style="margin:6px 0 0">{{ $isAr ? 'أكمل الخطوات بالترتيب. لا يتم إنشاء أي طلب قبل التأكيد النهائي.' : 'Complete each step in order. Nothing is created until final confirmation.' }}</p></div>
<button type="button" class="new-order-close" data-new-order-cancel aria-label="{{ $isAr ? 'إغلاق' : 'Close' }}">×</button>
</div>

<div class="new-order-progress" aria-label="{{ $isAr ? 'خطوات إنشاء الطلب' : 'Order creation steps' }}">
@foreach([$isAr?'المتجر':'Store',$isAr?'العميل':'Customer',$isAr?'المنتجات':'Products',$isAr?'العنوان':'Address',$isAr?'الدفع':'Payment',$isAr?'المراجعة':'Review'] as $stepLabel)
<span data-wizard-progress="{{ $loop->index }}" data-active="{{ $loop->first?'1':'0' }}" data-complete="0">{{ $loop->iteration }}. {{ $stepLabel }}</span>
@endforeach
</div>

@php
$newOrderRestore = old('_order_create_context') === 'central';
$newOrderIdempotencyKey = $newOrderRestore && trim((string) old('idempotency_key', '')) !== ''
    ? trim((string) old('idempotency_key'))
    : 'dashboard:'.(string) \Illuminate\Support\Str::uuid();
$newOrderOldInput = $newOrderRestore ? [
    'channel' => old('channel'),
    'store_id' => old('store_id'),
    'warehouse_id' => old('warehouse_id'),
    'customer_id' => old('customer_id'),
    'address_id' => old('address_id'),
    'payment_method' => old('payment_method'),
    'coupon_code' => old('coupon_code'),
    'customer_note' => old('customer_note'),
    'items' => (array) old('items', []),
    'idempotency_key' => $newOrderIdempotencyKey,
] : null;
@endphp
<form method="post" action="{{ route('admin.operations.orders.store') }}" data-new-order-form data-quote-url="{{ route('admin.operations.orders.quote') }}" data-lookup-url="{{ route('admin.operations.orders.lookups') }}" aria-busy="false">
@csrf
<input type="hidden" name="_order_create_context" value="central">
<input type="hidden" name="idempotency_key" data-wizard-idempotency value="{{ $newOrderIdempotencyKey }}">
<div class="new-order-body">
<div class="new-order-error" data-wizard-error role="alert"></div>

<section class="new-order-step" data-wizard-step="0" data-active="1">
<h3>{{ $isAr?'1. المتجر والقناة':'1. Store & channel' }}</h3>
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr?'القناة':'Channel' }}</span><select name="channel" data-wizard-channel required><option value="">{{ $isAr?'اختر القناة':'Select channel' }}</option></select></label>
<label class="new-order-field"><span>{{ $isAr?'المتجر':'Store' }}</span><select name="store_id" data-wizard-store required><option value="">{{ $isAr?'اختر المتجر':'Select store' }}</option></select></label>
</div>
<label class="new-order-field" data-wizard-warehouse-wrap style="margin-top:14px;display:none"><span>{{ $isAr?'مخزن الصرف':'Source warehouse' }}</span><select name="warehouse_id" data-wizard-warehouse><option value="">{{ $isAr?'اختر مخزن الصرف':'Select source warehouse' }}</option></select></label>
</section>

<section class="new-order-step" data-wizard-step="1" data-active="0">
<h3>{{ $isAr?'2. العميل':'2. Customer' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العميل':'Customer' }}</span>
<input type="search" data-wizard-customer-search autocomplete="off" placeholder="{{ $isAr?'ابحث بالاسم أو الهاتف أو البريد':'Search name, phone or email' }}" aria-describedby="new-order-customer-state">
<select id="new-order-customer-results" name="customer_id" data-wizard-customer required disabled><option value="">{{ $isAr?'ابحث لاختيار العميل':'Search to select customer' }}</option></select>
<small id="new-order-customer-state" data-wizard-customer-state role="status" aria-live="polite">{{ $isAr?'اختر المتجر ثم ابحث عن العميل.':'Choose a store, then search for a customer.' }}</small>
</label>
</section>

<section class="new-order-step" data-wizard-step="2" data-active="0">
<h3>{{ $isAr?'3. المنتجات والكميات':'3. Products & quantities' }}</h3>
<div class="new-order-lines" data-wizard-lines></div>
<button type="button" class="btn secondary" data-wizard-add-line style="margin-top:10px">+ {{ $isAr?'إضافة صنف':'Add item' }}</button>
</section>

<section class="new-order-step" data-wizard-step="3" data-active="0">
<h3>{{ $isAr?'4. عنوان التوصيل':'4. Delivery address' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العنوان':'Address' }}</span><select name="address_id" data-wizard-address disabled><option value="">{{ $isAr?'اختر العميل أولاً':'Select customer first' }}</option></select></label>
<small data-wizard-address-state role="status" aria-live="polite">{{ $isAr?'يتم تحميل عناوين العميل المختار فقط.':'Only the selected customer addresses are loaded.' }}</small>
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

<div class="new-order-discard" data-new-order-discard data-visible="0" role="alertdialog" aria-modal="true" aria-labelledby="new-order-discard-title" aria-describedby="new-order-discard-description" aria-hidden="true">
<h3 id="new-order-discard-title">{{ __('order_operations.new_order_draft.title') }}</h3>
<p id="new-order-discard-description">{{ __('order_operations.new_order_draft.description') }}</p>
<div class="new-order-discard-actions">
<button type="button" class="btn secondary" data-new-order-continue>{{ __('order_operations.new_order_draft.continue_editing') }}</button>
<button type="button" class="foodex-primary" data-new-order-discard-confirm>{{ __('order_operations.new_order_draft.discard') }}</button>
</div>
</div>

<div class="foodex-state" data-wizard-submit-state role="status" aria-live="polite" hidden></div>
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
    const restoreInput = @json($newOrderOldInput);
    const restoreAfterValidation = @json($newOrderRestore && $errors->any());
    const restoreError = @json($newOrderRestore ? $errors->first() : '');
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
        submitting: @json(__('order_operations.new_order_submit.submitting')),
        lookupLoading: isAr ? 'جاري البحث…' : 'Searching…',
        lookupEmpty: isAr ? 'لا توجد نتائج مطابقة.' : 'No matching results.',
        lookupFailed: isAr ? 'تعذر تحميل النتائج. حاول مرة أخرى.' : 'Unable to load results. Try again.',
        customerHint: isAr ? 'ابحث بالاسم أو الهاتف أو البريد.' : 'Search by name, phone or email.',
        productHint: isAr ? 'ابحث بالاسم أو SKU أو الباركود.' : 'Search by name, SKU or barcode.',
    };

    const channelSelect = form.querySelector('[data-wizard-channel]');
    const storeSelect = form.querySelector('[data-wizard-store]');
    const warehouseWrap = form.querySelector('[data-wizard-warehouse-wrap]');
    const warehouseSelect = form.querySelector('[data-wizard-warehouse]');
    const customerSearch = form.querySelector('[data-wizard-customer-search]');
    const customerSelect = form.querySelector('[data-wizard-customer]');
    const customerState = form.querySelector('[data-wizard-customer-state]');
    const addressSelect = form.querySelector('[data-wizard-address]');
    const addressState = form.querySelector('[data-wizard-address-state]');
    const paymentSelect = form.querySelector('[data-wizard-payment]');
    const linesBox = form.querySelector('[data-wizard-lines]');
    const errorBox = form.querySelector('[data-wizard-error]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const backButton = form.querySelector('[data-wizard-back]');
    const createButton = form.querySelector('[data-wizard-create]');
    const idempotencyInput = form.querySelector('[data-wizard-idempotency]');
    const submitState = form.querySelector('[data-wizard-submit-state]');
    const quoteBox = form.querySelector('[data-wizard-quote]');
    const discardPanel = modal.querySelector('[data-new-order-discard]');
    const continueButton = discardPanel?.querySelector('[data-new-order-continue]');
    const discardButton = discardPanel?.querySelector('[data-new-order-discard-confirm]');
    const modalHeader = modal.querySelector('.new-order-head');
    const modalProgress = modal.querySelector('.new-order-progress');
    const modalBody = modal.querySelector('.new-order-body');
    const modalActions = modal.querySelector('.new-order-actions');
    let step = 0;
    let quoteReady = false;
    let initialized = false;
    let dirty = false;
    let isSubmitting = false;
    let discardReturnFocus = null;
    let modalSession = null;
    const createButtonLabel = createButton.textContent;

    const makeOption = (value, label) => {
        const node = document.createElement('option');
        node.value = String(value);
        node.textContent = label;
        return node;
    };
    const selectedText = (select) => select?.selectedOptions?.[0]?.textContent?.trim() || '—';
    const activeChannel = () => (source.channels || []).find((item) => item.code === channelSelect.value) || null;
    const activeStore = () => (activeChannel()?.stores || []).find((item) => String(item.id) === storeSelect.value) || null;
    const money = (value, currency = 'EGP') => {
        const amount = Number(value || 0);
        if (!Number.isFinite(amount)) return '0';
        return String(currency || '').trim().toUpperCase() === 'EGP'
            ? amount.toFixed(2)
            : amount.toFixed(3);
    };
    const quantity = (value) => {
        const amount = Number(value || 0);
        if (!Number.isFinite(amount)) return '0';
        return amount.toFixed(3).replace(/\.?0+$/, '');
    };
    const showError = (message = '') => { errorBox.textContent = message; errorBox.dataset.visible = message ? '1' : '0'; };
    const createIdempotencyKey = () => {
        const randomPart = globalThis.crypto?.randomUUID?.()
            || (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' + Math.random().toString(36).slice(2));
        return 'dashboard:' + randomPart;
    };
    const setSubmitting = (active) => {
        isSubmitting = active;
        form.setAttribute('aria-busy', active ? 'true' : 'false');
        createButton.disabled = active || !quoteReady;
        createButton.textContent = active ? t.submitting : createButtonLabel;
        if (submitState) {
            submitState.hidden = !active;
            submitState.textContent = active ? t.submitting : '';
        }
    };
    const invalidateQuote = () => {
        quoteReady = false;
        if (!isSubmitting) createButton.disabled = true;
    };

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

    const lookupRequests = new WeakMap();
    const lookupTimers = new WeakMap();

    const lookup = async (type, query = '', { customerId = null, page = 1, owner = form } = {}) => {
        const previous = lookupRequests.get(owner);
        previous?.abort();
        const controller = new AbortController();
        lookupRequests.set(owner, controller);
        const url = new URL(form.dataset.lookupUrl, window.location.origin);
        url.searchParams.set('channel', channelSelect.value);
        url.searchParams.set('store_id', storeSelect.value);
        url.searchParams.set('type', type);
        url.searchParams.set('q', query);
        url.searchParams.set('page', String(page));
        if (customerId) url.searchParams.set('customer_id', String(customerId));
        const response = await fetch(url, {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal: controller.signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(firstApiError(payload));
        return payload;
    };

    const fillLookupSelect = (select, items, placeholder) => {
        const previous = select.value;
        select.innerHTML = '';
        select.appendChild(makeOption('', placeholder));
        items.forEach((item) => {
            const label = [item.label, item.meta].filter(Boolean).join(' · ');
            select.appendChild(makeOption(item.id, label));
        });
        if ([...select.options].some((node) => node.value === previous)) select.value = previous;
        select.disabled = false;
    };

    const loadCustomers = async (query = '') => {
        if (!channelSelect.value || !storeSelect.value) return;
        customerState.textContent = t.lookupLoading;
        customerSelect.disabled = true;
        try {
            const payload = await lookup('customer', query, { owner: customerSearch });
            fillLookupSelect(customerSelect, payload.data || [], isAr ? 'اختر العميل' : 'Select customer');
            customerState.textContent = (payload.data || []).length ? t.customerHint : t.lookupEmpty;
        } catch (error) {
            if (error?.name === 'AbortError') return;
            customerState.textContent = t.lookupFailed;
        }
    };

    const refillStoreData = () => {
        const currentStore = activeStore();
        const isB2b = channelSelect.value === 'b2b';
        warehouseWrap.style.display = isB2b ? '' : 'none';
        warehouseSelect.required = isB2b;
        warehouseSelect.innerHTML = '';
        warehouseSelect.appendChild(makeOption('', isAr ? 'اختر مخزن الصرف' : 'Select source warehouse'));
        (currentStore?.warehouses || []).forEach((item) => warehouseSelect.appendChild(makeOption(item.id, item.code + ' · ' + item.name)));
        if (isB2b && warehouseSelect.options.length === 2) warehouseSelect.selectedIndex = 1;

        customerSearch.value = '';
        customerSelect.innerHTML = '';
        customerSelect.appendChild(makeOption('', isAr ? 'ابحث لاختيار العميل' : 'Search to select customer'));
        customerSelect.disabled = true;
        customerState.textContent = currentStore ? t.customerHint : (isAr ? 'اختر المتجر أولاً.' : 'Select a store first.');

        linesBox.innerHTML = '';
        addLine();
        refillAddresses();
        invalidateQuote();
        if (currentStore) loadCustomers('');
    };

    const refillAddresses = async () => {
        const previous = addressSelect.value;
        addressSelect.innerHTML = '';
        const customerId = customerSelect.value;
        if (!customerId || !channelSelect.value || !storeSelect.value) {
            addressSelect.appendChild(makeOption('', isAr ? 'اختر العميل أولاً' : 'Select customer first'));
            addressSelect.disabled = true;
            if (addressState) addressState.textContent = isAr ? 'اختر العميل لتحميل عناوينه.' : 'Select a customer to load addresses.';
            return;
        }

        addressSelect.disabled = true;
        addressSelect.appendChild(makeOption('', t.pickup));
        if (addressState) addressState.textContent = t.lookupLoading;
        try {
            const payload = await lookup('address', '', { customerId, owner: addressSelect });
            fillLookupSelect(addressSelect, payload.data || [], t.pickup);
            if ([...addressSelect.options].some((node) => node.value === previous)) addressSelect.value = previous;
            if (addressState) addressState.textContent = (payload.data || []).length ? (isAr ? 'العناوين المحملة تخص العميل المختار.' : 'Loaded addresses belong to the selected customer.') : t.lookupEmpty;
        } catch (error) {
            if (error?.name === 'AbortError') return;
            if (addressState) addressState.textContent = t.lookupFailed;
        }
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
        const productSearch = document.createElement('input');
        productSearch.type = 'search';
        productSearch.autocomplete = 'off';
        productSearch.placeholder = t.productHint;
        const productSelect = document.createElement('select');
        productSelect.dataset.wizardProduct = '1';
        productSelect.required = true;
        productSelect.disabled = true;
        productSelect.appendChild(makeOption('', isAr ? 'ابحث لاختيار المنتج' : 'Search to select product'));
        const productState = document.createElement('small');
        productState.setAttribute('role', 'status');
        productState.setAttribute('aria-live', 'polite');
        productState.textContent = t.productHint;

        const loadProducts = async (query = '') => {
            if (!channelSelect.value || !storeSelect.value) return;
            productState.textContent = t.lookupLoading;
            productSelect.disabled = true;
            try {
                const payload = await lookup('product', query, { owner: productSearch });
                const items = (payload.data || []).map((item) => ({
                    ...item,
                    meta: [item.meta, item.price === null || item.price === undefined ? '' : money(item.price, 'EGP')].filter(Boolean).join(' · '),
                }));
                fillLookupSelect(productSelect, items, isAr ? 'اختر المنتج' : 'Select product');
                productState.textContent = items.length ? t.productHint : t.lookupEmpty;
            } catch (error) {
                if (error?.name === 'AbortError') return;
                productState.textContent = t.lookupFailed;
            }
        };
        productSearch.addEventListener('input', () => {
            clearTimeout(lookupTimers.get(productSearch));
            lookupTimers.set(productSearch, setTimeout(() => loadProducts(productSearch.value.trim()), 220));
        });
        productSearch.addEventListener('focus', () => {
            if (productSelect.options.length <= 1) loadProducts(productSearch.value.trim());
        });
        productLabel.append(productCaption, productSearch, productSelect, productState);

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
            lookupRequests.get(productSearch)?.abort();
            row.remove();
            reindexLines();
            dirty = true;
            invalidateQuote();
        });

        row.append(productLabel, quantityLabel, remove);
        linesBox.appendChild(row);
        reindexLines();
        if (storeSelect.value) loadProducts('');
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
            item.textContent = (line.is_available ? '✓ ' : '⚠ ') + (line.sku || '') + ' · ' + (line.name || '') + ' — ' + quantity(line.quantity) + ' × ' + money(line.unit_price, currency) + ' = ' + money(line.line_total, currency) + ' ' + currency;
            lines.appendChild(item);
        });
        const totals = document.createElement('div');
        totals.textContent = t.subtotal + ': ' + money(quote.subtotal, currency) + ' ' + currency + ' · ' + t.discount + ': ' + money(quote.discount_total, currency) + ' ' + currency + ' · ' + t.delivery + ': ' + money(quote.delivery_total, currency) + ' ' + currency + ' · ' + t.tax + ': ' + money(quote.tax_total, currency) + ' ' + currency;
        const grand = document.createElement('strong');
        grand.textContent = t.total + ': ' + money(quote.grand_total, currency) + ' ' + currency;
        quoteBox.append(lines, totals, grand);
        quoteReady = !quote.has_unavailable_items;
        if (!isSubmitting) createButton.disabled = !quoteReady;
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

    const setDiscardIsolation = (active) => {
        [modalHeader, modalProgress, modalBody, modalActions].forEach((node) => {
            if (node) node.inert = active;
        });
    };

    const hideDiscardPrompt = ({ restoreFocus = true } = {}) => {
        if (!discardPanel) return;
        discardPanel.dataset.visible = '0';
        discardPanel.setAttribute('aria-hidden', 'true');
        setDiscardIsolation(false);
        if (restoreFocus && discardReturnFocus instanceof HTMLElement) discardReturnFocus.focus();
        discardReturnFocus = null;
    };

    const reset = ({ freshIdentity = initialized } = {}) => {
        form.reset();
        setSubmitting(false);
        if (freshIdentity && idempotencyInput) idempotencyInput.value = createIdempotencyKey();
        channelSelect.innerHTML = '';
        channelSelect.appendChild(makeOption('', isAr ? 'اختر القناة' : 'Select channel'));
        (source.channels || []).forEach((item) => channelSelect.appendChild(makeOption(item.code, item.label)));
        if (channelSelect.options.length === 2) channelSelect.selectedIndex = 1;
        refillStores();
        if (paymentSelect.options.length === 2) paymentSelect.selectedIndex = 1;
        invalidateQuote();
        setStep(0);
        dirty = false;
        hideDiscardPrompt({ restoreFocus: false });
        initialized = true;
    };

    const closeModal = () => {
        hideDiscardPrompt({ restoreFocus: false });
        modalSession?.deactivate({ restoreFocus: true });
        modalSession = null;
        modal.dataset.open = '0';
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const open = (event) => {
        if (modal.dataset.open === '1') return;
        if (!initialized) reset();
        const opener = event?.currentTarget instanceof HTMLElement ? event.currentTarget : document.activeElement;
        modal.dataset.open = '1';
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        const focusTarget = step === 0 ? channelSelect : form.querySelector('[data-wizard-step="' + step + '"] input, [data-wizard-step="' + step + '"] select, [data-wizard-step="' + step + '"] textarea, [data-wizard-step="' + step + '"] button');
        modalSession = window.FoodexModalA11y?.activate({
            root: modal,
            dialog: modal.querySelector('.new-order-dialog'),
            initialFocus: focusTarget || channelSelect,
            returnFocus: opener instanceof HTMLElement ? opener : null,
            fallbackFocus: document.querySelector('[data-new-order-open]'),
        }) || null;
        if (!modalSession) (focusTarget || channelSelect).focus();
    };

    const requestClose = (trigger = null) => {
        if (!dirty) {
            closeModal();
            return;
        }
        if (!discardPanel) return;
        discardReturnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
        setDiscardIsolation(true);
        discardPanel.dataset.visible = '1';
        discardPanel.setAttribute('aria-hidden', 'false');
        continueButton?.focus();
    };

    const discardAndClose = () => {
        reset({ freshIdentity: true });
        closeModal();
    };

    const restoreServerInput = () => {
        if (!restoreAfterValidation || !restoreInput) return false;

        channelSelect.value = String(restoreInput.channel || '');
        refillStores();
        storeSelect.value = String(restoreInput.store_id || '');
        refillStoreData();
        warehouseSelect.value = String(restoreInput.warehouse_id || '');
        customerSelect.value = String(restoreInput.customer_id || '');
        refillAddresses();
        addressSelect.value = String(restoreInput.address_id || '');
        paymentSelect.value = String(restoreInput.payment_method || '');

        const couponInput = form.querySelector('[name="coupon_code"]');
        const noteInput = form.querySelector('[name="customer_note"]');
        if (couponInput) couponInput.value = String(restoreInput.coupon_code || '');
        if (noteInput) noteInput.value = String(restoreInput.customer_note || '');

        const restoredItems = Array.isArray(restoreInput.items) ? restoreInput.items : [];
        linesBox.innerHTML = '';
        (restoredItems.length ? restoredItems : [{}]).forEach((item) => {
            addLine();
            const row = linesBox.lastElementChild;
            const product = row?.querySelector('[data-wizard-product]');
            const quantityInput = row?.querySelector('[data-wizard-quantity]');
            if (product) product.value = String(item?.product_id || '');
            if (quantityInput) quantityInput.value = String(item?.quantity || '1');
        });
        reindexLines();

        if (idempotencyInput) {
            idempotencyInput.value = String(restoreInput.idempotency_key || idempotencyInput.value || createIdempotencyKey());
        }

        dirty = true;
        invalidateQuote();
        setStep(5);
        if (restoreError) showError(restoreError);

        return true;
    };

    customerSearch.addEventListener('input', () => {
        clearTimeout(lookupTimers.get(customerSearch));
        lookupTimers.set(customerSearch, setTimeout(() => loadCustomers(customerSearch.value.trim()), 220));
    });
    customerSearch.addEventListener('focus', () => {
        if (customerSelect.options.length <= 1) loadCustomers(customerSearch.value.trim());
    });
    customerSelect.addEventListener('change', () => {
        refillAddresses();
        dirty = true;
        invalidateQuote();
    });

    document.querySelectorAll('[data-new-order-open]').forEach((button) => button.addEventListener('click', open));
    modal.querySelectorAll('[data-new-order-cancel]').forEach((button) => button.addEventListener('click', (event) => requestClose(event.currentTarget)));
    continueButton?.addEventListener('click', () => hideDiscardPrompt());
    discardButton?.addEventListener('click', discardAndClose);
    modal.addEventListener('click', (event) => { if (event.target === modal) requestClose(modal.querySelector('[data-new-order-cancel]')); });
    document.addEventListener('keydown', (event) => {
        if (modal.dataset.open !== '1') return;

        if (discardPanel?.dataset.visible === '1' && event.key === 'Tab') {
            const focusable = [continueButton, discardButton].filter((node) => node instanceof HTMLElement && !node.disabled);
            if (focusable.length) {
                const currentIndex = focusable.indexOf(document.activeElement);
                const nextIndex = event.shiftKey
                    ? (currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1)
                    : (currentIndex < 0 || currentIndex === focusable.length - 1 ? 0 : currentIndex + 1);
                event.preventDefault();
                focusable[nextIndex].focus();
            }
            return;
        }

        if (event.key !== 'Escape') return;
        if (discardPanel?.dataset.visible === '1') {
            event.preventDefault();
            hideDiscardPrompt();
            return;
        }
        event.preventDefault();
        requestClose(document.activeElement);
    });

    channelSelect.addEventListener('change', () => { refillStores(); invalidateQuote(); });
    storeSelect.addEventListener('change', () => { refillStoreData(); invalidateQuote(); });
    customerSelect.addEventListener('change', () => { refillAddresses(); invalidateQuote(); });
    form.addEventListener('input', () => { dirty = true; invalidateQuote(); });
    form.addEventListener('change', () => { dirty = true; invalidateQuote(); });
    form.querySelector('[data-wizard-add-line]').addEventListener('click', () => { addLine(); dirty = true; invalidateQuote(); });

    nextButton.addEventListener('click', () => {
        const error = validateStep();
        if (error) { showError(error); return; }
        setStep(step + 1);
    });
    backButton.addEventListener('click', () => setStep(step - 1));

    form.addEventListener('submit', (event) => {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

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
            return;
        }

        if (idempotencyInput && !idempotencyInput.value.trim()) {
            idempotencyInput.value = createIdempotencyKey();
        }
        setSubmitting(true);
        dirty = false;
    });

    reset();
    if (restoreServerInput()) open();
});
</script>
@endif