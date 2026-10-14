<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'صندوق خدمة العملاء':'Customer Service Inbox' }} · FOODEX</title>
@include('admin._brand-components')
<style>
.wa-shell{display:grid;gap:14px}.wa-toolbar,.wa-profile{padding:16px}.wa-toolbar form{display:grid;grid-template-columns:2fr repeat(4,minmax(130px,1fr));gap:10px;align-items:end}
.wa-workspace{display:grid;grid-template-columns:minmax(250px,.9fr) minmax(360px,1.6fr) minmax(280px,1fr);gap:14px;min-height:68vh}
.wa-pane{padding:0;overflow:hidden}.wa-pane-head{padding:14px 16px;border-bottom:1px solid var(--foodex-border);display:flex;justify-content:space-between;gap:10px;align-items:center}.wa-pane-body{padding:14px 16px}
.wa-list{display:grid}.wa-row{display:block;padding:14px 16px;border-bottom:1px solid var(--foodex-border);text-decoration:none;color:inherit}.wa-row:hover,.wa-row.active{background:#f4fbf7}.wa-row-top{display:flex;justify-content:space-between;gap:10px}.wa-row p{margin:4px 0;color:var(--foodex-muted);font-size:12px}
.wa-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:800;background:#eef2f7}.wa-badge.wait{background:#fff7ed;color:#9a3412}.wa-badge.open{background:#eefbf4;color:#166534}.wa-badge.resolved{background:#f1f5f9;color:#475569}.wa-unread{min-width:22px;height:22px;border-radius:99px;background:#166534;color:#fff;text-align:center;line-height:22px;font-size:11px}
.wa-timeline{display:grid;gap:10px;max-height:46vh;overflow:auto;padding:16px}.wa-message{max-width:82%;padding:11px 13px;border-radius:14px;background:#f1f5f9}.wa-message.outbound{margin-inline-start:auto;background:#e8f8ee}.wa-message.note{max-width:100%;background:#fff8db;border:1px dashed #d8a800}.wa-message small{display:block;color:var(--foodex-muted);margin-top:5px}
.wa-compose{border-top:1px solid var(--foodex-border);padding:14px 16px;display:grid;gap:10px}.wa-compose textarea{width:100%;min-height:82px}.wa-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.wa-inline label{display:grid;gap:4px;min-width:130px}
.wa-360{display:grid;gap:12px}.wa-card{border:1px solid var(--foodex-border);border-radius:12px;padding:12px}.wa-card h3{margin:0 0 9px;font-size:14px}.wa-card p{margin:4px 0;font-size:13px}.wa-mini{width:100%;border-collapse:collapse}.wa-mini td{padding:6px 0;border-bottom:1px solid var(--foodex-border);font-size:12px}.wa-tags{display:flex;gap:6px;flex-wrap:wrap}.wa-tag{padding:4px 8px;background:#eef2ff;border-radius:999px;font-size:11px;font-weight:700}
.wa-empty{padding:32px;text-align:center;color:var(--foodex-muted)}.wa-status{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr)) auto;gap:8px;align-items:end}
@media(max-width:1180px){.wa-workspace{grid-template-columns:300px 1fr}.wa-context{grid-column:1/-1}.wa-toolbar form{grid-template-columns:repeat(3,1fr)}}
@media(max-width:760px){.wa-toolbar form,.wa-status{grid-template-columns:1fr}.wa-workspace{grid-template-columns:1fr}.wa-context{grid-column:auto}.wa-message{max-width:94%}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · WhatsApp CRM · Experimental</span>
                <h1>{{ $ar?'صندوق خدمة العملاء المشترك':'Shared Customer Service Inbox' }}</h1>
                <p>{{ $ar?'واجهة تشغيلية معزولة؛ الردود تبقى معلقة للنقل ولا تعتبر مرسلة حتى يؤكدها مزود النقل.':'Isolated operational workspace; replies remain transport-pending and are not considered sent until transport confirms them.' }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        @if(session('status'))<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="foodex-card" style="padding:12px 16px;margin-bottom:12px;color:#991b1b"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="wa-shell">
            <section class="foodex-card wa-profile">
                <form method="post" action="{{ route('admin.whatsapp-inbox.status') }}" class="wa-status">
                    @csrf @method('PATCH')
                    <label>{{ $ar?'حالتي':'My status' }}
                        <select name="status">
                            @foreach(['ONLINE','BUSY','AWAY','OFFLINE'] as $status)
                                <option value="{{ $status }}" @selected(($profile->status??'OFFLINE')===$status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $ar?'السعة':'Capacity' }}<input type="number" min="1" max="500" name="capacity" value="{{ $profile->capacity??20 }}"></label>
                    <label>{{ $ar?'قبول محادثات جديدة':'Accept new' }}
                        <select name="accepts_new"><option value="1" @selected(($profile->accepts_new??1)==1)>{{ $ar?'نعم':'Yes' }}</option><option value="0" @selected(($profile->accepts_new??1)==0)>{{ $ar?'لا':'No' }}</option></select>
                    </label>
                    <button class="foodex-action-primary" type="submit">{{ $ar?'تحديث':'Update' }}</button>
                </form>
            </section>

            <section class="foodex-card wa-toolbar">
                <form method="get">
                    <label>{{ $ar?'بحث':'Search' }}<input name="q" value="{{ $filters['q']??'' }}" placeholder="{{ $ar?'اسم، هاتف، بريد':'Name, phone, email' }}"></label>
                    <label>{{ $ar?'الحالة':'Status' }}
                        <select name="status"><option value="">{{ $ar?'الكل':'All' }}</option>@foreach(['OPEN','WAITING_FOR_AGENT','WAITING_FOR_CUSTOMER','RESOLVED','CLOSED'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ $status }}</option>@endforeach</select>
                    </label>
                    <label>{{ $ar?'الإسناد':'Assignment' }}
                        <select name="assignment">@foreach(['all'=>$ar?'الكل':'All','mine'=>$ar?'لي':'Mine','unassigned'=>$ar?'غير مسند':'Unassigned'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['assignment']??'all')===$value)>{{ $label }}</option>@endforeach</select>
                    </label>
                    <label>{{ $ar?'المتجر':'Store' }}
                        <select name="store_id"><option value="">{{ $ar?'كل المسموح':'All authorized' }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)($filters['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select>
                    </label>
                    <label>{{ $ar?'القناة':'Channel' }}<input name="channel" value="{{ $filters['channel']??'' }}" placeholder="whatsapp"></label>
                    <button class="foodex-action-primary" type="submit">{{ $ar?'تطبيق':'Apply' }}</button>
                </form>
            </section>

            <div class="wa-workspace">
                <section class="foodex-card wa-pane">
                    <div class="wa-pane-head"><strong>{{ $ar?'المحادثات':'Conversations' }}</strong><span>{{ $conversations->total() }}</span></div>
                    <div class="wa-list">
                    @forelse($conversations as $row)
                        @php($active=$selected && (int)$selected->id===(int)$row->id)
                        <a class="wa-row {{ $active?'active':'' }}" href="{{ route('admin.whatsapp-inbox.index', array_merge(request()->except('page','conversation'),['conversation'=>$row->id])) }}">
                            <div class="wa-row-top"><strong>{{ $row->customer_name ?: ($ar?'عميل غير معروف':'Unknown customer') }}</strong>@if($row->unread_count>0)<span class="wa-unread">{{ $row->unread_count }}</span>@endif</div>
                            <p>{{ $row->store_name ?: '-' }} · {{ $row->channel }} · {{ $row->agent_name ?: ($ar?'غير مسند':'Unassigned') }}</p>
                            <span class="wa-badge {{ str_contains($row->status,'WAITING')?'wait':($row->status==='OPEN'?'open':'resolved') }}">{{ $row->status }}</span>
                        </a>
                    @empty
                        <div class="wa-empty">{{ $ar?'لا توجد محادثات داخل نطاقك.':'No conversations in your authorized scope.' }}</div>
                    @endforelse
                    </div>
                    <div class="wa-pane-body">{{ $conversations->links() }}</div>
                </section>

                <section class="foodex-card wa-pane">
                    @if($selected)
                    <div class="wa-pane-head">
                        <div><strong>{{ $selected->customer_name ?: ($ar?'عميل':'Customer') }}</strong><div style="font-size:12px;color:var(--foodex-muted)">{{ $selected->customer_phone ?: $selected->customer_email }}</div></div>
                        <span class="wa-badge">{{ $selected->status }}</span>
                    </div>
                    <div class="wa-pane-body">
                        <div class="wa-inline" style="margin-bottom:10px">
                            @foreach($tags as $tag)<span class="wa-tag">{{ $tag->name }}</span>@endforeach
                        </div>
                        <form method="post" action="{{ route('admin.whatsapp-inbox.assign',['conversation'=>$selected->id]) }}" class="wa-inline">
                            @csrf @method('PATCH')
                            <input type="hidden" name="expected_version" value="{{ $selected->lock_version }}">
                            <label>{{ $ar?'الوكيل الأساسي':'Primary Agent' }}<select name="agent_id" required><option value="">{{ $ar?'اختر':'Choose' }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected((int)$selected->primary_agent_id===$agent->id)>{{ $agent->name }} · {{ $agent->load }}/{{ $agent->capacity }}</option>@endforeach</select></label>
                            <label>{{ $ar?'البديل':'Backup' }}<select name="backup_agent_id"><option value="">{{ $ar?'بدون':'None' }}</option>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }}</option>@endforeach</select></label>
                            <label>{{ $ar?'سبب التغيير':'Reason' }}<input name="reason" maxlength="500"></label>
                            <button class="foodex-action-secondary" type="submit">{{ $ar?'إسناد / إعادة إسناد':'Assign / reassign' }}</button>
                        </form>
                        <div class="wa-inline" style="margin-top:10px">
                            <form method="post" action="{{ route('admin.whatsapp-inbox.transition',['conversation'=>$selected->id]) }}">@csrf @method('PATCH')<input type="hidden" name="action" value="{{ in_array($selected->status,['RESOLVED','CLOSED'])?'reopen':'resolve' }}"><button class="foodex-action-secondary" type="submit">{{ in_array($selected->status,['RESOLVED','CLOSED'])?($ar?'إعادة فتح':'Reopen'):($ar?'حل المحادثة':'Resolve') }}</button></form>
                            <form method="post" action="{{ route('admin.whatsapp-inbox.tag',['conversation'=>$selected->id]) }}" class="wa-inline">@csrf
                                <select name="tag_id" required><option value="">{{ $ar?'وسم':'Tag' }}</option>@foreach($allTags as $tag)<option value="{{ $tag->id }}">{{ $tag->name }}</option>@endforeach</select>
                                <select name="action"><option value="attach">{{ $ar?'إضافة':'Attach' }}</option><option value="detach">{{ $ar?'إزالة':'Detach' }}</option></select>
                                <button class="foodex-action-secondary" type="submit">{{ $ar?'تحديث الوسم':'Update tag' }}</button>
                            </form>
                        </div>
                    </div>

                    <div class="wa-timeline">
                        @forelse($timeline as $item)
                            <div class="wa-message {{ $item['kind']==='note'?'note':$item['direction'] }}">
                                @if($item['kind']==='note')<strong>🔒 {{ $ar?'ملاحظة داخلية':'Internal note' }}</strong>@endif
                                <div>{{ $item['body'] ?: ($ar?'مرفق':'Attachment') }}</div>
                                <small>{{ $item['actor'] }} · {{ $item['timestamp'] }} @if($item['delivery_status']) · {{ $item['delivery_status'] }}@endif</small>
                            </div>
                        @empty
                            <div class="wa-empty">{{ $ar?'لا توجد رسائل بعد.':'No messages yet.' }}</div>
                        @endforelse
                    </div>

                    <div class="wa-compose">
                        <form method="post" action="{{ route('admin.whatsapp-inbox.reply',['conversation'=>$selected->id]) }}">@csrf
                            <label><strong>{{ $ar?'رد للعميل':'Customer reply' }}</strong><textarea name="body" required maxlength="5000"></textarea></label>
                            <button class="foodex-action-primary" type="submit">{{ $ar?'إضافة إلى عقد النقل':'Queue for transport' }}</button>
                            <small>{{ $ar?'لا يتم اعتبار الرد مرسلاً هنا؛ النقل الفعلي مسؤولية #1402.':'This does not claim provider delivery; actual transport belongs to #1402.' }}</small>
                        </form>
                        <form method="post" action="{{ route('admin.whatsapp-inbox.note',['conversation'=>$selected->id]) }}">@csrf
                            <label><strong>{{ $ar?'ملاحظة داخلية فقط':'Internal note only' }}</strong><textarea name="body" required maxlength="5000"></textarea></label>
                            <button class="foodex-action-secondary" type="submit">{{ $ar?'حفظ ملاحظة':'Save note' }}</button>
                        </form>
                    </div>
                    @else
                        <div class="wa-empty">{{ $ar?'اختر محادثة من القائمة.':'Choose a conversation from the queue.' }}</div>
                    @endif
                </section>

                <section class="foodex-card wa-pane wa-context">
                    <div class="wa-pane-head"><strong>{{ $ar?'العميل 360':'Customer 360' }}</strong></div>
                    <div class="wa-pane-body wa-360">
                    @if($customer360)
                        @php($customer=$customer360['customer'])
                        <div class="wa-card">
                            <h3>{{ $customer->name }}</h3>
                            <p>{{ $customer->phone ?: '-' }}</p><p>{{ $customer->email }}</p>
                            <p>{{ $ar?'القناة الأصلية':'Origin' }}: {{ $customer->origin_channel ?: '-' }}</p>
                            <p>{{ $ar?'المتجر':'Store' }}: {{ $customer->originStore?->name ?: '-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ $ar?'الملكية':'Ownership' }}</h3>
                            <p>{{ $ar?'الأساسي':'Primary' }}: {{ $customer360['ownership']->primary_agent_name??($ar?'غير مسند':'Unassigned') }}</p>
                            <p>{{ $ar?'البديل':'Backup' }}: {{ $customer360['ownership']->backup_agent_name??'-' }}</p>
                            <p>{{ $ar?'المصدر':'Source' }}: {{ $customer360['ownership']->source??'-' }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ $ar?'النشاط التجاري':'Commercial activity' }}</h3>
                            <p>{{ $ar?'الطلبات':'Orders' }}: {{ $customer360['orders_count'] }}</p>
                            <p>{{ $ar?'إجمالي قيمة الطلبات':'Order value' }}: {{ number_format($customer360['orders_value'],3) }}</p>
                            <p>{{ $ar?'الفواتير':'Invoices' }}: {{ $customer360['invoices_count'] }}</p>
                        </div>
                        <div class="wa-card"><h3>{{ $ar?'أحدث الطلبات':'Recent orders' }}</h3><table class="wa-mini">@forelse($customer360['orders'] as $order)<tr><td>{{ $order->order_number }}</td><td>{{ $order->status }}</td><td>{{ number_format((float)$order->grand_total,3) }} {{ $order->currency }}</td></tr>@empty<tr><td>{{ $ar?'لا يوجد':'None' }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ $ar?'أحدث الفواتير':'Recent invoices' }}</h3><table class="wa-mini">@forelse($customer360['invoices'] as $invoice)<tr><td>{{ $invoice->invoice_number }}</td><td>{{ $invoice->status }}</td><td>{{ number_format((float)$invoice->total,3) }} {{ $invoice->currency }}</td></tr>@empty<tr><td>{{ $ar?'لا يوجد':'None' }}</td></tr>@endforelse</table></div>
                        <div class="wa-card"><h3>{{ $ar?'أحدث المدفوعات':'Recent payments' }}</h3><table class="wa-mini">@forelse($customer360['payments'] as $payment)<tr><td>{{ $payment->provider }}</td><td>{{ $payment->status }}</td><td>{{ number_format((float)$payment->amount,3) }} {{ $payment->currency }}</td></tr>@empty<tr><td>{{ $ar?'لا يوجد':'None' }}</td></tr>@endforelse</table></div>
                    @else
                        <div class="wa-empty">{{ $ar?'لا توجد هوية عميل متاحة للمحادثة المحددة.':'No customer identity is available for the selected conversation.' }}</div>
                    @endif
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
</body>
</html>
