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


<dialog id="driver-orders-dialog-{{ $channel }}" data-driver-orders-dialog style="width:min(980px,94vw);max-height:88vh;border:0;border-radius:16px;padding:0;box-shadow:0 24px 60px rgba(15,23,42,.22)">
    <div style="padding:14px 16px;border-bottom:1px solid var(--foodex-border);display:flex;justify-content:space-between;align-items:center;gap:12px">
        <strong>{{ app()->getLocale()==='ar'?'طلبات السائق':'Driver orders' }}</strong>
        <button type="button" class="btn foodex-driver-premium-btn foodex-driver-premium-secondary" data-driver-orders-close aria-label="{{ app()->getLocale()==='ar'?'إغلاق':'Close' }}">×</button>
    </div>
    <div data-driver-orders-body style="padding:16px;max-height:calc(88vh - 64px);overflow:auto"></div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById(@json('driver-orders-dialog-'.$channel));
    if (!dialog || dialog.dataset.ready === '1') return;
    dialog.dataset.ready = '1';
    const body = dialog.querySelector('[data-driver-orders-body]');
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

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-driver-orders-open]');
        if (!trigger) return;
        event.preventDefault();
        load(trigger.dataset.url);
    });

    dialog.addEventListener('click', (event) => {
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
