@php
    $fieldFinance = $fieldFinance ?? [];
    $opsTab = $fieldFinance['tab'] ?? 'wallets';
    $opsRows = $fieldFinance['rows'] ?? [];
    $opsPagination = $fieldFinance['pagination'] ?? ['current_page'=>1,'last_page'=>1,'total'=>0];
    $opsTabs = [
        'wallets' => app()->getLocale()==='ar' ? 'المحافظ' : 'Wallets',
        'collections' => app()->getLocale()==='ar' ? 'التحصيلات' : 'Collections',
        'remittances' => app()->getLocale()==='ar' ? 'التوريدات' : 'Remittances',
        'reconciliation' => app()->getLocale()==='ar' ? 'المطابقة' : 'Reconciliation',
    ];
    $opsStatusLabels = [
        'active'=>app()->getLocale()==='ar'?'نشط':'Active',
        'suspended'=>app()->getLocale()==='ar'?'موقوف':'Suspended',
        'closed'=>app()->getLocale()==='ar'?'مغلق':'Closed',
        'posted'=>app()->getLocale()==='ar'?'مسجل':'Posted',
        'reversed'=>app()->getLocale()==='ar'?'معكوس':'Reversed',
        'failed'=>app()->getLocale()==='ar'?'فشل':'Failed',
        'pending'=>app()->getLocale()==='ar'?'قيد المراجعة':'Pending',
        'approved'=>app()->getLocale()==='ar'?'معتمد':'Approved',
        'rejected'=>app()->getLocale()==='ar'?'مرفوض':'Rejected',
        'reconciled'=>app()->getLocale()==='ar'?'تمت المطابقة':'Reconciled',
    ];
    $opsBaseQuery = request()->except('ops_page', 'ops_tab');
@endphp

