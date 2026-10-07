<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'العميل 360':'Customer 360' }} · FOODEX</title>
@include('admin._brand-components')
<style>
.c360-shell{display:grid;gap:var(--foodex-space-5)}.c360-panel{padding:var(--foodex-space-5)}
.c360-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}.c360-head h2{margin:0}.c360-head p{margin:6px 0 0;color:var(--foodex-muted)}
.c360-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end;margin-top:18px}
.c360-filters label{display:grid;gap:6px;font-weight:700}.c360-actions{display:flex;gap:8px;flex-wrap:wrap}
.c360-table{width:100%;border-collapse:collapse}.c360-table th,.c360-table td{padding:13px 12px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.c360-table th{font-size:12px;color:var(--foodex-muted);background:#f8fafc}.c360-table tr:hover td{background:#fbfefc}
.c360-person{display:grid;gap:3px}.c360-person strong{font-size:14px}.c360-person small{color:var(--foodex-muted)}
.c360-badge{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--foodex-border);border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800;background:#fff}.c360-badge.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:transparent}.c360-badge.b2b{background:#fff7ed;color:#9a3412}.c360-badge.b2c{background:#eefbf4;color:#166534}
.c360-kpi{display:grid;gap:2px}.c360-kpi strong{font-size:14px}.c360-kpi small{color:var(--foodex-muted)}.c360-empty{padding:34px;text-align:center;color:var(--foodex-muted)}
@media(max-width:860px){.c360-table thead{display:none}.c360-table,.c360-table tbody,.c360-table tr,.c360-table td{display:block;width:100%}.c360-table tr{padding:12px 0;border-bottom:1px solid var(--foodex-border)}.c360-table td{border:0;padding:7px 0}.c360-table td:before{content:attr(data-label);display:block;font-size:11px;font-weight:800;color:var(--foodex-muted);margin-bottom:2px}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · {{ strtoupper($access['mode']) }}</span>
                <h1>{{ $ar?'العميل 360':'Customer 360' }}</h1>
                <p>{{ $ar?'هوية عميل واحدة مع مصدر التسجيل والتعاملات المصرح بها حسب نطاقك.':'One customer identity with registration provenance and commerce history limited to your authorized scope.' }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        <div class="c360-shell">
            <section class="foodex-card c360-panel">
                <div class="c360-head">
                    <div>
                        <h2>{{ $ar?'البحث والتصفية':'Search & filters' }}</h2>
                        <p>{{ $ar?'النتائج لا تتجاوز صلاحيات المتجر أو القناة الحالية.':'Filters never broaden store or channel authorization.' }}</p>
                    </div>
                </div>
                <form method="get" class="c360-filters">
                    <label>{{ $ar?'بحث':'Search' }}
                        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ $ar?'الاسم أو البريد أو الهاتف':'Name, email or phone' }}">
                    </label>
                    <label>{{ $ar?'قناة التسجيل':'Origin channel' }}
                        <select name="origin_channel">
                            <option value="">{{ $ar?'الكل':'All' }}</option>
                            @foreach(['b2b'=>$ar?'الجملة':'Wholesale','b2c'=>$ar?'التجزئة':'Retail','unknown'=>$ar?'غير معروف':'Unknown'] as $value=>$label)
                                <option value="{{ $value }}" @selected(($filters['origin_channel']??'')===$value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $ar?'مصدر التسجيل':'Registration source' }}
                        <select name="registration_source">
                            <option value="">{{ $ar?'الكل':'All' }}</option>
                            @foreach(['customer_app'=>'Customer App','dashboard'=>'Dashboard','import'=>'Import','migration'=>'Migration'] as $value=>$label)
                                <option value="{{ $value }}" @selected(($filters['registration_source']??'')===$value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ $ar?'الحالة':'Status' }}
                        <select name="active">
                            <option value="">{{ $ar?'الكل':'All' }}</option>
                            <option value="1" @selected(($filters['active']??'')==='1')>{{ $ar?'نشط':'Active' }}</option>
                            <option value="0" @selected(($filters['active']??'')==='0')>{{ $ar?'غير نشط':'Inactive' }}</option>
                        </select>
                    </label>
                    @if($originStores->isNotEmpty())
                    <label>{{ $ar?'متجر التسجيل':'Origin store' }}
                        <select name="origin_store_id">
                            <option value="">{{ $ar?'الكل المسموح':'All authorized' }}</option>
                            @foreach($originStores as $store)
                                <option value="{{ $store->id }}" @selected((string)($filters['origin_store_id']??'')===(string)$store->id)>{{ $store->name }} · {{ $store->code }}</option>
                            @endforeach
                        </select>
                    </label>
                    @endif
                    <label>{{ $ar?'من تاريخ':'From' }}<input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
                    <label>{{ $ar?'إلى تاريخ':'To' }}<input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
                    <div class="c360-actions">
                        <button class="foodex-action-primary foodex-filter-action" type="submit">{{ $ar?'تطبيق':'Apply' }}</button>
                        <a class="foodex-action-secondary button secondary" href="{{ route('admin.customer-360.index') }}">{{ $ar?'مسح':'Clear' }}</a>
                    </div>
                </form>
            </section>

            <section class="foodex-card c360-panel">
                <div class="c360-head">
                    <div>
                        <h2>{{ $ar?'العملاء':'Customers' }}</h2>
                        <p>{{ $ar?'إجمالي النتائج':'Total results' }}: <strong>{{ $customers->total() }}</strong></p>
                    </div>
                </div>

                @if($customers->isEmpty())
                    <div class="c360-empty">{{ $ar?'لا توجد نتائج مطابقة داخل نطاقك.':'No matching customers in your authorized scope.' }}</div>
                @else
                <div style="overflow:auto;margin-top:16px">
                    <table class="c360-table">
                        <thead><tr>
                            <th>{{ $ar?'العميل':'Customer' }}</th>
                            <th>{{ $ar?'مصدر التسجيل':'Registration origin' }}</th>
                            <th>{{ $ar?'الحالة':'Status' }}</th>
                            @if($access['mode']!=='b2c')<th>{{ $ar?'شريحة الجملة':'Wholesale tier' }}</th>@endif
                            <th>{{ $ar?'الطلبات':'Orders' }}</th>
                            <th>{{ $ar?'الفواتير':'Invoices' }}</th>
                            <th>{{ $ar?'التسجيل':'Registered' }}</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        @foreach($customers as $row)
                            <tr>
                                <td data-label="{{ $ar?'العميل':'Customer' }}">
                                    <div class="c360-person"><strong>{{ $row['name'] }}</strong><small>{{ $row['email'] }}</small><small>{{ $row['phone'] ?: '-' }}</small></div>
                                </td>
                                @php
                                    $originChannel = strtolower((string)($row['origin']['channel'] ?? ''));
                                    $originChannelLabel = match($originChannel) {
                                        'b2b', 'wholesale' => $ar ? 'جملة' : 'Wholesale',
                                        'b2c', 'retail' => $ar ? 'تجزئة' : 'Retail',
                                        default => $ar ? 'عميل' : 'Customer',
                                    };
                                @endphp
                                <td data-label="{{ $ar?'مصدر التسجيل':'Registration origin' }}">
                                    <span class="c360-badge">{{ $originChannelLabel }}</span>
                                    <div style="margin-top:5px;font-weight:700">{{ $row['origin']['label'] }}</div>
                                    <small>{{ $row['registration_source'] }}</small>
                                </td>
                                <td data-label="{{ $ar?'الحالة':'Status' }}"><span class="c360-badge {{ $row['active']?'active':'' }}">{{ $row['active']?($ar?'نشط':'Active'):($ar?'غير نشط':'Inactive') }}</span></td>
                                @if($access['mode']!=='b2c')<td data-label="{{ $ar?'شريحة الجملة':'Wholesale tier' }}">{{ $row['wholesale_tier'] ?: '-' }}</td>@endif
                                <td data-label="{{ $ar?'الطلبات':'Orders' }}"><div class="c360-kpi"><strong>{{ number_format($row['orders_count']) }}</strong><small>{{ number_format($row['orders_total'],3) }} EGP</small></div></td>
                                <td data-label="{{ $ar?'الفواتير':'Invoices' }}"><div class="c360-kpi"><strong>{{ number_format($row['invoices_count']) }}</strong><small>{{ number_format($row['invoices_total'],3) }} EGP</small></div></td>
                                <td data-label="{{ $ar?'التسجيل':'Registered' }}">{{ optional($row['registered_at'])->format('Y-m-d H:i') ?: '-' }}</td>
                                <td><a class="foodex-action-primary" href="{{ $row['url'] }}">{{ $ar?'فتح 360':'Open 360' }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:16px">{{ $customers->links() }}</div>
                @endif
            </section>
        </div>
    </main>
</div>
</body></html>
