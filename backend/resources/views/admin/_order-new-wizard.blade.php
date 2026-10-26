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
    'channel' => $newOrderWizard['fixed_channel'] ?? old('channel'),
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
<form method="post" action="{{ route('admin.operations.orders.store') }}" data-new-order-form data-quote-url="{{ route('admin.operations.orders.quote') }}" data-lookup-url="{{ route('admin.operations.orders.lookups',['type'=>'__TYPE__'],false) }}" aria-busy="false">
@csrf
<input type="hidden" name="_order_create_context" value="central">
<input type="hidden" name="idempotency_key" data-wizard-idempotency value="{{ $newOrderIdempotencyKey }}">
<div class="new-order-body">
<div class="new-order-error" data-wizard-error role="alert"></div>

<section class="new-order-step" data-wizard-step="0" data-active="1">
<h3>{{ $isAr?'1. المتجر والقناة':'1. Store & channel' }}</h3>
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr?'القناة':'Channel' }}</span><select data-wizard-channel required disabled aria-disabled="true" data-channel-fixed="1"><option value="">{{ $isAr?'القناة':'Channel' }}</option></select><input type="hidden" name="channel" value="{{ $newOrderWizard['fixed_channel'] ?? '' }}" data-wizard-channel-value><small>{{ $isAr ? 'القناة محددة تلقائياً حسب مساحة العمل الحالية ولا يمكن تغييرها.' : 'Channel is fixed automatically from the current workspace and cannot be changed.' }}</small></label>
<label class="new-order-field"><span>{{ $isAr?'المتجر':'Store' }}</span><select name="store_id" data-wizard-store required><option value="">{{ $isAr?'اختر المتجر':'Select store' }}</option></select></label>
</div>
<label class="new-order-field" data-wizard-warehouse-wrap style="margin-top:14px;display:none"><span>{{ $isAr?'مخزن الصرف':'Source warehouse' }}</span><select name="warehouse_id" data-wizard-warehouse><option value="">{{ $isAr?'اختر مخزن الصرف':'Select source warehouse' }}</option></select></label>
</section>

