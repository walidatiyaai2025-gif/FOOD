@php
    $isB2bOrder = $channel === 'b2b';
    $statusRoute = $isB2bOrder ? 'admin.b2b.orders.status' : 'admin.b2c.orders.status';
    $updateRoute = $isB2bOrder ? 'admin.b2b.orders.update' : 'admin.b2c.orders.update';
    $driverRoute = $isB2bOrder ? 'admin.b2b.drivers.assign' : 'admin.b2c.drivers.assign';
    $statusTransitions = match ($row['status']) {
        'pending' => ['confirmed','cancelled'],
        'confirmed' => ['preparing','cancelled'],
        'preparing' => ['ready','cancelled'],
        'ready' => ['out_for_delivery','cancelled'],
        'out_for_delivery' => ['delivered','failed'],
        'failed' => ['out_for_delivery','cancelled'],
        default => [],
    };
@endphp

<div style="display:grid;gap:8px;min-width:260px">
    @if(count($statusTransitions))
    <form method="post" action="{{ route($statusRoute,['order'=>$row['_id']]) }}" class="links module-inline-form" style="margin:0;padding:0;border:0;background:transparent">
        @csrf
        @if(!$isB2bOrder)
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            @if($supportAccess ?? false)<input type="hidden" name="support_access" value="1">@endif
        @endif
        <select name="status" required>
            @foreach($statusTransitions as $state)
                <option value="{{ $state }}">{{ $state }}</option>
            @endforeach
        </select>
        <input name="note" maxlength="1000" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة الحالة':'Status note' }}">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تحديث الحالة':'Update status' }}</button>
    </form>
    @endif

    @if(!in_array($row['status'],['delivered','cancelled'],true) && !empty($moduleData['drivers']))
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
                @if($driver['store_id']===$row['_store_id'])
                    <option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>
                @endif
            @endforeach
        </select>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين':'Assign' }}</button>
    </form>
    @endif

    <details>
        <summary style="cursor:pointer">{{ app()->getLocale()==='ar'?'التفاصيل والإدارة':'Details & management' }}</summary>
        <div style="display:grid;gap:8px;margin-top:8px">
            <div>
                <strong>{{ app()->getLocale()==='ar'?'البنود':'Items' }}</strong>
                <ul style="margin:6px 0">
                    @foreach($row['_items'] as $item)
                        <li>{{ $item['sku'] }} · {{ $item['name'] }} — {{ number_format($item['quantity'],3) }} × {{ number_format($item['unit_price'],3) }} = {{ number_format($item['line_total'],3) }} KWD</li>
                    @endforeach
                </ul>
            </div>
            <div>
                {{ app()->getLocale()==='ar'?'الإجمالي الفرعي':'Subtotal' }}: {{ number_format($row['_subtotal'],3) }} KWD ·
                {{ app()->getLocale()==='ar'?'الخصم':'Discount' }}: {{ number_format($row['_discount_total'],3) }} KWD ·
                {{ app()->getLocale()==='ar'?'التوصيل':'Delivery' }}: {{ number_format($row['_delivery_total'],3) }} KWD
            </div>
            @if($row['_payment'])
                <div>{{ app()->getLocale()==='ar'?'الدفع':'Payment' }}: {{ $row['_payment']['provider'] }} · {{ $row['_payment']['status'] }} · {{ number_format($row['_payment']['amount'],3) }} {{ $row['_payment']['currency'] }}</div>
            @endif
            @if($row['_invoice'])
                <div>{{ app()->getLocale()==='ar'?'الفاتورة':'Invoice' }}: {{ $row['_invoice']['number'] }} · {{ $row['_invoice']['status'] }} · {{ number_format($row['_invoice']['total'],3) }} {{ $row['_invoice']['currency'] }}</div>
            @endif
            @if($row['_customer_note'])
                <div>{{ app()->getLocale()==='ar'?'ملاحظة العميل':'Customer note' }}: {{ $row['_customer_note'] }}</div>
            @endif
            @if(count($row['_history']))
                <div>
                    <strong>{{ app()->getLocale()==='ar'?'سجل الحالات':'Status history' }}</strong>
                    <ul style="margin:6px 0">
                        @foreach($row['_history'] as $entry)
                            <li>{{ $entry['from'] ?? '—' }} → {{ $entry['to'] }} · {{ $entry['created_at'] }}@if($entry['note']) · {{ $entry['note'] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($row['status']==='pending')
                <form method="post" action="{{ route($updateRoute,['order'=>$row['_id']]) }}" class="workspace-inline-form module-inline-form js-dashboard-order-form" style="margin-top:8px">
                    @csrf
                    @method('patch')
                    @if($isB2bOrder)
                        <input type="hidden" name="store_id" value="{{ $row['_store_id'] }}">
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
                            <option value="{{ $method }}" @selected($row['_payment_method']===$method)>{{ $method }}</option>
                        @endforeach
                    </select>

                    <input name="discount_total" type="number" min="0" step="0.001" value="{{ number_format($row['_discount_total'],3,'.','') }}" placeholder="{{ app()->getLocale()==='ar'?'الخصم':'Discount' }}">
                    <input name="delivery_total" type="number" min="0" step="0.001" value="{{ number_format($row['_delivery_total'],3,'.','') }}" placeholder="{{ app()->getLocale()==='ar'?'التوصيل':'Delivery' }}">
                    <input name="customer_note" maxlength="1000" value="{{ $row['_customer_note'] }}" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة الطلب':'Order note' }}">

                    <div class="js-order-lines" style="display:grid;gap:8px;flex:1 1 100%">
                        @foreach($row['_items'] as $index=>$currentItem)
                            <div class="js-order-line" style="display:flex;gap:8px;flex-wrap:wrap">
                                <select name="items[{{ $index }}][product_id]" class="js-order-product" required style="min-width:260px">
                                    <option value="">{{ app()->getLocale()==='ar'?'اختر المنتج':'Select product' }}</option>
                                    @foreach($moduleData['products'] as $product)
                                        <option value="{{ $product['id'] }}" data-store-id="{{ $product['store_id'] }}" @selected($currentItem['product_id']===$product['id'])>
                                            {{ $product['sku'] }} · {{ $product['name'] }}
                            @if(array_key_exists('price',$product))
                                · {{ number_format((float)$product['price'],3) }} KWD
                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                <input name="items[{{ $index }}][quantity]" type="number" min="0.001" step="0.001" value="{{ number_format($currentItem['quantity'],3,'.','') }}" required>
                                <button type="button" class="js-remove-order-line">{{ app()->getLocale()==='ar'?'حذف':'Remove' }}</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="js-add-order-line">{{ app()->getLocale()==='ar'?'إضافة صنف':'Add item' }}</button>
                    <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ تعديل الطلب':'Save order changes' }}</button>
                </form>
            @endif
        </div>
    </details>
</div>