<section class="foodex-ops-shell" data-field-finance-operations>
    <div class="toolbar">
        <div>
            <strong>{{ app()->getLocale()==='ar'?'التحصيل والعهدة والتوريد':'Collections, custody & remittance' }}</strong>
            <div class="muted">{{ app()->getLocale()==='ar'
                ? 'قراءة وتشغيل مباشر من سجل التحصيل والعهدة المشترك بدون دفتر مالي مكرر.'
                : 'Operational view backed directly by the shared collection and custody ledger; no duplicate finance engine.' }}</div>
        </div>
    </div>

    <nav class="foodex-tabs" aria-label="{{ app()->getLocale()==='ar'?'تبويبات عمليات المالية':'Finance operations tabs' }}">
        @foreach($opsTabs as $key=>$label)
            <a
                href="{{ route('admin.b2b.module', array_merge(['module'=>'finance'], $opsBaseQuery, ['ops_tab'=>$key])) }}"
                @class(['active'=>$opsTab===$key])
                @if($opsTab===$key) aria-current="page" @endif
            >{{ $label }}</a>
        @endforeach
    </nav>

    <form method="get" action="{{ route('admin.b2b.module',['module'=>'finance']) }}" class="foodex-ops-toolbar">
        <input type="hidden" name="ops_tab" value="{{ $opsTab }}">
        @foreach(request()->only(['from','to','customer_id']) as $name=>$value)
            @if($value !== null && $value !== '')<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
        @endforeach
        <label>
            <span>{{ app()->getLocale()==='ar'?'بحث':'Search' }}</span>
            <input type="search" name="ops_q" value="{{ $fieldFinance['q'] ?? '' }}" placeholder="{{ app()->getLocale()==='ar'?'رقم، مرجع، جهة تحصيل أو متجر':'ID, reference, collector or store' }}">
        </label>
        <label>
            <span>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</span>
            <select name="ops_status">
                <option value="">{{ app()->getLocale()==='ar'?'كل الحالات':'All statuses' }}</option>
                @foreach(($fieldFinance['status_options'] ?? []) as $status)
                    <option value="{{ $status }}" @selected(($fieldFinance['status'] ?? '')===$status)>{{ $opsStatusLabels[$status] ?? $status }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>{{ app()->getLocale()==='ar'?'عدد الصفوف':'Rows' }}</span>
            <select name="ops_per_page">
                @foreach([10,25,50,100] as $size)
                    <option value="{{ $size }}" @selected((int)($fieldFinance['per_page'] ?? 25)===$size)>{{ $size }}</option>
                @endforeach
            </select>
        </label>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تطبيق':'Apply' }}</button>
    </form>

    @if($opsRows===[])
        <div class="foodex-ops-state">{{ app()->getLocale()==='ar'?'لا توجد بيانات تطابق الفلاتر الحالية.':'No records match the current filters.' }}</div>
    @else
        <div style="overflow:auto">
            <table class="foodex-ops-grid">
                <thead>
                    @if($opsTab==='wallets')
                        <tr>
                            <th>#</th><th>{{ app()->getLocale()==='ar'?'الجهة':'Actor' }}</th><th>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'العملة':'Currency' }}</th><th>{{ app()->getLocale()==='ar'?'العهدة':'Custody' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'توريدات معلقة':'Pending remittance' }}</th><th>{{ app()->getLocale()==='ar'?'المتاح':'Available' }}</th><th>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</th>
                        </tr>
                    @elseif($opsTab==='collections')
                        <tr>
                            <th>#</th><th>{{ app()->getLocale()==='ar'?'الجهة':'Actor' }}</th><th>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'المبلغ':'Amount' }}</th><th>{{ app()->getLocale()==='ar'?'المصدر':'Source' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'مرجع الدفع':'Payment ref' }}</th><th>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</th><th>{{ app()->getLocale()==='ar'?'الوقت':'Time' }}</th>
                        </tr>
                    @else
                        <tr>
                            <th>#</th><th>{{ app()->getLocale()==='ar'?'الجهة':'Actor' }}</th><th>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'المبلغ':'Amount' }}</th><th>{{ app()->getLocale()==='ar'?'الطريقة':'Method' }}</th>
                            <th>{{ app()->getLocale()==='ar'?'المرجع':'Reference' }}</th><th>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}</th>
                            @if($opsTab==='reconciliation')<th>{{ app()->getLocale()==='ar'?'الفحص':'Check' }}</th>@endif
                            <th>{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}</th>
                        </tr>
                    @endif
                </thead>
                <tbody>
                    @foreach($opsRows as $row)
                        @if($opsTab==='wallets')
                            <tr>
                                <td>{{ $row['id'] }}</td>
                                <td>{{ $row['actor_type'] }} #{{ $row['actor_id'] }}</td>
                                <td>{{ $row['store_name'] ?? ('#'.$row['store_id']) }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td>{{ number_format((float)$row['custody_balance'],3) }}</td>
                                <td>{{ number_format((float)$row['pending_remittance'],3) }}</td>
                                <td>{{ number_format((float)$row['available_to_remit'],3) }}</td>
                                <td>{{ $opsStatusLabels[$row['status']] ?? $row['status'] }}</td>
                            </tr>
                        @elseif($opsTab==='collections')
                            <tr>
                                <td>{{ $row['id'] }}</td>
                                <td>{{ $row['actor_type'] }} #{{ $row['actor_id'] }}</td>
                                <td>{{ $row['store_name'] ?? ('#'.$row['store_id']) }}</td>
                                <td>{{ $row['currency'] }} {{ number_format((float)$row['amount'],3) }}</td>
                                <td>{{ $row['source'] }}</td>
                                <td>{{ $row['provider_reference'] ?: $row['idempotency_key'] }}</td>
                                <td>{{ $opsStatusLabels[$row['status']] ?? $row['status'] }}</td>
                                <td>{{ $row['created_at'] }}</td>
                            </tr>
                        @else
                            <tr>
                                <td>{{ $row['id'] }}</td>
                                <td>{{ $row['actor_type'] }} #{{ $row['actor_id'] }}</td>
                                <td>{{ $row['store_name'] ?? ('#'.$row['store_id']) }}</td>
                                <td>{{ $row['currency'] }} {{ number_format((float)$row['amount'],3) }}</td>
                                <td>{{ $row['method'] }}</td>
                                <td>{{ $row['reference'] ?: '—' }}</td>
                                <td>{{ $opsStatusLabels[$row['status']] ?? $row['status'] }}</td>
                                @if($opsTab==='reconciliation')
                                    <td>
                                        @if((int)($row['has_exception'] ?? 0)===1)
                                            <strong style="color:var(--foodex-red)">{{ app()->getLocale()==='ar'?'استثناء عهدة':'Custody exception' }}</strong>
                                        @else
                                            <span class="muted">{{ app()->getLocale()==='ar'?'سليم':'Clear' }}</span>
                                        @endif
                                    </td>
                                @endif
                                <td>
                                    @if(($fieldFinance['can_manage'] ?? false) && in_array($row['status'],['pending','approved'],true))
                                        <details class="foodex-ops-actions">
                                            <summary aria-label="{{ app()->getLocale()==='ar'?'الإجراءات':'Actions' }}">⋮</summary>
                                            <div class="foodex-ops-menu">
                                                @if($row['status']==='pending')
                                                    <form method="post" action="{{ route('admin.b2b.finance.remittances.review',['remittance'=>$row['id'],'action'=>'approve']) }}">
                                                        @csrf
                                                        <button type="submit">{{ app()->getLocale()==='ar'?'اعتماد':'Approve' }}</button>
                                                    </form>
                                                    <form method="post" action="{{ route('admin.b2b.finance.remittances.review',['remittance'=>$row['id'],'action'=>'reject']) }}">
                                                        @csrf
                                                        <button class="danger" type="submit">{{ app()->getLocale()==='ar'?'رفض':'Reject' }}</button>
                                                    </form>
                                                @elseif($row['status']==='approved')
                                                    <form method="post" action="{{ route('admin.b2b.finance.remittances.review',['remittance'=>$row['id'],'action'=>'reconcile']) }}">
                                                        @csrf
                                                        <button type="submit">{{ app()->getLocale()==='ar'?'تأكيد المطابقة':'Mark reconciled' }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </details>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="workspace-inline-form" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <span class="muted">{{ app()->getLocale()==='ar'?'الإجمالي':'Total' }}: {{ number_format((int)($opsPagination['total'] ?? 0)) }}</span>
        <div class="links">
            @if(($opsPagination['current_page'] ?? 1)>1)
                <a href="{{ route('admin.b2b.module', array_merge(['module'=>'finance'], request()->except('ops_page'), ['ops_page'=>(int)$opsPagination['current_page']-1])) }}">{{ app()->getLocale()==='ar'?'السابق':'Previous' }}</a>
            @endif
            <span>{{ (int)($opsPagination['current_page'] ?? 1) }} / {{ max(1,(int)($opsPagination['last_page'] ?? 1)) }}</span>
            @if(($opsPagination['current_page'] ?? 1)<($opsPagination['last_page'] ?? 1))
                <a href="{{ route('admin.b2b.module', array_merge(['module'=>'finance'], request()->except('ops_page'), ['ops_page'=>(int)$opsPagination['current_page']+1])) }}">{{ app()->getLocale()==='ar'?'التالي':'Next' }}</a>
            @endif
        </div>
    </div>
</section>