<section class="new-order-step" data-wizard-step="1" data-active="0">
<h3>{{ $isAr?'2. العميل':'2. Customer' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العميل':'Customer' }}</span>
<input type="search" data-wizard-customer-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_customer') }}" aria-describedby="new-order-customer-status">
<select name="customer_id" data-wizard-customer required disabled><option value="">{{ $isAr?'اختر العميل':'Select customer' }}</option></select>
<small id="new-order-customer-status" data-wizard-customer-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-wizard-customer-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
</section>

<section class="new-order-step" data-wizard-step="2" data-active="0">
<h3>{{ $isAr?'3. المنتجات والكميات':'3. Products & quantities' }}</h3>
<div class="new-order-lines" data-wizard-lines></div>
<button type="button" class="btn secondary" data-wizard-add-line style="margin-top:10px">+ {{ $isAr?'إضافة صنف':'Add item' }}</button>
</section>

<section class="new-order-step" data-wizard-step="3" data-active="0">
<h3>{{ $isAr?'4. عنوان التوصيل':'4. Delivery address' }}</h3>
<label class="new-order-field"><span>{{ $isAr?'العنوان':'Address' }}</span><select name="address_id" data-wizard-address disabled><option value="">{{ $isAr?'بدون عنوان / استلام':'No address / pickup' }}</option></select></label>
<small data-wizard-address-status role="status" aria-live="polite">{{ __('order_operations.lookup.select_customer_first') }}</small>
<button type="button" class="btn secondary" data-wizard-address-retry hidden>{{ __('order_operations.lookup.retry') }}</button>
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
        searchCustomer: @json(__('order_operations.lookup.search_customer')),
        searchProduct: @json(__('order_operations.lookup.search_product')),
        searching: @json(__('order_operations.lookup.searching')),
        noResults: @json(__('order_operations.lookup.no_results')),
        loadMore: @json(__('order_operations.lookup.load_more')),
        lookupFailed: @json(__('order_operations.lookup.failed')),
        selectedUnavailable: @json(__('order_operations.lookup.selected_unavailable')),
        selectCustomerFirst: @json(__('order_operations.lookup.select_customer_first')),
        addressLoading: @json(__('order_operations.lookup.address_loading')),
        resultsHelp: @json(__('order_operations.lookup.results_help')),
    };

    const channelSelect = form.querySelector('[data-wizard-channel]');
    const channelValue = form.querySelector('[data-wizard-channel-value]');
    const storeSelect = form.querySelector('[data-wizard-store]');
    const warehouseWrap = form.querySelector('[data-wizard-warehouse-wrap]');
    const warehouseSelect = form.querySelector('[data-wizard-warehouse]');
    const customerSelect = form.querySelector('[data-wizard-customer]');
    const customerSearch = form.querySelector('[data-wizard-customer-search]');
    const customerStatus = form.querySelector('[data-wizard-customer-status]');
    const customerMore = form.querySelector('[data-wizard-customer-more]');
    const addressSelect = form.querySelector('[data-wizard-address]');
    const addressStatus = form.querySelector('[data-wizard-address-status]');
    const addressRetry = form.querySelector('[data-wizard-address-retry]');
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
    let quoteRevision = 0;
    let quoteSession = 0;
    let quoteAbortController = null;
    let quoteOwner = null;
    let customerLookupAbort = null;
    let customerLookupPage = 1;
    let customerLookupQuery = '';
    let customerLookupSequence = 0;
    let lookupScopeVersion = 0;
    let customerSearchTimer = null;
    let addressLookupAbort = null;
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
    const ownsCurrentQuote = (owner) => owner !== null
        && owner.revision === quoteRevision
        && owner.session === quoteSession;
    const setSubmitting = (active) => {
        isSubmitting = active;
        form.setAttribute('aria-busy', active ? 'true' : 'false');
        createButton.disabled = active || !quoteReady || !ownsCurrentQuote(quoteOwner);
        createButton.textContent = active ? t.submitting : createButtonLabel;
        if (submitState) {
            submitState.hidden = !active;
            submitState.textContent = active ? t.submitting : '';
        }
    };
    const invalidateQuote = () => {
        quoteRevision += 1;
        quoteReady = false;
        quoteOwner = null;
        quoteAbortController?.abort();
        quoteAbortController = null;
        if (!isSubmitting) createButton.disabled = true;
    };

    const setStep = (target) => {
        step = Math.max(0, Math.min(5, target));
        if (step !== 5) invalidateQuote();
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

    const lookupUrl = (type) => String(form.dataset.lookupUrl || '').replace('__TYPE__', encodeURIComponent(type));
    const scopedLookupParams = () => ({
        channel: channelSelect.value,
        store_id: storeSelect.value,
        warehouse_id: channelSelect.value === 'b2b' ? warehouseSelect.value : '',
    });
    const lookupLabel = (item) => {
        const base = [item.label, item.meta].filter(Boolean).join(' · ');
        return item.active === false ? base + ' · ' + t.selectedUnavailable : base;
    };
    const replaceLookupOptions = (select, items, placeholder, { append = false, selectedId = null } = {}) => {
        const previousValue = selectedId ? String(selectedId) : select.value;
        const previousOption = previousValue
            ? [...select.options].find((option) => option.value === previousValue)?.cloneNode(true)
            : null;
        if (!append) {
            select.innerHTML = '';
            select.appendChild(makeOption('', placeholder));
        }
        const existing = new Set([...select.options].map((option) => option.value));
        (items || []).forEach((item) => {
            const value = String(item.id);
            if (existing.has(value)) return;
            const option = makeOption(value, lookupLabel(item));
            option.dataset.active = item.active === false ? '0' : '1';
            if (item.price !== undefined && item.price !== null) option.dataset.price = String(item.price);
            select.appendChild(option);
            existing.add(value);
        });
        if (previousValue && !existing.has(previousValue) && previousOption) {
            select.appendChild(previousOption);
        }
        if (previousValue && [...select.options].some((option) => option.value === previousValue)) {
            select.value = previousValue;
        }
    };
    const fetchLookup = async (type, params, signal) => {
        const url = new URL(lookupUrl(type), window.location.origin);
        Object.entries({...scopedLookupParams(), ...params}).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) url.searchParams.set(key, String(value));
        });
        const response = await fetch(url, {
            headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            signal,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(firstApiError(payload));
        return payload.data || {items: [], page: 1, has_more: false};
    };
    const loadCustomers = async (query = customerLookupQuery, page = 1, selectedId = null) => {
        if (!channelSelect.value || !storeSelect.value) return;
        customerLookupAbort?.abort();
        const controller = new AbortController();
        customerLookupAbort = controller;
        const sequence = ++customerLookupSequence;
        const scopeVersion = lookupScopeVersion;
        customerStatus.textContent = t.searching;
        customerMore.dataset.retry = '0';
        customerMore.textContent = t.loadMore;
        customerSelect.disabled = true;
        if (page === 1 && !selectedId) customerSelect.value = '';
        try {
            const result = await fetchLookup('customer', {q: query, page, selected_id: selectedId}, controller.signal);
            if (sequence !== customerLookupSequence || scopeVersion !== lookupScopeVersion) return;
            customerLookupQuery = query;
            customerLookupPage = page;
            replaceLookupOptions(customerSelect, result.items, isAr ? 'اختر العميل' : 'Select customer', {append: page > 1, selectedId});
            customerSelect.disabled = false;
            customerStatus.textContent = result.items.length ? t.resultsHelp : t.noResults;
            customerMore.dataset.retry = '0';
            customerMore.textContent = t.loadMore;
            customerMore.hidden = !result.has_more;
            if (selectedId) customerSelect.value = String(selectedId);
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (scopeVersion !== lookupScopeVersion) return;
            customerSelect.disabled = false;
            customerStatus.textContent = t.lookupFailed;
            customerMore.dataset.retry = '1';
            customerMore.textContent = @json(__('order_operations.lookup.retry'));
            customerMore.hidden = false;
        }
    };
    const loadAddresses = async (selectedId = null) => {
        addressLookupAbort?.abort();
        const controller = new AbortController();
        addressLookupAbort = controller;
        const scopeVersion = lookupScopeVersion;
        const customerId = customerSelect.value;
        addressSelect.innerHTML = '';
        addressSelect.appendChild(makeOption('', t.pickup));
        if (!customerId) {
            addressSelect.disabled = true;
            addressStatus.textContent = t.selectCustomerFirst;
            return;
        }
        addressSelect.disabled = true;
        addressRetry.hidden = true;
        addressStatus.textContent = t.addressLoading;
        try {
            const result = await fetchLookup('address', {customer_id: customerId, selected_id: selectedId}, controller.signal);
            if (scopeVersion !== lookupScopeVersion || String(customerSelect.value) !== String(customerId)) return;
            replaceLookupOptions(addressSelect, result.items, t.pickup, {selectedId});
            addressSelect.disabled = false;
            addressRetry.hidden = true;
            addressStatus.textContent = result.items.length ? t.resultsHelp : t.noResults;
            if (selectedId) addressSelect.value = String(selectedId);
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (scopeVersion !== lookupScopeVersion) return;
            addressSelect.disabled = false;
            addressStatus.textContent = t.lookupFailed;
            addressRetry.hidden = false;
        }
    };
    const loadProducts = async (row, query = '', page = 1, selectedId = null) => {
        const select = row.querySelector('[data-wizard-product]');
        const status = row.querySelector('[data-wizard-product-status]');
        const more = row.querySelector('[data-wizard-product-more]');
        if (!select || !status || !channelSelect.value || !storeSelect.value) return;
        row._lookupAbort?.abort();
        const controller = new AbortController();
        row._lookupAbort = controller;
        const sequence = (row._lookupSequence || 0) + 1;
        row._lookupSequence = sequence;
        const scopeVersion = lookupScopeVersion;
        status.textContent = t.searching;
        more.dataset.retry = '0';
        more.textContent = t.loadMore;
        select.disabled = true;
        try {
            const result = await fetchLookup('product', {q: query, page, selected_id: selectedId}, controller.signal);
            if (row._lookupSequence !== sequence || scopeVersion !== lookupScopeVersion) return;
            row._lookupQuery = query;
            row._lookupPage = page;
            replaceLookupOptions(select, result.items, isAr ? 'اختر المنتج' : 'Select product', {append: page > 1, selectedId});
            select.disabled = false;
            status.textContent = result.items.length ? t.resultsHelp : t.noResults;
            more.dataset.retry = '0';
            more.textContent = t.loadMore;
            more.hidden = !result.has_more;
            if (selectedId) select.value = String(selectedId);
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (scopeVersion !== lookupScopeVersion) return;
            select.disabled = false;
            status.textContent = t.lookupFailed;
            more.dataset.retry = '1';
            more.textContent = @json(__('order_operations.lookup.retry'));
            more.hidden = false;
        }
    };

    const refillStoreData = () => {
        lookupScopeVersion += 1;
        customerLookupAbort?.abort();
        addressLookupAbort?.abort();
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
        customerSelect.appendChild(makeOption('', isAr ? 'اختر العميل' : 'Select customer'));
        customerSelect.disabled = !currentStore;
        customerStatus.textContent = currentStore ? t.searching : t.resultsHelp;
        customerMore.hidden = true;
        customerLookupQuery = '';
        customerLookupPage = 1;

        linesBox.innerHTML = '';
        addLine();
        addressSelect.innerHTML = '';
        addressSelect.appendChild(makeOption('', t.pickup));
        addressSelect.disabled = true;
        addressStatus.textContent = t.selectCustomerFirst;
        if (currentStore) loadCustomers('', 1);
        invalidateQuote();
    };

    const reindexLines = () => {
        linesBox.querySelectorAll('[data-wizard-line]').forEach((row, index) => {
            row.querySelector('[data-wizard-product]').name = 'items[' + index + '][product_id]';
            row.querySelector('[data-wizard-quantity]').name = 'items[' + index + '][quantity]';
        });
    };

    const addLine = (selectedProductId = null) => {
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
        productSearch.placeholder = t.searchProduct;
        productSearch.dataset.wizardProductSearch = '1';
        const productSelect = document.createElement('select');
        productSelect.dataset.wizardProduct = '1';
        productSelect.required = true;
        productSelect.disabled = true;
        productSelect.appendChild(makeOption('', isAr ? 'اختر المنتج' : 'Select product'));
        const productStatus = document.createElement('small');
        productStatus.dataset.wizardProductStatus = '1';
        productStatus.setAttribute('role', 'status');
        productStatus.setAttribute('aria-live', 'polite');
        productStatus.textContent = t.resultsHelp;
        const productMore = document.createElement('button');
        productMore.type = 'button';
        productMore.className = 'btn secondary';
        productMore.dataset.wizardProductMore = '1';
        productMore.textContent = t.loadMore;
        productMore.hidden = true;
        productLabel.append(productCaption, productSearch, productSelect, productStatus, productMore);

        let searchTimer = null;
        productSearch.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadProducts(row, productSearch.value.trim(), 1), 250);
        });
        productMore.addEventListener('click', () => {
            const retrying = productMore.dataset.retry === '1';
            loadProducts(row, row._lookupQuery || '', retrying ? (row._lookupPage || 1) : (row._lookupPage || 1) + 1);
        });

        const quantityLabel = document.createElement('label');
        quantityLabel.className = 'new-order-field';
        const quantityCaption = document.createElement('span');
        quantityCaption.textContent = isAr ? 'الكمية' : 'Quantity';
        const quantityInput = document.createElement('input');
        quantityInput.type = 'number';
        quantityInput.min = '0.001';
        quantityInput.step = '0.001';
        quantityInput.value = '1';
        quantityInput.required = true;
        quantityInput.dataset.wizardQuantity = '1';
        quantityLabel.append(quantityCaption, quantityInput);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn secondary js-wizard-remove-line';
        remove.textContent = isAr ? 'حذف' : 'Remove';
        remove.addEventListener('click', () => {
            if (linesBox.querySelectorAll('[data-wizard-line]').length <= 1) return;
            row._lookupAbort?.abort();
            row.remove();
            reindexLines();
            dirty = true;
            invalidateQuote();
        });

        row.append(productLabel, quantityLabel, remove);
        linesBox.appendChild(row);
        reindexLines();
        loadProducts(row, '', 1, selectedProductId);
        return row;
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

    const renderQuote = (quote, owner) => {
        if (!ownsCurrentQuote(owner)) return;
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
        quoteOwner = owner;
        quoteReady = !quote.has_unavailable_items;
        if (!isSubmitting) createButton.disabled = !quoteReady || !ownsCurrentQuote(quoteOwner);
        if (!quoteReady) showError(t.unavailable);
    };

    const loadReviewAndQuote = async () => {
        renderReview();
        invalidateQuote();
        const owner = { revision: quoteRevision, session: quoteSession };
        const controller = new AbortController();
        quoteAbortController = controller;
        quoteBox.textContent = t.quoteLoading;
        showError('');
        try {
            const response = await fetch(form.dataset.quoteUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));
            if (controller.signal.aborted || !ownsCurrentQuote(owner)) return;
            if (!response.ok) throw new Error(firstApiError(payload));
            renderQuote(payload.data || {}, owner);
        } catch (error) {
            if (controller.signal.aborted || !ownsCurrentQuote(owner)) return;
            quoteBox.textContent = t.quoteFailed;
            showError(error.message || t.quoteFailed);
        } finally {
            if (quoteAbortController === controller) quoteAbortController = null;
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
        quoteSession += 1;
        invalidateQuote();
        form.reset();
        setSubmitting(false);
        if (freshIdentity && idempotencyInput) idempotencyInput.value = createIdempotencyKey();
        channelSelect.innerHTML = '';
        channelSelect.appendChild(makeOption('', isAr ? 'اختر القناة' : 'Select channel'));
        (source.channels || []).forEach((item) => channelSelect.appendChild(makeOption(item.code, item.label)));
        if (channelSelect.options.length === 2) channelSelect.selectedIndex = 1;
        if (channelValue) channelValue.value = String(source.fixed_channel || channelSelect.value || '');
        refillStores();
        if (paymentSelect.options.length === 2) paymentSelect.selectedIndex = 1;
        invalidateQuote();
        setStep(0);
        dirty = false;
        hideDiscardPrompt({ restoreFocus: false });
        initialized = true;
    };

    const closeModal = () => {
        quoteSession += 1;
        invalidateQuote();
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
        const focusTarget = step === 0 ? storeSelect : form.querySelector('[data-wizard-step="' + step + '"] input, [data-wizard-step="' + step + '"] select, [data-wizard-step="' + step + '"] textarea, [data-wizard-step="' + step + '"] button');
        modalSession = window.FoodexModalA11y?.activate({
            root: modal,
            dialog: modal.querySelector('.new-order-dialog'),
            initialFocus: focusTarget || storeSelect,
            returnFocus: opener instanceof HTMLElement ? opener : null,
            fallbackFocus: document.querySelector('[data-new-order-open]'),
        }) || null;
        if (!modalSession) (focusTarget || storeSelect).focus();
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

    const restoreServerInput = async () => {
        if (!restoreAfterValidation || !restoreInput) return false;

        channelSelect.value = String(source.fixed_channel || restoreInput.channel || '');
        if (channelValue) channelValue.value = channelSelect.value;
        refillStores();
        storeSelect.value = String(restoreInput.store_id || '');
        refillStoreData();
        warehouseSelect.value = String(restoreInput.warehouse_id || '');
        await loadCustomers('', 1, restoreInput.customer_id || null);
        customerSelect.value = String(restoreInput.customer_id || '');
        await loadAddresses(restoreInput.address_id || null);
        addressSelect.value = String(restoreInput.address_id || '');
        paymentSelect.value = String(restoreInput.payment_method || '');

        const couponInput = form.querySelector('[name="coupon_code"]');
        const noteInput = form.querySelector('[name="customer_note"]');
        if (couponInput) couponInput.value = String(restoreInput.coupon_code || '');
        if (noteInput) noteInput.value = String(restoreInput.customer_note || '');

        const restoredItems = Array.isArray(restoreInput.items) ? restoreInput.items : [];
        linesBox.innerHTML = '';
        for (const item of (restoredItems.length ? restoredItems : [{}])) {
            const row = addLine(item?.product_id || null);
            const quantityInput = row?.querySelector('[data-wizard-quantity]');
            if (quantityInput) quantityInput.value = String(item?.quantity || '1');
        }
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
    warehouseSelect.addEventListener('change', () => {
        lookupScopeVersion += 1;
        linesBox.querySelectorAll('[data-wizard-line]').forEach((row) => {
            row._lookupAbort?.abort();
            const select = row.querySelector('[data-wizard-product]');
            if (select) {
                select.innerHTML = '';
                select.appendChild(makeOption('', isAr ? 'اختر المنتج' : 'Select product'));
                select.value = '';
            }
            loadProducts(row, row._lookupQuery || '', 1);
        });
        dirty = true;
        invalidateQuote();
    });
    customerSearch.addEventListener('input', () => {
        window.clearTimeout(customerSearchTimer);
        customerSearchTimer = window.setTimeout(() => loadCustomers(customerSearch.value.trim(), 1), 250);
    });
    customerMore.addEventListener('click', () => {
        const retrying = customerMore.dataset.retry === '1';
        loadCustomers(customerLookupQuery, retrying ? customerLookupPage : customerLookupPage + 1);
    });
    addressRetry.addEventListener('click', () => loadAddresses(addressSelect.value || null));
    customerSelect.addEventListener('change', () => { loadAddresses(); invalidateQuote(); });
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

        if (!quoteReady || !ownsCurrentQuote(quoteOwner)) {
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
    restoreServerInput().then((restored) => { if (restored) open(); });
});
</script>
@endif
