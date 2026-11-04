@if(!empty($moduleData['drivers']))
<div class="panel foodex-card" style="margin:12px 0">
    <strong>{{ app()->getLocale()==='ar'?'السائقون وطلباتهم':'Drivers & their orders' }}</strong>
    <p class="muted empty" style="margin:6px 0 10px">
        {{ app()->getLocale()==='ar'?'اضغط على السائق لعرض طلباته من الأحدث إلى الأقدم مع فلترة بالتاريخ.':'Select a driver to view orders newest first with date filters.' }}
    </p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach($moduleData['drivers'] as $driver)
            <button
                type="button"
                class="btn foodex-driver-premium-btn foodex-driver-premium-secondary"
                data-driver-orders-open
                data-url="{{ route('admin.drivers.orders',['driver'=>$driver['id'],'channel'=>$channel,'store_id'=>$driver['store_id'] ?? null]) }}"
            >{{ $driver['name'] }}</button>
        @endforeach
    </div>
</div>
@endif

@php
    $isB2bAssignmentManager = $channel === 'b2b';
    $reassignRoute = $isB2bAssignmentManager ? 'admin.b2b.orders.driver.reassign' : 'admin.b2c.orders.driver.reassign';
    $unassignRoute = $isB2bAssignmentManager ? 'admin.b2b.orders.driver.unassign' : 'admin.b2c.orders.driver.unassign';
    $activeStatuses = ['assigned','accepted','picked_up','out_for_delivery'];
    $proofReasonLabels = [
        'customer_no_answer' => app()->getLocale()==='ar' ? 'العميل لا يرد' : 'Customer did not answer',
        'wrong_address' => app()->getLocale()==='ar' ? 'العنوان غير صحيح' : 'Wrong address',
        'customer_refused' => app()->getLocale()==='ar' ? 'العميل رفض الاستلام' : 'Customer refused delivery',
        'customer_absent' => app()->getLocale()==='ar' ? 'العميل غير موجود' : 'Customer not available',
        'payment_issue' => app()->getLocale()==='ar' ? 'مشكلة في الدفع' : 'Payment issue',
        'order_issue' => app()->getLocale()==='ar' ? 'مشكلة في الطلب' : 'Order issue',
        'other' => app()->getLocale()==='ar' ? 'سبب آخر' : 'Other reason',
    ];
@endphp

