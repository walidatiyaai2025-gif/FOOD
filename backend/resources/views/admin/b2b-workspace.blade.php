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
      ? ['number'=>'رقم الطلب','client'=>'العميل','store'=>'الفرع','status'=>'الحالة','amount'=>'الإجمالي','created'=>'الإنشاء','code'=>'الكود','name'=>'الاسم','products'=>'المنتجات','orders'=>'الطلبات','company'=>'الشركة','email'=>'البريد','phone'=>'الهاتف','tax_number'=>'الرقم الضريبي','sku'=>'SKU','price'=>'السعر','available'=>'المتاح','actions'=>'إجراءات','availability'=>'التوفر','active'=>'نشط','assignments'=>'التعيينات','tier'=>'شريحة السعر','product'=>'المنتج','unit_price'=>'سعر الوحدة','minimum_quantity'=>'الحد الأدنى']
      : ['number'=>'Order','client'=>'Client','store'=>'Store','status'=>'Status','amount'=>'Amount','created'=>'Created','code'=>'Code','name'=>'Name','products'=>'Products','orders'=>'Orders','company'=>'Company','email'=>'Email','phone'=>'Phone','tax_number'=>'Tax number','sku'=>'SKU','price'=>'Price','available'=>'Available','actions'=>'Actions','availability'=>'Availability','active'=>'Active','assignments'=>'Assignments','tier'=>'Price tier','product'=>'Product','unit_price'=>'Unit price','minimum_quantity'=>'Minimum quantity'];
    @endphp
    @if(session('status'))<div class="panel" style="border-color:#b7dfc4;background:var(--foodex-green-soft);color:var(--foodex-green-dark)">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" style="border-color:#ffd0a6;background:var(--foodex-orange-soft)"><strong>{{ app()->getLocale()==='ar'?'تعذر تنفيذ العملية':'Action could not be completed' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="panel">
      <div class="toolbar">
        <div><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><div class="muted">{{ app()->getLocale()==='ar' ? 'النطاق محصور في متاجر وقناة B2B.' : 'Scope is restricted to B2B stores and channel data.' }}</div></div>
        <nav class="links" aria-label="B2B core modules">
          @foreach(['dashboard','stores','clients','products','orders','drivers','pricing'] as $core)
          <a class="{{ $module===$core?'active':'' }}" href="{{ $core==='dashboard'?route('admin.b2b.dashboard'):route('admin.b2b.module',['module'=>$core]) }}">{{ __('admin.b2b_workspace.modules.'.$core) }}</a>
          @endforeach
        </nav>
      </div>
      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage'))
      <form method="post" action="{{ route('admin.b2b.drivers.assign') }}" class="links" style="margin-bottom:14px">
        @csrf
        <select name="driver_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر السائق':'Select driver' }}</option>@foreach($moduleData['drivers'] as $driver)<option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>@endforeach</select>
        <select name="order_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر الطلب':'Select order' }}</option>@foreach($moduleData['orders'] as $order)<option value="{{ $order['id'] }}">{{ $order['number'] }}</option>@endforeach</select>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين السائق':'Assign driver' }}</button>
      </form>
      @endif
      @if($module==='pricing' && $user->hasPermission('b2b.pricing.manage'))
      <form method="post" action="{{ route('admin.b2b.pricing.save') }}" class="links" style="margin-bottom:14px">
        @csrf
        <select name="price_tier_id" required><option value="">{{ app()->getLocale()==='ar'?'شريحة السعر':'Price tier' }}</option>@foreach($moduleData['tiers'] as $tier)<option value="{{ $tier['id'] }}">{{ $tier['name'] }}</option>@endforeach</select>
        <select name="store_id" required><option value="">{{ app()->getLocale()==='ar'?'الفرع':'Store' }}</option>@foreach($moduleData['stores'] as $store)<option value="{{ $store['id'] }}">{{ $store['name'] }}</option>@endforeach</select>
        <select name="product_id" required><option value="">{{ app()->getLocale()==='ar'?'المنتج':'Product' }}</option>@foreach($moduleData['products'] as $product)<option value="{{ $product['id'] }}">{{ $product['sku'] }} · {{ $product['name'] }}</option>@endforeach</select>
        <input name="unit_price" type="number" min="0" step="0.001" placeholder="{{ app()->getLocale()==='ar'?'سعر الوحدة':'Unit price' }}" required>
        <input name="minimum_quantity" type="number" min="0.001" step="0.001" placeholder="{{ app()->getLocale()==='ar'?'الحد الأدنى':'Minimum quantity' }}" required>
        <input type="hidden" name="is_active" value="1">
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'حفظ قاعدة السعر':'Save price rule' }}</button>
      </form>
      @endif
      @if(count($moduleData['rows']))
      <div class="table-wrap"><table class="data"><thead><tr>@foreach($moduleData['columns'] as $column)<th>{{ $labels[$column]??$column }}</th>@endforeach</tr></thead><tbody>
      @foreach($moduleData['rows'] as $row)<tr>@foreach($moduleData['columns'] as $column)<td>
        @if(in_array($column,['status','availability','active'],true) && is_bool($row[$column]))<span class="state {{ $row[$column]?'':'off' }}">{{ $row[$column]?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
        @elseif($column==='status')<span class="badge {{ $row[$column] }}">{{ $row[$column] }}</span>
        @elseif($column==='actions' && $module==='orders' && $user->hasPermission('orders.manage'))
          <form method="post" action="{{ route('admin.b2b.orders.status',['order'=>$row['_id']]) }}" class="links">
            @csrf
            <select name="status" required>
              @foreach(['confirmed','preparing','ready','out_for_delivery','delivered','failed','cancelled'] as $state)<option value="{{ $state }}">{{ $state }}</option>@endforeach
            </select>
            <input name="note" maxlength="1000" placeholder="{{ app()->getLocale()==='ar'?'ملاحظة':'Note' }}">
            <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تحديث':'Update' }}</button>
          </form>
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
