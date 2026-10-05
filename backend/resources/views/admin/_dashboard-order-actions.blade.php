@php
    $isB2bOrder = $channel === 'b2b';
    $statusRoute = $isB2bOrder ? 'admin.b2b.orders.status' : 'admin.b2c.orders.status';
    $updateRoute = $isB2bOrder ? 'admin.b2b.orders.update' : 'admin.b2c.orders.update';
    $quoteRoute = $isB2bOrder ? 'admin.b2b.orders.quote' : 'admin.b2c.orders.quote';
    $driverRoute = $isB2bOrder ? 'admin.b2b.drivers.assign' : 'admin.b2c.drivers.assign';
    $driverReassignRoute = $isB2bOrder ? 'admin.b2b.orders.driver.reassign' : 'admin.b2c.orders.driver.reassign';
    $driverUnassignRoute = $isB2bOrder ? 'admin.b2b.orders.driver.unassign' : 'admin.b2c.orders.driver.unassign';
    $statusTransitions = match ($row['status']) {
        'pending' => ['confirmed','cancelled'],
        'confirmed' => ['preparing','cancelled'],
        'preparing' => ['ready','cancelled'],
        'ready' => ['out_for_delivery','cancelled'],
        'out_for_delivery' => ['delivered','failed'],
        'failed' => ['out_for_delivery','cancelled'],
        default => [],
    };
    $isArAction = app()->getLocale()==='ar';
    $stateLabels = [
        'pending'=>$isArAction?'قيد الانتظار':'Pending',
        'confirmed'=>$isArAction?'مؤكد':'Confirmed',
        'preparing'=>$isArAction?'قيد التجهيز':'Preparing',
        'ready'=>$isArAction?'جاهز':'Ready',
        'assigned'=>$isArAction?'تم التعيين':'Assigned',
        'picked_up'=>$isArAction?'تم الاستلام':'Picked up',
        'out_for_delivery'=>$isArAction?'قيد التوصيل':'Out for delivery',
        'in_transit'=>$isArAction?'في الطريق':'In transit',
        'delivered'=>$isArAction?'تم التسليم':'Delivered',
        'completed'=>$isArAction?'مكتمل':'Completed',
        'failed'=>$isArAction?'تعذر التسليم':'Failed',
        'cancelled'=>$isArAction?'ملغي':'Cancelled',
        'refunded'=>$isArAction?'مسترد':'Refunded',
        'unassigned'=>$isArAction?'غير معين':'Unassigned',
    ];
    $paymentLabels = [
        'cash_on_delivery'=>$isArAction?'الدفع عند الاستلام':'Cash on delivery',
        'cash'=>$isArAction?'نقدي':'Cash',
        'card'=>$isArAction?'بطاقة':'Card',
        'credit'=>$isArAction?'آجل / ائتمان':'Credit',
        'account_credit'=>$isArAction?'رصيد الحساب':'Account credit',
        'bank_transfer'=>$isArAction?'تحويل بنكي':'Bank transfer',
        'paid'=>$isArAction?'مدفوع':'Paid',
        'pending'=>$isArAction?'قيد الانتظار':'Pending',
        'failed'=>$isArAction?'فشل':'Failed',
        'refunded'=>$isArAction?'مسترد':'Refunded',
    ];
@endphp

