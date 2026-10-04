<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $customer->name }} · {{ app()->getLocale()==='ar'?'العميل 360':'Customer 360' }} · FOODEX</title>
@include('admin._brand-components')
<style>
.c360-shell{display:grid;gap:var(--foodex-space-5)}.c360-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.c360-card{padding:var(--foodex-space-5)}
.c360-card h2,.c360-card h3{margin:0}.c360-card p{color:var(--foodex-muted)}.c360-stat{display:grid;gap:4px}.c360-stat strong{font-size:24px}.c360-stat small{color:var(--foodex-muted)}
.c360-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--foodex-border);border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:#fff}.c360-badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:transparent}.c360-badge.b2b{background:#fff7ed;color:#9a3412}.c360-badge.b2c{background:#eefbf4;color:#166534}
.c360-info{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.c360-info>div{padding:14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:#fbfcfd}.c360-info small{display:block;color:var(--foodex-muted);margin-bottom:4px}.c360-info strong{word-break:break-word}
.c360-table{width:100%;border-collapse:collapse}.c360-table th,.c360-table td{padding:12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.c360-table th{font-size:12px;color:var(--foodex-muted);background:#f8fafc}.c360-table tr:hover td{background:#fbfefc}
.c360-list{display:flex;gap:8px;flex-wrap:wrap}.c360-address-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}.c360-address-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.c360-address-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.c360-address-form .wide{grid-column:1/-1}.c360-address-form input,.c360-address-form textarea{width:100%}.c360-store{padding:9px 12px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;font-weight:800}.c360-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:1050px){.c360-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:720px){.c360-grid{grid-template-columns:1fr}.c360-table thead{display:none}.c360-table,.c360-table tbody,.c360-table tr,.c360-table td{display:block;width:100%}.c360-table tr{padding:10px 0;border-bottom:1px solid var(--foodex-border)}.c360-table td{border:0;padding:7px 0}.c360-table td:before{content:attr(data-label);display:block;font-size:11px;font-weight:800;color:var(--foodex-muted);margin-bottom:2px}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · CUSTOMER 360 · #{{ $customer->id }}</span>
                <h1>{{ $customer->name }}</h1>
                <p>{{ $ar?'عرض موحد للهوية والتعاملات داخل النطاق المصرح لك فقط.':'Unified identity and commerce view restricted to your authorized scope.' }}</p>
            </div>
            <div class="c360-actions">
                <a class="foodex-action-primary" href="#addresses">{{ $ar?'إدارة العناوين':'Manage addresses' }}</a>
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.index') }}">← {{ $ar?'العودة للعملاء':'Back to customers' }}</a>
                @include('admin._live-notifications',['user'=>auth()->user()])
            </div>
        </header>

        <div class="c360-shell">
            <section class="c360-grid">
                <article class="foodex-card c360-card c360-stat">
                    <small>{{ $ar?'الحالة':'Status' }}</small>
                    <strong>{{ $summary['active']?($ar?'نشط':'Active'):($ar?'غير نشط':'Inactive') }}</strong>
                    <span class="c360-badge {{ $summary['active']?'active':'' }}">{{ $summary['registration_source'] }}</span>
                </article>
                <article class="foodex-card c360-card c360-stat">
                    <small>{{ $ar?'الطلبات':'Orders' }}</small>
                    <strong>{{ number_format($summary['orders_count']) }}</strong>
                    <span>{{ number_format($summary['orders_total'],3) }} EGP</span>
                </article>
                <article class="foodex-card c360-card c360-stat">
                    <small>{{ $ar?'الفواتير':'Invoices' }}</small>
                    <strong>{{ number_format($summary['invoices_count']) }}</strong>
                    <span>{{ number_format($summary['invoices_total'],3) }} EGP</span>
                </article>
                <article class="foodex-card c360-card c360-stat">
                    <small>{{ $ar?'مصدر التسجيل':'Registration origin' }}</small>
                    <strong style="font-size:16px">{{ $summary['origin']['label'] }}</strong>
                    <span class="c360-badge {{ $summary['origin']['channel'] }}">{{ strtoupper($summary['origin']['channel']) }}</span>
                </article>
            </section>

            <section class="foodex-card c360-card">
                <h2>{{ $ar?'هوية العميل':'Customer identity' }}</h2>
                <div class="c360-info" style="margin-top:16px">
                    <div><small>{{ $ar?'الاسم':'Name' }}</small><strong>{{ $customer->name }}</strong></div>
                    <div><small>{{ $ar?'البريد الإلكتروني':'Email' }}</small><strong>{{ $customer->email }}</strong></div>
                    <div><small>{{ $ar?'الهاتف':'Phone' }}</small><strong>{{ $customer->phone ?: '-' }}</strong></div>
                    <div><small>{{ $ar?'تاريخ التسجيل':'Registered at' }}</small><strong>{{ optional($customer->registered_at)->format('Y-m-d H:i') ?: '-' }}</strong></div>
                    <div><small>{{ $ar?'قناة التسجيل الأصلية':'Immutable origin channel' }}</small><strong>{{ strtoupper($customer->origin_channel ?: 'unknown') }}</strong></div>
                    <div><small>{{ $ar?'مصدر التسجيل':'Registration source' }}</small><strong>{{ $customer->registration_source }}</strong></div>
                </div>
            </section>

            <section class="foodex-card c360-card" id="addresses">
                <div class="c360-actions" style="justify-content:space-between;align-items:center">
                    <div>
                        <h2>{{ $ar?'عناوين العميل':'Customer addresses' }}</h2>
                        <p>{{ $ar?'إدارة العناوين المحفوظة للعميل. تعديل العنوان لا يغير عناوين الطلبات السابقة.':'Manage the customer saved addresses. Changes never rewrite historical order delivery snapshots.' }}</p>
                    </div>
                    <span class="c360-badge">{{ count($addresses) }} {{ $ar?'عنوان':'addresses' }}</span>
                </div>

                @if(session('status'))
                    <div class="foodex-success" style="margin-top:12px">{{ session('status') }}</div>
                @endif

                @if($errors->any())
                    <div class="foodex-error" style="margin-top:12px">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if($canManageAddresses)
                <details style="margin-top:16px">
                    <summary class="foodex-action-primary" style="display:inline-flex;cursor:pointer">{{ $ar?'إضافة عنوان جديد':'Add new address' }}</summary>
                    <form method="post" action="{{ route('admin.customer-360.addresses.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:14px">
                        @csrf
                        <label><small>{{ $ar?'اسم العنوان':'Label' }}</small><input name="label" maxlength="100" placeholder="{{ $ar?'المنزل / العمل':'Home / Work' }}"></label>
                        <label><small>{{ $ar?'اسم المستلم':'Recipient' }}</small><input name="recipient_name" maxlength="255"></label>
                        <label><small>{{ $ar?'هاتف التوصيل':'Delivery phone' }}</small><input name="delivery_phone" maxlength="50"></label>
                        <label><small>{{ $ar?'المدينة':'City' }}</small><input name="city" maxlength="120" required></label>
                        <label class="wide"><small>{{ $ar?'العنوان':'Address' }}</small><input name="line1" maxlength="255" required></label>
                        <label><small>{{ $ar?'المنطقة':'Area' }}</small><input name="area" maxlength="120"></label>
                        <label><small>{{ $ar?'المحافظة':'Governorate' }}</small><input name="governorate" maxlength="120"></label>
                        <label><small>{{ $ar?'البلوك':'Block' }}</small><input name="block" maxlength="120"></label>
                        <label><small>{{ $ar?'المبنى':'Building' }}</small><input name="building" maxlength="120"></label>
                        <label><small>{{ $ar?'الدور':'Floor' }}</small><input name="floor" maxlength="120"></label>
                        <label><small>{{ $ar?'الشقة':'Apartment' }}</small><input name="apartment" maxlength="120"></label>
                        <label><small>{{ $ar?'رمز الدولة':'Country code' }}</small><input name="country_code" maxlength="2" value="EG" required></label>
                        <label><small>Latitude</small><input name="latitude" type="number" step="0.0000001" min="-90" max="90"></label>
                        <label><small>Longitude</small><input name="longitude" type="number" step="0.0000001" min="-180" max="180"></label>
                        <input type="hidden" name="location_source" value="manual">
                        <label class="wide"><small>{{ $ar?'علامة مميزة':'Landmark' }}</small><input name="landmark" maxlength="255"></label>
                        <label class="wide"><small>{{ $ar?'ملاحظات التوصيل':'Delivery notes' }}</small><textarea name="delivery_notes" maxlength="1000" rows="2"></textarea></label>
                        <label class="wide"><input type="checkbox" name="is_default" value="1"> {{ $ar?'تعيين كعنوان افتراضي':'Set as default' }}</label>
                        <div class="wide"><button class="foodex-action-primary" type="submit">{{ $ar?'حفظ العنوان':'Save address' }}</button></div>
                    </form>
                </details>
                @endif

                <div class="c360-address-grid" style="margin-top:16px">
                    @forelse($addresses as $address)
                    <article class="c360-info" style="display:block;padding:14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control)">
                        <div style="padding:0;border:0;background:transparent">
                            <div class="c360-actions" style="justify-content:space-between">
                                <strong>{{ $address->label ?: ($ar?'عنوان التوصيل':'Delivery address') }}</strong>
                                @if($address->is_default)<span class="c360-badge active">{{ $ar?'افتراضي':'Default' }}</span>@endif
                            </div>
                            <p style="margin:8px 0">{{ collect([$address->building,$address->street ?: $address->line1,$address->block,$address->area,$address->city,$address->governorate])->filter()->join(' · ') }}</p>
                            @if($address->landmark)<small>{{ $ar?'علامة مميزة':'Landmark' }}: {{ $address->landmark }}</small>@endif
                            @if($address->latitude!==null && $address->longitude!==null)
                                <div style="margin-top:8px"><small>{{ number_format((float)$address->latitude,7,'.','') }}, {{ number_format((float)$address->longitude,7,'.','') }}</small></div>
                                <a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)$address->latitude.','.(string)$address->longitude) }}">{{ $ar?'فتح على الخريطة':'Open in map' }}</a>
                            @endif

                            @if($canManageAddresses)
                            <div class="c360-address-actions" style="margin-top:12px">
                                @if(!$address->is_default)
                                <form method="post" action="{{ route('admin.customer-360.addresses.default',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}">
                                    @csrf
                                    <button class="foodex-action-secondary button secondary" type="submit">{{ $ar?'تعيين افتراضي':'Set default' }}</button>
                                </form>
                                @endif
                                <form method="post" action="{{ route('admin.customer-360.addresses.destroy',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" onsubmit="return confirm('{{ $ar?'حذف هذا العنوان؟':'Delete this address?' }}')">
                                    @csrf @method('DELETE')
                                    <button class="foodex-action-secondary button secondary" type="submit">{{ $ar?'حذف':'Delete' }}</button>
                                </form>
                            </div>

                            <details style="margin-top:10px">
                                <summary style="cursor:pointer;font-weight:800">{{ $ar?'تعديل العنوان':'Edit address' }}</summary>
                                <form method="post" action="{{ route('admin.customer-360.addresses.update',['platformCustomer'=>$customer->id,'address'=>$address->id]) }}" class="c360-address-form" style="margin-top:12px">
                                    @csrf @method('PATCH')
                                    <label><small>{{ $ar?'اسم العنوان':'Label' }}</small><input name="label" value="{{ $address->label }}" maxlength="100"></label>
                                    <label><small>{{ $ar?'اسم المستلم':'Recipient' }}</small><input name="recipient_name" value="{{ $address->recipient_name }}" maxlength="255"></label>
                                    <label><small>{{ $ar?'هاتف التوصيل':'Delivery phone' }}</small><input name="delivery_phone" value="{{ $address->delivery_phone }}" maxlength="50"></label>
                                    <label><small>{{ $ar?'المدينة':'City' }}</small><input name="city" value="{{ $address->city }}" maxlength="120"></label>
                                    <label class="wide"><small>{{ $ar?'العنوان':'Address' }}</small><input name="line1" value="{{ $address->line1 }}" maxlength="255"></label>
                                    <label><small>{{ $ar?'المنطقة':'Area' }}</small><input name="area" value="{{ $address->area }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'المحافظة':'Governorate' }}</small><input name="governorate" value="{{ $address->governorate }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'البلوك':'Block' }}</small><input name="block" value="{{ $address->block }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'المبنى':'Building' }}</small><input name="building" value="{{ $address->building }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'الدور':'Floor' }}</small><input name="floor" value="{{ $address->floor }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'الشقة':'Apartment' }}</small><input name="apartment" value="{{ $address->apartment }}" maxlength="120"></label>
                                    <label><small>{{ $ar?'رمز الدولة':'Country code' }}</small><input name="country_code" value="{{ $address->country_code }}" maxlength="2"></label>
                                    <label><small>Latitude</small><input name="latitude" type="number" step="0.0000001" min="-90" max="90" value="{{ $address->latitude }}"></label>
                                    <label><small>Longitude</small><input name="longitude" type="number" step="0.0000001" min="-180" max="180" value="{{ $address->longitude }}"></label>
                                    <input type="hidden" name="location_source" value="{{ $address->location_source ?: 'manual' }}">
                                    <label class="wide"><small>{{ $ar?'علامة مميزة':'Landmark' }}</small><input name="landmark" value="{{ $address->landmark }}" maxlength="255"></label>
                                    <label class="wide"><small>{{ $ar?'ملاحظات التوصيل':'Delivery notes' }}</small><textarea name="delivery_notes" maxlength="1000" rows="2">{{ $address->delivery_notes }}</textarea></label>
                                    <div class="wide"><button class="foodex-action-primary" type="submit">{{ $ar?'حفظ التعديل':'Save changes' }}</button></div>
                                </form>
                            </details>
                            @endif
                        </div>
                    </article>
                    @empty
                    <div class="foodex-empty-state">{{ $ar?'لا توجد عناوين محفوظة لهذا العميل.':'No saved addresses for this customer.' }}</div>
                    @endforelse
                </div>
            </section>

            @if($wholesale)
            @php($finance = $wholesale['financial'] ?? null)
            <section class="foodex-card c360-card">
                <h2>{{ $ar?'حساب الجملة والمالية':'Wholesale account & finance' }}</h2>
                <div class="c360-info" style="margin-top:16px">
                    <div><small>{{ $ar?'الشركة':'Company' }}</small><strong>{{ $wholesale['company_name'] ?: $customer->name }}</strong></div>
                    <div><small>{{ $ar?'الحالة':'Status' }}</small><strong>{{ $wholesale['status'] ?: '-' }}</strong></div>
                    <div><small>{{ $ar?'شريحة السعر':'Price tier' }}</small><strong>{{ $wholesale['tier_name'] ?: '-' }} @if($wholesale['tier_code'])· {{ $wholesale['tier_code'] }}@endif</strong></div>
                    @if($finance)
                    <div>
                        <small>{{ $ar?'الرصيد الحالي':'Current balance' }}</small>
                        <strong>
                            @if($finance['balance_direction']==='customer_owes_company')
                                {{ $ar?'عليك':'You owe' }}
                            @elseif($finance['balance_direction']==='company_owes_customer')
                                {{ $ar?'لك':'Company owes you' }}
                            @else
                                {{ $ar?'مسدد':'Settled' }}
                            @endif
                            · {{ number_format(abs((float)$finance['balance']),3) }} {{ $finance['currency'] ?: '' }}
                        </strong>
                    </div>
                    <div><small>{{ $ar?'حد الائتمان':'Credit limit' }}</small><strong>{{ number_format((float)$finance['credit_limit'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
                    <div><small>{{ $ar?'الائتمان المتاح':'Available credit' }}</small><strong>{{ number_format((float)$finance['available_credit_line'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
                    <div><small>{{ $ar?'قوة الشراء':'Purchasing power' }}</small><strong>{{ number_format((float)$finance['purchasing_power'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
                    <div><small>{{ $ar?'المبلغ المفتوح':'Open amount' }}</small><strong>{{ number_format((float)$finance['open_amount'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
                    <div><small>{{ $ar?'المتأخر':'Overdue amount' }}</small><strong>{{ number_format((float)$finance['overdue_amount'],3) }} {{ $finance['currency'] ?: '' }}</strong></div>
                    <div><small>{{ $ar?'آخر دفعة':'Last payment' }}</small><strong>{{ $finance['last_payment']['occurred_at'] ?? '-' }}</strong></div>
                    <div><small>{{ $ar?'آخر حركة':'Last transaction' }}</small><strong>{{ $finance['last_transaction']['occurred_at'] ?? '-' }}</strong></div>
                    @else
                    <div><small>{{ $ar?'حد الائتمان':'Credit limit' }}</small><strong>{{ $wholesale['credit_limit']===null?'-':number_format($wholesale['credit_limit'],3) }}</strong></div>
                    @endif
                </div>

                @if($canManageFinance && $finance)
                <details style="margin-top:16px">
                    <summary style="cursor:pointer;font-weight:800">{{ $ar?'تسجيل حركة مالية':'Record financial entry' }}</summary>
                    <p>{{ $ar?'كل حركة تضاف كسجل تدقيق جديد ولا تعدّل الرصيد المخزن مباشرة.':'Each action appends an auditable ledger entry; no stored balance is overwritten.' }}</p>
                    <form method="post" action="{{ route('admin.customer-360.finance-entries.store',['platformCustomer'=>$customer->id]) }}" class="c360-address-form" style="margin-top:12px">
                        @csrf
                        <label>
                            <small>{{ $ar?'نوع الحركة':'Entry type' }}</small>
                            <select name="entry_type" required>
                                <option value="payment">{{ $ar?'دفعة':'Record Payment' }}</option>
                                <option value="credit_note">{{ $ar?'إشعار دائن':'Credit Note' }}</option>
                                <option value="debit_note">{{ $ar?'إشعار مدين':'Debit Note' }}</option>
                                <option value="opening_balance">{{ $ar?'رصيد افتتاحي':'Opening Balance' }}</option>
                                <option value="adjustment_positive">{{ $ar?'تسوية موجبة':'Positive Adjustment' }}</option>
                                <option value="adjustment_negative">{{ $ar?'تسوية سالبة':'Negative Adjustment' }}</option>
                                <option value="return">{{ $ar?'مرتجع':'Return' }}</option>
                                <option value="refund">{{ $ar?'رد مبلغ':'Refund' }}</option>
                            </select>
                        </label>
                        <label>
                            <small>{{ $ar?'الاتجاه':'Direction' }}</small>
                            <select name="direction" required>
                                <option value="credit">{{ $ar?'دائن — يقلل عليك / يزيد لك':'Credit — reduces amount owed / increases customer credit' }}</option>
                                <option value="debit">{{ $ar?'مدين — يزيد عليك / يقلل لك':'Debit — increases amount owed / reduces customer credit' }}</option>
                            </select>
                        </label>
                        <label><small>{{ $ar?'المبلغ':'Amount' }}</small><input name="amount" type="number" min="0.001" step="0.001" required></label>
                        <label><small>{{ $ar?'العملة':'Currency' }}</small><input name="currency" value="{{ $finance['currency'] }}" maxlength="3" minlength="3" required></label>
                        <label><small>{{ $ar?'مرجع':'Reference' }}</small><input name="reference" maxlength="120"></label>
                        <label><small>{{ $ar?'رقم الفاتورة الداخلي':'Invoice ID' }}</small><input name="invoice_id" type="number" min="1"></label>
                        <label class="wide"><small>{{ $ar?'الوصف':'Description' }}</small><input name="description" maxlength="500"></label>
                        <label><small>{{ $ar?'التاريخ':'Date' }}</small><input name="occurred_at" type="datetime-local"></label>
                        <div class="wide"><button class="foodex-action-primary" type="submit">{{ $ar?'حفظ الحركة':'Record entry' }}</button></div>
                    </form>
                </details>
                @endif
            </section>
            @endif

            @if($access['mode']!=='b2b')
            <section class="foodex-card c360-card">
                <h2>{{ $ar?'متاجر التجزئة المرتبطة':'Materialized Retail stores' }}</h2>
                <p>{{ $ar?'تظهر فقط المتاجر التي يحق لك رؤيتها.':'Only Retail stores inside your authorization scope are shown.' }}</p>
                <div class="c360-list">
                    @forelse($retailStores as $store)
                        <span class="c360-store">{{ $store['name'] }} · {{ $store['code'] }}</span>
                    @empty
                        <span class="c360-store">{{ $ar?'لا توجد متاجر ضمن النطاق الحالي':'No Retail stores in the current scope' }}</span>
                    @endforelse
                </div>
            </section>
            @endif

            <section class="foodex-card c360-card">
                <h2>{{ $ar?'أحدث الطلبات':'Recent orders' }}</h2>
                <p>{{ $ar?'كل رابط يفتح مساحة الإدارة الخاصة بنفس القناة والمتجر.':'Each link opens the matching authorized channel/store workspace.' }}</p>
                @if(empty($orders))
                    <div class="foodex-empty-state">{{ $ar?'لا توجد طلبات داخل النطاق الحالي.':'No orders in the current scope.' }}</div>
                @else
                <div style="overflow:auto;margin-top:12px">
                    <table class="c360-table">
                        <thead><tr><th>{{ $ar?'الطلب':'Order' }}</th><th>{{ $ar?'المتجر':'Store' }}</th><th>{{ $ar?'القناة':'Channel' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجمالي':'Total' }}</th><th>{{ $ar?'التاريخ':'Date' }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach($orders as $order)
                            <tr id="order-{{ $order['id'] }}">
                                <td data-label="{{ $ar?'الطلب':'Order' }}"><strong>{{ $order['number'] }}</strong></td>
                                <td data-label="{{ $ar?'المتجر':'Store' }}">{{ $order['store'] }}</td>
                                <td data-label="{{ $ar?'القناة':'Channel' }}"><span class="c360-badge {{ $order['channel'] }}">{{ strtoupper($order['channel']) }}</span></td>
                                <td data-label="{{ $ar?'الحالة':'Status' }}">{{ $order['status'] }}</td>
                                <td data-label="{{ $ar?'الإجمالي':'Total' }}">{{ number_format($order['total'],3) }} {{ $order['currency'] }}</td>
                                <td data-label="{{ $ar?'التاريخ':'Date' }}">{{ $order['created_at'] }}</td>
                                <td><a class="foodex-action-secondary button secondary" href="{{ $order['url'] }}">{{ $ar?'إدارة الطلب':'Manage order' }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </section>

            <section class="foodex-card c360-card">
                <h2>{{ $ar?'الفواتير':'Invoices' }}</h2>
                <p>{{ $ar?'الفواتير هنا تستخدم نفس صلاحيات Finance وعزل المتاجر المطبق في النظام.':'Invoice links use the existing Finance authorization and store isolation.' }}</p>
                @if(empty($invoices))
                    <div class="foodex-empty-state">{{ $ar?'لا توجد فواتير داخل النطاق الحالي.':'No invoices in the current scope.' }}</div>
                @else
                <div style="overflow:auto;margin-top:12px">
                    <table class="c360-table">
                        <thead><tr><th>{{ $ar?'الفاتورة':'Invoice' }}</th><th>{{ $ar?'المتجر':'Store' }}</th><th>{{ $ar?'القناة':'Channel' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجمالي':'Total' }}</th><th>{{ $ar?'الإصدار':'Issued' }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach($invoices as $invoice)
                            <tr>
                                <td data-label="{{ $ar?'الفاتورة':'Invoice' }}"><strong>{{ $invoice['number'] }}</strong></td>
                                <td data-label="{{ $ar?'المتجر':'Store' }}">{{ $invoice['store'] }}</td>
                                <td data-label="{{ $ar?'القناة':'Channel' }}"><span class="c360-badge {{ $invoice['channel'] }}">{{ strtoupper($invoice['channel']) }}</span></td>
                                <td data-label="{{ $ar?'الحالة':'Status' }}">{{ $invoice['status'] }}</td>
                                <td data-label="{{ $ar?'الإجمالي':'Total' }}">{{ number_format($invoice['total'],3) }} {{ $invoice['currency'] }}</td>
                                <td data-label="{{ $ar?'الإصدار':'Issued' }}">{{ $invoice['issued_at'] ?: '-' }}</td>
                                <td class="c360-actions"><a class="foodex-action-secondary button secondary" href="{{ $invoice['url'] }}">{{ $ar?'التفاصيل':'Details' }}</a><a class="foodex-action-primary" href="{{ $invoice['pdf_url'] }}">PDF</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </section>
        </div>
    </main>
</div>
</body></html>
