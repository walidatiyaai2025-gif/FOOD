<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.b2b_workspace.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
*{box-sizing:border-box}body{margin:0}.layout{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}.sidebar{border-inline-end:1px solid var(--foodex-border);padding:20px}.main{padding:26px}.headline{display:flex;justify-content:space-between;gap:12px;align-items:end;margin-bottom:18px}.headline h1{margin:4px 0}.muted{color:var(--foodex-muted)}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:#fff;border:1px solid var(--foodex-border);border-radius:16px;padding:18px}.card strong{font-size:13px;color:var(--foodex-muted)}.card p{font-size:28px;font-weight:850;margin:8px 0 0}.panel{margin-top:16px}.toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.links{display:flex;flex-wrap:wrap;gap:8px}.links a{padding:8px 11px;border:1px solid var(--foodex-border);border-radius:10px;font-size:12px;font-weight:750}.links a.active{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:#c9e7d3}.table-wrap{overflow:auto;border:1px solid var(--foodex-border);border-radius:12px}.data{width:100%;border-collapse:collapse;min-width:760px}.data th,.data td{padding:12px 14px;text-align:start;border-bottom:1px solid #eef1f4;font-size:13px}.data th{background:#f8faf9;color:var(--foodex-muted);font-size:12px}.data tr:last-child td{border-bottom:0}.state{display:inline-flex;align-items:center;gap:6px}.state:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green)}.state.off:before{background:#98a2b3}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;background:var(--foodex-orange-soft);color:var(--foodex-orange);font-size:11px;font-weight:750}.badge.active,.badge.delivered{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.suspended,.badge.denied,.badge.cancelled{background:#fff0f0;color:var(--foodex-red)}@media(max-width:1000px){.cards{grid-template-columns:1fr 1fr}}@media(max-width:760px){.layout{grid-template-columns:1fr}.sidebar{display:none}.main{padding:16px}.cards{grid-template-columns:1fr}.headline,.toolbar{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="main">
    <div class="headline">
        <div>
            <a class="muted" href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a>
            <h1>{{ __('admin.b2b_workspace.modules.'.$module) }}</h1>
            <div class="muted">{{ app()->getLocale()==='ar' ? 'FOODEX · إدارة الجملة ببيانات مباشرة من النظام' : 'FOODEX · B2B management with live server data' }}</div>
        </div>
    </div>
    <section class="cards">
        @foreach($counts as $key=>$value)
        <article class="card"><strong>{{ __('admin.b2b_workspace.modules.'.$key) }}</strong><p>{{ number_format($value) }}</p></article>
        @endforeach
    </section>

    @if($moduleData)
    @php
      $labels=app()->getLocale()==='ar'
      ? ['number'=>'رقم الطلب','client'=>'العميل','store'=>'الفرع','status'=>'الحالة','amount'=>'الإجمالي','created'=>'الإنشاء','code'=>'الكود','name'=>'الاسم','products'=>'المنتجات','orders'=>'الطلبات','company'=>'الشركة','email'=>'البريد','phone'=>'الهاتف','tax_number'=>'الرقم الضريبي','sku'=>'SKU','price'=>'السعر','available'=>'المتاح']
      : ['number'=>'Order','client'=>'Client','store'=>'Store','status'=>'Status','amount'=>'Amount','created'=>'Created','code'=>'Code','name'=>'Name','products'=>'Products','orders'=>'Orders','company'=>'Company','email'=>'Email','phone'=>'Phone','tax_number'=>'Tax number','sku'=>'SKU','price'=>'Price','available'=>'Available'];
    @endphp
    <section class="panel">
      <div class="toolbar">
        <div><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><div class="muted">{{ app()->getLocale()==='ar' ? 'النطاق محصور في متاجر وقناة B2B.' : 'Scope is restricted to B2B stores and channel data.' }}</div></div>
        <nav class="links" aria-label="B2B core modules">
          @foreach(['dashboard','stores','clients','products'] as $core)
          <a class="{{ $module===$core?'active':'' }}" href="{{ $core==='dashboard'?route('admin.b2b.dashboard'):route('admin.b2b.module',['module'=>$core]) }}">{{ __('admin.b2b_workspace.modules.'.$core) }}</a>
          @endforeach
        </nav>
      </div>
      @if(count($moduleData['rows']))
      <div class="table-wrap"><table class="data"><thead><tr>@foreach($moduleData['columns'] as $column)<th>{{ $labels[$column]??$column }}</th>@endforeach</tr></thead><tbody>
      @foreach($moduleData['rows'] as $row)<tr>@foreach($moduleData['columns'] as $column)<td>
        @if($column==='status' && is_bool($row[$column]))<span class="state {{ $row[$column]?'':'off' }}">{{ $row[$column]?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
        @elseif($column==='status')<span class="badge {{ $row[$column] }}">{{ $row[$column] }}</span>
        @else{{ $row[$column] }}@endif
      </td>@endforeach</tr>@endforeach
      </tbody></table></div>
      @else
      <p class="muted">{{ app()->getLocale()==='ar' ? 'لا توجد بيانات متاحة في هذا القسم.' : 'No records are available in this section.' }}</p>
      @endif
    </section>
    @else
    <section class="panel"><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><p class="muted">{{ __('admin.b2b_workspace.empty_hint') }}</p></section>
    @endif
</main>
</div>
</body>
</html>