@if(!empty($moduleData['assignments_list']))
<div class="panel foodex-card" style="margin:12px 0">
    <strong>{{ app()->getLocale()==='ar'?'إدارة تعيينات الطلبات والسائقين':'Order & driver assignment management' }}</strong>
    <p class="muted empty" style="margin:6px 0 12px">
        {{ app()->getLocale()==='ar'
            ? 'يمكن سحب الطلب من السائق أو إعادة تعيينه مع الاحتفاظ بسجل التعيينات السابق.'
            : 'Unassign or reassign an order while preserving the full assignment history.' }}
    </p>
    <div style="overflow:auto">
        <table class="foodex-table data module-table" data-pagination-required style="min-width:760px;width:100%">
            <thead>
                <tr>
                    <th>{{ app()->getLocale()==='ar'?'الطلب':'Order' }}</th>
                    <th>{{ app()->getLocale()==='ar'?'السائق':'Driver' }}</th>
                    <th>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</th>
                    <th>{{ app()->getLocale()==='ar'?'وقت التعيين':'Assigned at' }}</th>
                    <th>{{ app()->getLocale()==='ar'?'إثبات التسليم':'Delivery proof' }}</th>
                    <th>{{ app()->getLocale()==='ar'?'الإدارة':'Management' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($moduleData['assignments_list'] as $assignment)
                @php
                    $assignmentStatusCode = strtolower((string)($assignment['status'] ?? ''));
                    $assignmentStatusLabel = match($assignmentStatusCode) {
                        'assigned', 'pending' => app()->getLocale()==='ar' ? 'مُعيّن' : 'Assigned',
                        'accepted' => app()->getLocale()==='ar' ? 'مقبول' : 'Accepted',
                        'picked_up', 'in_transit', 'out_for_delivery' => app()->getLocale()==='ar' ? 'قيد التوصيل' : 'In delivery',
                        'delivered', 'completed' => app()->getLocale()==='ar' ? 'مكتمل' : 'Completed',
                        'cancelled', 'canceled' => app()->getLocale()==='ar' ? 'ملغي' : 'Cancelled',
                        default => app()->getLocale()==='ar' ? 'حالة التعيين' : 'Assignment status',
                    };
                    $assignmentStatusClass = in_array($assignmentStatusCode, ['delivered','completed'], true) ? 'active' : '';
                @endphp
                <tr>
                    <td><strong>{{ $assignment['order'] }}</strong></td>
                    <td><button type="button" class="btn foodex-driver-premium-btn foodex-driver-premium-secondary" data-driver-orders-open data-url="{{ route('admin.drivers.orders',['driver'=>$assignment['driver_id'],'channel'=>$channel,'store_id'=>$assignment['store_id']]) }}">{{ $assignment['driver'] }}</button></td>
                    <td><span class="badge {{ $assignmentStatusClass }}">{{ $assignmentStatusLabel }}</span></td>
                    <td>{{ $assignment['assigned_at'] }}</td>
                    <td style="min-width:210px">
                        @if(!empty($assignment['proof']))
                            @if(!empty($assignment['proof']['file_path']) && !empty($assignment['proof']['id']))
                                <a
                                    class="btn foodex-driver-premium-btn foodex-driver-premium-secondary"
                                    href="{{ route('admin.driver-live-tracking.proofs.show', ['assignment' => $assignment['id'], 'proof' => $assignment['proof']['id']]) }}"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    {{ app()->getLocale()==='ar'?'فتح صورة الإثبات':'Open proof image' }}
                                </a>
                            @endif
                            @if(!empty($assignment['proof']['reason_code']))
                                <div style="margin-top:6px;font-weight:700">
                                    {{ $proofReasonLabels[$assignment['proof']['reason_code']] ?? $assignment['proof']['reason_code'] }}
                                </div>
                            @endif
                            @if(!empty($assignment['proof']['note']))
                                <div class="muted" style="margin-top:4px;white-space:normal">
                                    {{ $assignment['proof']['note'] }}
                                </div>
                            @endif
                            @if(!empty($assignment['proof']['captured_at']))
                                <small class="muted">{{ $assignment['proof']['captured_at'] }}</small>
                            @endif
                        @else
                            <span class="muted">{{ app()->getLocale()==='ar'?'لا يوجد إثبات':'No proof' }}</span>
                        @endif
                    </td>
                    <td>
                        @if(in_array($assignment['status'],$activeStatuses,true))
                        <div style="display:grid;gap:7px;min-width:260px">
                            <form method="post" action="{{ route($reassignRoute,['order'=>$assignment['order_id']]) }}" class="links module-inline-form workspace-inline-form" style="margin:0;padding:0;border:0;background:transparent">
                                @csrf @method('PATCH')
                                @if(!$isB2bAssignmentManager)
                                    <input type="hidden" name="store_id" value="{{ $assignment['store_id'] }}">
                                    @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
                                @endif
                                <select name="driver_id" required>
                                    <option value="">{{ app()->getLocale()==='ar'?'اختر السائق الجديد':'Select new driver' }}</option>
                                    @foreach($moduleData['drivers'] as $driver)
                                        @if(($isB2bAssignmentManager || ($driver['store_id'] ?? null)===$assignment['store_id']) && $driver['id']!==$assignment['driver_id'])
                                            <option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button class="foodex-primary foodex-driver-premium-btn" type="submit">{{ app()->getLocale()==='ar'?'إعادة تعيين':'Reassign' }}</button>
                            </form>
                            <form method="post" action="{{ route($unassignRoute,['order'=>$assignment['order_id']]) }}" style="margin:0" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'سحب الطلب من السائق الحالي؟':'Unassign this order from the current driver?' }}')">
                                @csrf @method('DELETE')
                                @if(!$isB2bAssignmentManager)
                                    <input type="hidden" name="store_id" value="{{ $assignment['store_id'] }}">
                                    @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
                                @endif
                                <input type="hidden" name="reason" value="manual_unassign">
                                <button class="danger btn foodex-driver-premium-btn" type="submit">{{ app()->getLocale()==='ar'?'سحب الطلب':'Unassign' }}</button>
                            </form>
                        </div>
                        @else
                            <span class="muted">{{ app()->getLocale()==='ar'?'سجل سابق':'History' }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if(isset($moduleData['assignments_paginator'])){{ $moduleData['assignments_paginator']->links() }}@endif
</div>
@endif


<style data-driver-order-print-style>
/* The driver drilldown reuses the order-operations popup HTML on a different page.
   Scope its presentation here; order-operations page CSS is not loaded on B2B/B2C Drivers. */
[data-driver-order-details-dialog] {
    width: min(920px, 96vw) !important;
    max-width: 96vw;
    max-height: min(88vh, 960px);
    padding: 0 !important;
    border: 1px solid var(--foodex-border, #dbe3e8) !important;
    border-radius: 16px;
    background: var(--foodex-surface, #fff);
    color: var(--foodex-ink, #17271d);
}
[data-driver-order-details-dialog]::backdrop { background: rgba(15, 23, 42, .55); }
[data-driver-order-details-head] {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 18px;
    border-bottom: 1px solid var(--foodex-border, #dbe3e8);
}
[data-driver-order-details-body] {
    padding: 18px;
    overflow-y: auto;
    max-height: calc(88vh - 76px);
}
[data-driver-order-details-dialog] .order-print-header,
[data-driver-order-details-dialog] .order-print-footer { display: none; }
[data-driver-order-details-dialog] .order-popup-content { display: grid; gap: 14px; min-width: 0; }
[data-driver-order-details-dialog] .order-popup-toolbar {
    display: flex;
    justify-content: flex-start;
    margin: 0;
}
[data-driver-order-details-dialog] .order-popup-toolbar .foodex-primary {
    min-height: 40px;
    padding: 8px 18px;
    cursor: pointer;
}
[data-driver-order-details-dialog] .order-popup-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
}
[data-driver-order-details-dialog] .order-popup-summary > div,
[data-driver-order-details-dialog] .order-popup-facts > div {
    display: grid;
    align-content: start;
    gap: 5px;
    min-width: 0;
    padding: 12px;
    border: 1px solid var(--foodex-border, #dbe3e8);
    border-radius: 10px;
    background: var(--foodex-surface-soft, var(--foodex-palette-f8fafc, #f8fafc));
}
[data-driver-order-details-dialog] .order-popup-summary small,
[data-driver-order-details-dialog] .order-popup-facts span {
    color: var(--foodex-muted, #64748b);
    font-size: .75rem;
}
[data-driver-order-details-dialog] .order-popup-summary strong,
[data-driver-order-details-dialog] .order-popup-facts strong {
    font-size: .9rem;
    overflow-wrap: anywhere;
    line-height: 1.5;
}
[data-driver-order-details-dialog] .is-paid { color: var(--foodex-green-dark, #12652d); }
[data-driver-order-details-dialog] .is-unpaid { color: var(--foodex-danger-text, #b42318); }
[data-driver-order-details-dialog] .order-popup-section {
    min-width: 0;
    margin: 0;
    padding: 14px;
    border: 1px solid var(--foodex-border, #dbe3e8);
    border-radius: 12px;
}
[data-driver-order-details-dialog] .order-popup-section h3 {
    margin: 0 0 12px;
    font-size: 1rem;
    color: var(--foodex-ink, #17271d);
}
[data-driver-order-details-dialog] .order-popup-section p {
    margin: 0 0 8px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}
[data-driver-order-details-dialog] .order-popup-facts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}
[data-driver-order-details-dialog] .order-popup-table-wrap { width: 100%; overflow-x: auto; }
[data-driver-order-details-dialog] .order-popup-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .84rem;
}
[data-driver-order-details-dialog] .order-popup-table th,
[data-driver-order-details-dialog] .order-popup-table td {
    padding: 10px;
    text-align: start;
    border-bottom: 1px solid var(--foodex-border, #dbe3e8);
    vertical-align: top;
}
[data-driver-order-details-dialog] .order-popup-table th {
    background: var(--foodex-surface-soft, var(--foodex-palette-f8fafc, #f8fafc));
}
@media (max-width: 600px) {
    [data-driver-order-details-head] { padding: 10px 12px; }
    [data-driver-order-details-body] { padding: 12px; }
    [data-driver-order-details-dialog] .order-popup-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    [data-driver-order-details-dialog] .order-popup-facts { grid-template-columns: 1fr; }
    [data-driver-order-details-dialog] .order-popup-summary > div { padding: 9px; }
}
@media print {
    @page { size: A4; margin: 14mm; }
    body * { visibility: hidden !important; }
    [data-driver-order-details-dialog][open],
    [data-driver-order-details-dialog][open] * { visibility: visible !important; }
    [data-driver-order-details-dialog]::backdrop { display: none !important; }
    [data-driver-order-details-dialog][open] {
        display: block !important;
        position: fixed !important;
        inset: 0 !important;
        width: 100% !important;
        max-width: none !important;
        max-height: none !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #111 !important;
    }
    [data-driver-order-details-head],
    [data-driver-order-details-dialog] [data-no-print] { display: none !important; }
    [data-driver-order-details-body] {
        max-height: none !important;
        overflow: visible !important;
        padding: 0 !important;
    }
    [data-driver-order-details-dialog] .order-popup-content { gap: 9px; }
    [data-driver-order-details-dialog] .order-print-header {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        align-items: center;
        gap: 12px;
        margin-bottom: 8px;
        padding-bottom: 10px;
        border-bottom: 2px solid #12652d;
    }
    [data-driver-order-details-dialog] .order-print-header > div:first-child { display: none; }
    [data-driver-order-details-dialog] .order-print-type { display: grid; gap: 4px; }
    [data-driver-order-details-dialog] .order-print-brand {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 7px;
    }
    [data-driver-order-details-dialog] .order-print-brand img {
        display: block;
        max-height: 55px;
        max-width: 140px;
        object-fit: contain;
    }
    [data-driver-order-details-dialog] .order-popup-summary > div,
    [data-driver-order-details-dialog] .order-popup-facts > div,
    [data-driver-order-details-dialog] .order-popup-section {
        border-color: #d0d6d3 !important;
        background: #fff !important;
        break-inside: avoid;
    }
    [data-driver-order-details-dialog] .order-popup-summary strong,
    [data-driver-order-details-dialog] .order-popup-facts strong { color: #111 !important; }
    [data-driver-order-details-dialog] .order-popup-table thead { display: table-header-group; }
    [data-driver-order-details-dialog] .order-print-footer {
        display: flex !important;
        gap: 6px;
        justify-content: flex-end;
        border-top: 1px solid #d0d6d3;
        padding-top: 8px;
        margin-top: 8px;
        font-size: 10pt;
    }
}
</style>
<dialog id="driver-orders-dialog-{{ $channel }}" data-driver-orders-dialog style="width:min(980px,94vw);max-height:88vh;border:0;border-radius:16px;padding:0;box-shadow:0 24px 60px rgba(15,23,42,.22)">
    <div style="padding:14px 16px;border-bottom:1px solid var(--foodex-border);display:flex;justify-content:space-between;align-items:center;gap:12px">
        <strong>{{ app()->getLocale()==='ar'?'طلبات السائق':'Driver orders' }}</strong>
        <button type="button" class="btn foodex-driver-premium-btn foodex-driver-premium-secondary" data-driver-orders-close aria-label="{{ app()->getLocale()==='ar'?'إغلاق':'Close' }}">×</button>
    </div>
    <div data-driver-orders-body style="padding:16px;max-height:calc(88vh - 64px);overflow:auto"></div>
</dialog>
<dialog id="driver-order-details-dialog-{{ $channel }}" data-driver-order-details-dialog aria-label="{{ __('order_operations.popup.details') }}"
        dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
    <div data-driver-order-details-head>
        <strong>{{ __('order_operations.popup.details') }}</strong>
        <div style="display:flex;align-items:center;gap:10px">
            <a data-driver-order-full class="foodex-action-secondary" href="{{ route('admin.operations.orders.index') }}" target="_blank" rel="noopener">{{ app()->getLocale()==='ar' ? 'فتح صفحة الطلب' : 'Open order page' }}</a>
            <button type="button" class="btn foodex-driver-premium-secondary" data-driver-order-details-close aria-label="{{ app()->getLocale()==='ar' ? 'إغلاق' : 'Close' }}">×</button>
        </div>
    </div>
    <div data-driver-order-details-body></div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById(@json('driver-orders-dialog-'.$channel));
    if (!dialog || dialog.dataset.ready === '1') return;
    dialog.dataset.ready = '1';
    const body = dialog.querySelector('[data-driver-orders-body]');
    const detailsDialog = document.getElementById(@json('driver-order-details-dialog-'.$channel));
    const detailsBody = detailsDialog?.querySelector('[data-driver-order-details-body]');
    const detailsLink = detailsDialog?.querySelector('[data-driver-order-full]');

    const loadingText = @json(app()->getLocale()==='ar'?'جاري تحميل الطلبات...':'Loading driver orders...');
    const failedText = @json(app()->getLocale()==='ar'?'تعذر تحميل طلبات السائق.':'Unable to load driver orders.');

    const load = async (url) => {
        if (!url) return;
        if (!dialog.open && typeof dialog.showModal === 'function') dialog.showModal();
        body.textContent = loadingText;
        try {
            const response = await fetch(url, {
                headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('http_' + response.status);
            body.innerHTML = await response.text();
        } catch (_) {
            body.textContent = failedText;
        }
    };

    const showOrderDetails = async (target) => {
        if (!detailsDialog || !detailsBody || !target) return;
        detailsLink.href = target.dataset.fullUrl;
        detailsBody.textContent = loadingText;
        if (!detailsDialog.open && typeof detailsDialog.showModal === 'function') {
            detailsDialog.showModal();
        }
        try {
            const response = await fetch(target.href, {
                headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('http_' + response.status);
            detailsBody.innerHTML = await response.text();
        } catch (_) {
            detailsBody.textContent = failedText;
        }
    };

    detailsDialog?.addEventListener('click', (event) => {
        if (event.target.closest('[data-order-popup-print]')) {
            event.preventDefault();
            const locale = document.documentElement.lang || 'en';
            const printedAt = new Intl.DateTimeFormat(locale, {
                dateStyle: 'full',
                timeStyle: 'medium',
                timeZone: 'Asia/Kuwait',
            }).format(new Date());
            detailsBody?.querySelectorAll('[data-order-print-time]').forEach((node) => {
                node.textContent = printedAt;
            });
            window.print();
            return;
        }
        if (event.target.closest('[data-driver-order-details-close]')) {
            detailsDialog.close();
        }
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-driver-orders-open]');
        if (!trigger) return;
        event.preventDefault();
        load(trigger.dataset.url);
    });

    dialog.addEventListener('click', (event) => {
        const orderLink = event.target.closest('[data-driver-order-detail]');
        if (orderLink) {
            event.preventDefault();
            showOrderDetails(orderLink);
            return;
        }
        if (event.target.closest('[data-driver-orders-close]')) {
            dialog.close();
            return;
        }
        const pageLink = event.target.closest('.pagination a');
        if (pageLink) {
            event.preventDefault();
            load(pageLink.href);
        }
    });

    dialog.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-driver-orders-filter]');
        if (!form) return;
        event.preventDefault();
        const params = new URLSearchParams(new FormData(form));
        load(form.action + '?' + params.toString());
    });
})();
</script>