<div id="order-{{ $row['_id'] }}" style="display:grid;gap:8px;min-width:260px">
    @if($isB2bOrder && $row['status']==='pending')
    <div class="workspace-inline-form" style="margin:0;display:grid;gap:10px;border-color:#f4c27a;background:#fffaf2">
        <strong>{{ app()->getLocale()==='ar'?'قرار خدمة العملاء':'Customer Service approval' }}</strong>
        <div class="muted">{{ app()->getLocale()==='ar'?'راجع تفاصيل الطلب والتسوية المالية قبل الاعتماد أو الرفض.':'Review order and settlement details before approving or rejecting.' }}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" action="{{ route($statusRoute,['order'=>$row['_id']]) }}" style="margin:0">
                @csrf
                <input type="hidden" name="status" value="confirmed">
                <input type="hidden" name="note" value="customer_service_approved">
                <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'اعتماد الطلب':'Approve order' }}</button>
            </form>
            <form method="post" action="{{ route($statusRoute,['order'=>$row['_id']]) }}" style="margin:0;display:flex;gap:8px;flex-wrap:wrap">
                @csrf
                <input type="hidden" name="status" value="cancelled">
                <input name="note" maxlength="1000" required placeholder="{{ app()->getLocale()==='ar'?'سبب الرفض (إلزامي)':'Rejection reason (required)' }}">
                <button class="danger btn" type="submit">{{ app()->getLocale()==='ar'?'رفض الطلب':'Reject order' }}</button>
            </form>
        </div>
    </div>
    @elseif(count($statusTransitions))
    <form method="post" action="{{ route($statusRoute,['order'=>$row['_id']]) }}" class="links module-inline-form" style="margin:0;padding:0;border:0;background:transparent">
        @csrf
        @if(!$isB2bOrder)
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
        @endif
        <select name="status" required>
            @foreach($statusTransitions as $state)
                <option value="{{ $state }}">{{ $stateLabels[$state] ?? $state }}</option>
            @endforeach
        </select>
        <input name="note" maxlength="1000" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة الحالة':'Status note' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تحديث الحالة':'Update status' }}</button>
    </form>
    @endif

    @if((!$isB2bOrder || $row['status']!=='pending') && !in_array($row['status'],['delivered','cancelled'],true) && !empty($moduleData['drivers']))
        @if(empty($row['_assignment_id']))
        <form method="post" action="{{ route($driverRoute) }}" class="links module-inline-form" style="margin:0;padding:0;border:0;background:transparent">
            @csrf
            <input type="hidden" name="order_id" value="{{ $row['_id'] }}">
            @if(!$isB2bOrder)
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
            @endif
            <select name="driver_id" required>
                <option value="">{{ app()->getLocale()==='ar'?'تعيين سائق':'Assign driver' }}</option>
                @foreach($moduleData['drivers'] as $driver)
                    @if($isB2bOrder || $driver['store_id']===$row['_store_id'])
                        <option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>
                    @endif
                @endforeach
            </select>
            <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين':'Assign' }}</button>
        </form>
        @else
        <div style="display:grid;gap:7px;padding:9px;border:1px solid var(--foodex-border);border-radius:10px;background:#fbfcfd">
            <strong>{{ app()->getLocale()==='ar'?'السائق الحالي':'Current driver' }}: {{ $row['driver'] }} · {{ $stateLabels[$row['assignment_status']] ?? $row['assignment_status'] }}</strong>
            <form method="post" action="{{ route($driverReassignRoute,['order'=>$row['_id']]) }}" class="links module-inline-form" style="margin:0;padding:0;border:0;background:transparent">
                @csrf @method('PATCH')
                @if(!$isB2bOrder)
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
                @endif
                <select name="driver_id" required>
                    <option value="">{{ app()->getLocale()==='ar'?'إعادة تعيين إلى سائق':'Reassign to driver' }}</option>
                    @foreach($moduleData['drivers'] as $driver)
                        @if(($isB2bOrder || $driver['store_id']===$row['_store_id']) && $driver['id']!==($row['_driver_id'] ?? null))
                            <option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>
                        @endif
                    @endforeach
                </select>
                <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'إعادة تعيين':'Reassign' }}</button>
            </form>
            <form method="post" action="{{ route($driverUnassignRoute,['order'=>$row['_id']]) }}" style="margin:0" onsubmit="return confirm('{{ app()->getLocale()==='ar'?'سحب الطلب من السائق الحالي؟':'Remove this order from the current driver?' }}')">
                @csrf @method('DELETE')
                @if(!$isB2bOrder)
                    <input type="hidden" name="store_id" value="{{ $storeId }}">
                    @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
                @endif
                <input type="hidden" name="reason" value="manual_unassign">
                <button class="danger btn" type="submit">{{ app()->getLocale()==='ar'?'سحب الطلب من السائق':'Unassign driver' }}</button>
            </form>
        </div>
        @endif
    @endif

    <details>
        <summary style="cursor:pointer">{{ app()->getLocale()==='ar'?'التفاصيل والإدارة':'Details & management' }}</summary>
        <div style="display:grid;gap:8px;margin-top:8px">
            <div>
                <strong>{{ app()->getLocale()==='ar'?'البنود':'Items' }}</strong>
                <ul style="margin:6px 0">
                    @foreach($row['_items'] as $item)
                        <li>{{ $item['sku'] }} · {{ $item['name'] }} — {{ number_format($item['quantity'],3) }} × {{ number_format($item['unit_price'],3) }} = {{ number_format($item['line_total'],3) }} {{ $row['_currency'] }}</li>
                    @endforeach
                </ul>
            </div>
            <div>
                {{ app()->getLocale()==='ar'?'الإجمالي الفرعي':'Subtotal' }}: {{ number_format($row['_subtotal'],3) }} {{ $row['_currency'] }} ·
                {{ app()->getLocale()==='ar'?'الخصم':'Discount' }}: {{ number_format($row['_discount_total'],3) }} {{ $row['_currency'] }} ·
                {{ app()->getLocale()==='ar'?'التوصيل':'Delivery' }}: {{ number_format($row['_delivery_total'],3) }} {{ $row['_currency'] }} ·
                {{ app()->getLocale()==='ar'?'الضريبة':'Tax' }}: {{ number_format($row['_tax_total'] ?? 0,3) }} {{ $row['_currency'] }} ·
                <strong>{{ app()->getLocale()==='ar'?'الإجمالي النهائي':'Grand total' }}: {{ number_format($row['_grand_total'],3) }} {{ $row['_currency'] }}</strong>
            </div>
            @if($isB2bOrder && !empty($row['_settlement']))
                @php($settlement = $row['_settlement'])
                <div style="display:grid;gap:6px;padding:10px;border:1px solid var(--foodex-border);border-radius:10px;background:#fbfcfd">
                    <strong>{{ app()->getLocale()==='ar'?'مراجعة التسوية المالية':'Financial settlement review' }}</strong>
                    <div>
                        {{ app()->getLocale()==='ar'?'إجمالي الطلب':'Order total' }}:
                        <strong>{{ number_format($row['_grand_total'],3) }} {{ $settlement['currency'] }}</strong>
                        · {{ app()->getLocale()==='ar'?'طريقة المتبقي':'Remainder method' }}:
                        <strong>{{ $settlement['remainder_method'] ? ($paymentLabels[$settlement['remainder_method']] ?? str_replace('_',' ',$settlement['remainder_method'])) : '—' }}</strong>
                    </div>
                    <div>
                        {{ app()->getLocale()==='ar'?'رصيد العميل المتاح':'Customer credit balance' }}:
                        <strong>{{ $settlement['customer_credit_balance']===null?'—':number_format($settlement['customer_credit_balance'],3) }}</strong>
                        · {{ app()->getLocale()==='ar'?'إجمالي المديونية الحالية':'Aggregate outstanding' }}:
                        <strong>{{ $settlement['aggregate_outstanding']===null?'—':number_format($settlement['aggregate_outstanding'],3) }}</strong>
                    </div>
                    <div>
                        {{ app()->getLocale()==='ar'?'حد الائتمان':'Credit limit' }}:
                        <strong>{{ $settlement['credit_limit']===null?'—':number_format($settlement['credit_limit'],3) }}</strong>
                        · {{ app()->getLocale()==='ar'?'الحد المتاح':'Available credit line' }}:
                        <strong>{{ $settlement['available_credit_line']===null?'—':number_format($settlement['available_credit_line'],3) }}</strong>
                    </div>
                    @if($settlement['balance_applied']!==null)
                    <div>
                        {{ app()->getLocale()==='ar'?'المخصوم من الرصيد':'Balance applied' }}:
                        <strong>{{ number_format($settlement['balance_applied'],3) }}</strong>
                        @if($settlement['remaining_after_balance']!==null)
                            · {{ app()->getLocale()==='ar'?'المتبقي بعد الرصيد':'Remaining after balance' }}:
                            <strong>{{ number_format($settlement['remaining_after_balance'],3) }}</strong>
                        @endif
                    </div>
                    @endif
                    <div>
                        {{ app()->getLocale()==='ar'?'المتبقي على الفاتورة':'Invoice outstanding' }}:
                        <strong>{{ $settlement['invoice_outstanding']===null?(app()->getLocale()==='ar'?'لم تصدر بعد':'Not issued yet'):number_format($settlement['invoice_outstanding'],3) }}</strong>
                    </div>
                </div>
            @endif
            @if($row['_payment'])
                <div>{{ app()->getLocale()==='ar'?'الدفع':'Payment' }}: {{ $paymentLabels[$row['_payment']['provider']] ?? str_replace('_',' ',$row['_payment']['provider']) }} · {{ $paymentLabels[$row['_payment']['status']] ?? $row['_payment']['status'] }} · {{ number_format($row['_payment']['amount'],3) }} {{ $row['_payment']['currency'] }}</div>
            @endif
            @if($row['_invoice'])
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <span>{{ app()->getLocale()==='ar'?'الفاتورة':'Invoice' }}: {{ $row['_invoice']['number'] }} · {{ $stateLabels[$row['_invoice']['status']] ?? $row['_invoice']['status'] }} · {{ number_format($row['_invoice']['total'],3) }} {{ $row['_invoice']['currency'] }}</span>
                    <a class="foodex-primary" target="_blank" rel="noopener" href="{{ route('admin.invoices.show',['invoice'=>$row['_invoice']['id']]) }}">{{ app()->getLocale()==='ar'?'عرض':'View' }}</a>
                    <a class="foodex-primary" href="{{ route('admin.invoices.download',['invoice'=>$row['_invoice']['id'],'locale'=>app()->getLocale()]) }}">PDF</a>
                    <a class="foodex-primary" target="_blank" rel="noopener" href="{{ route('admin.invoices.show',['invoice'=>$row['_invoice']['id'],'print'=>1]) }}">{{ app()->getLocale()==='ar'?'طباعة':'Print' }}</a>
                </div>
            @endif
            @if($row['_customer_note'])
                <div>{{ app()->getLocale()==='ar'?'ملاحظة العميل':'Customer note' }}: {{ $row['_customer_note'] }}</div>
            @endif
            @if(count($row['_history']))
                <div>
                    <strong>{{ app()->getLocale()==='ar'?'سجل الحالات':'Status history' }}</strong>
                    <ul style="margin:6px 0">
                        @foreach($row['_history'] as $entry)
                            <li>{{ $entry['from'] ? ($stateLabels[$entry['from']] ?? $entry['from']) : '—' }} → {{ $stateLabels[$entry['to']] ?? $entry['to'] }} · {{ $entry['created_at'] }}@if($entry['note']) · {{ $entry['note'] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(!empty($row['_driver_history']))
                <div>
                    <strong>{{ app()->getLocale()==='ar'?'سجل السائق والملاحظات':'Driver timeline & notes' }}</strong>
                    <ul style="margin:6px 0">
                        @foreach($row['_driver_history'] as $entry)
                            <li>
                                {{ $entry['from'] ? ($stateLabels[$entry['from']] ?? $entry['from']) : '—' }} → {{ $entry['to'] ? ($stateLabels[$entry['to']] ?? $entry['to']) : '—' }}
                                @if($entry['actor']) · {{ $entry['actor'] }}@endif
                                @if($entry['created_at']) · {{ $entry['created_at'] }}@endif
                                @if($entry['note']) · <strong>{{ $entry['note'] }}</strong>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($row['status']==='pending')
                <form method="post"
                      action="{{ route($updateRoute,['order'=>$row['_id']]) }}"
                      class="workspace-inline-form module-inline-form js-dashboard-order-form"
                      style="margin-top:8px"
                      data-order-channel="{{ $channel }}"
                      data-order-quote-url="{{ route($quoteRoute) }}">
                    @csrf
                    @method('patch')
                    @if($isB2bOrder)
                        <select name="warehouse_id" class="js-order-warehouse" required>
                            <option value="">{{ app()->getLocale()==='ar'?'اختر مخزن الصرف':'Select source warehouse' }}</option>
                            @foreach($moduleData['warehouses'] as $warehouse)
                                <option value="{{ $warehouse['id'] }}" @selected($row['_warehouse_id']===$warehouse['id'])>{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="hidden" name="store_id" value="{{ $storeId }}">
                        @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
                    @endif

                    <select name="customer_id" class="js-order-customer" required>
                        @foreach($moduleData['customers'] as $customer)
                            <option value="{{ $customer['id'] }}" @selected($row['_customer_id']===$customer['id'])>{{ $customer['name'] }}</option>
                        @endforeach
                    </select>

                    <select name="address_id" class="js-order-address">
                        <option value="">{{ app()->getLocale()==='ar'?'بدون عنوان / استلام':'No address / pickup' }}</option>
                        @foreach($moduleData['addresses'] as $address)
                            <option value="{{ $address['id'] }}" data-customer-id="{{ $address['customer_id'] }}" @selected($row['_address_id']===$address['id'])>{{ $address['label'] }}</option>
                        @endforeach
                    </select>

                    <select name="payment_method" required>
                        @foreach($moduleData['payment_methods'] as $method)
                            <option value="{{ $method }}" @selected($row['_payment_method']===$method)>{{ $paymentLabels[$method] ?? str_replace('_',' ',$method) }}</option>
                        @endforeach
                    </select>

                    <input name="coupon_code" maxlength="80" autocomplete="off" value="{{ $row['_coupon_code'] ?? '' }}" placeholder="{{ app()->getLocale()==='ar'?'كود كوبون اختياري':'Optional coupon code' }}">
                    <input name="customer_note" maxlength="1000" value="{{ $row['_customer_note'] }}" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة الطلب':'Order note' }}">

                    <div class="js-order-lines" style="display:grid;gap:8px;flex:1 1 100%">
                        @foreach($row['_items'] as $index=>$currentItem)
                            <div class="js-order-line" style="display:flex;gap:8px;flex-wrap:wrap">
                                <select name="items[{{ $index }}][product_id]" class="js-order-product" required style="min-width:260px">
                                    <option value="">{{ app()->getLocale()==='ar'?'اختر المنتج':'Select product' }}</option>
                                    @foreach($moduleData['products'] as $product)
                                        <option value="{{ $product['id'] }}"
                                            @if($isB2bOrder)
                                                data-warehouse-ids="{{ implode(',', $product['warehouse_ids'] ?? []) }}"
                                            @else
                                                data-store-id="{{ $product['store_id'] }}"
                                            @endif
                                            @selected($currentItem['product_id']===$product['id'])
                                        >
                                            {{ $product['sku'] }} · {{ $product['name'] }}
                                            @if(array_key_exists('price',$product))
                                                · {{ number_format((float)$product['price'],3) }} {{ $row['_currency'] }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                <input name="items[{{ $index }}][quantity]" type="number" min="0.001" step="0.001" value="{{ number_format($currentItem['quantity'],3,'.','') }}" placeholder="1.000" required>
                                <button type="button" class="js-remove-order-line">{{ app()->getLocale()==='ar'?'حذف':'Remove' }}</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="js-order-quote" role="status" aria-live="polite" style="flex:1 1 100%;padding:12px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface)">
                        {{ app()->getLocale()==='ar'?'جاري تجهيز إعادة التسعير المعتمدة…':'Preparing authoritative reprice…' }}
                    </div>
                    <button type="button" class="js-add-order-line">{{ app()->getLocale()==='ar'?'إضافة صنف':'Add item' }}</button>
                    <button class="foodex-primary js-submit-order" type="submit" disabled>{{ app()->getLocale()==='ar'?'حفظ تعديل الطلب':'Save order changes' }}</button>
                </form>
            @endif
        </div>
    </details>
</div>
