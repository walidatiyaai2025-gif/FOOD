<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('admin.b2b_workspace.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
*{box-sizing:border-box}body{margin:0}.layout{display:grid;grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr);min-height:100vh;background:var(--foodex-background)}.sidebar{padding:var(--foodex-space-5);position:sticky;inset-block-start:0;height:100vh}.main{width:100%;max-width:none!important;padding:var(--foodex-space-8)}.headline{margin-bottom:var(--foodex-space-6)}.headline h1{margin:var(--foodex-space-1) 0 0}.headline .muted{max-width:760px}.muted{color:var(--foodex-muted)}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}.metric-card{position:relative;overflow:hidden;padding:var(--foodex-space-5)!important;min-height:122px}.metric-card:before{content:"";position:absolute;inset-inline-start:0;inset-block:0;width:4px;background:var(--foodex-green)}.metric-card:nth-child(2n):before{background:var(--foodex-orange)}.metric-card strong{font-size:var(--foodex-text-xs);color:var(--foodex-muted);font-weight:var(--foodex-font-weight-medium)}.metric-card p{font-family:var(--foodex-font-en);font-size:clamp(1.6rem,2.3vw,2rem);font-weight:var(--foodex-font-weight-bold);line-height:1.1;margin:var(--foodex-space-3) 0 0;color:var(--foodex-ink)}.panel{margin-top:var(--foodex-space-4);padding:var(--foodex-space-5)}.workspace-panel{box-shadow:var(--foodex-shadow)}.toolbar{display:flex;justify-content:space-between;gap:var(--foodex-space-4);align-items:flex-start;margin-bottom:var(--foodex-space-4)}.toolbar>div:first-child{max-width:520px}.links{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center}.workspace-tabs{justify-content:flex-end}.links a{min-height:var(--foodex-control-height);display:inline-flex;align-items:center;padding:0 var(--foodex-space-3);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);text-decoration:none;background:var(--foodex-surface);color:var(--foodex-ink)}.links a:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:#c9e7d3}.links a.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green);box-shadow:0 8px 20px rgba(21,138,58,.14)}.table-wrap{overflow:auto;border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-sm)}.data{min-width:760px}.data th,.data td{vertical-align:middle}.state{display:inline-flex;align-items:center;gap:6px;font-weight:var(--foodex-font-weight-medium)}.state:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--foodex-green)}.state.off:before{background:#98a2b3}.badge{display:inline-flex;align-items:center;min-height:26px;border-radius:999px;padding:3px 9px;background:var(--foodex-orange-soft);color:var(--foodex-orange);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}.badge.active,.badge.delivered{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.suspended,.badge.denied,.badge.cancelled{background:#fff0f0;color:var(--foodex-red)}.workspace-inline-form{padding:var(--foodex-space-4);margin-bottom:var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}.workspace-inline-form input,.workspace-inline-form select{min-width:150px}.empty-state{display:grid;place-items:center;min-height:160px;text-align:center;border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;padding:var(--foodex-space-6);color:var(--foodex-muted)}@media(max-width:1200px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.toolbar{flex-direction:column}.workspace-tabs{justify-content:flex-start}}@media(max-width:767px){.layout{grid-template-columns:1fr}.sidebar{position:relative;height:auto}.main{padding:var(--foodex-space-4)!important}.cards{grid-template-columns:1fr}.toolbar{align-items:stretch}.links a{flex:1 1 auto;justify-content:center}.workspace-inline-form{align-items:stretch}.workspace-inline-form input,.workspace-inline-form select,.workspace-inline-form button{width:100%}.data{min-width:680px}}
</style>
</head>
<body>
<div class="layout b2b-premium-shell" data-b2b-premium="v1">
<aside class="sidebar">@include('admin._sidebar')</aside>
<main class="main foodex-admin-page">
    <div class="headline foodex-page-header">
        <div>
            <a class="muted" href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a>
            <h1>{{ __('admin.b2b_workspace.modules.'.$module) }}</h1>
            <div class="muted">{{ app()->getLocale()==='ar' ? 'FOODEX · إدارة الجملة ببيانات مباشرة من النظام' : 'FOODEX · B2B management with live server data' }}</div>
        </div>
    </div>
    <section class="cards" aria-label="{{ app()->getLocale()==='ar' ? 'مؤشرات إدارة الجملة' : 'B2B management metrics' }}">
        @foreach($counts as $key=>$value)
        <article class="card foodex-card metric-card"><strong>{{ __('admin.b2b_workspace.modules.'.$key) }}</strong><p>{{ number_format($value) }}</p></article>
        @endforeach
    </section>

    @if($moduleData)
    @php
      $labels=app()->getLocale()==='ar'
      ? ['number'=>'رقم الطلب','client'=>'العميل','store'=>'الفرع','status'=>'الحالة','amount'=>'الإجمالي','created'=>'الإنشاء','code'=>'الكود','name'=>'الاسم','products'=>'المنتجات','orders'=>'الطلبات','company'=>'الشركة','email'=>'البريد','phone'=>'الهاتف','tax_number'=>'الرقم الضريبي','sku'=>'SKU','price'=>'السعر','available'=>'المتاح','actions'=>'إجراءات','availability'=>'التوفر','active'=>'نشط','assignments'=>'التعيينات','tier'=>'شريحة السعر','product'=>'المنتج','unit_price'=>'سعر الوحدة','minimum_quantity'=>'الحد الأدنى','revenue'=>'الإيراد','average'=>'متوسط الطلب','setting'=>'الإعداد','value'=>'القيمة']
      : ['number'=>'Order','client'=>'Client','store'=>'Store','status'=>'Status','amount'=>'Amount','created'=>'Created','code'=>'Code','name'=>'Name','products'=>'Products','orders'=>'Orders','company'=>'Company','email'=>'Email','phone'=>'Phone','tax_number'=>'Tax number','sku'=>'SKU','price'=>'Price','available'=>'Available','actions'=>'Actions','availability'=>'Availability','active'=>'Active','assignments'=>'Assignments','tier'=>'Price tier','product'=>'Product','unit_price'=>'Unit price','minimum_quantity'=>'Minimum quantity','revenue'=>'Revenue','average'=>'Average order','setting'=>'Setting','value'=>'Value'];
    @endphp
    @if(session('status'))<div class="panel" style="border-color:#b7dfc4;background:var(--foodex-green-soft);color:var(--foodex-green-dark)">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" style="border-color:#ffd0a6;background:var(--foodex-orange-soft)"><strong>{{ app()->getLocale()==='ar'?'تعذر تنفيذ العملية':'Action could not be completed' }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="panel workspace-panel">
      <div class="toolbar">
        <div><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><div class="muted">{{ app()->getLocale()==='ar' ? 'النطاق محصور في متاجر وقناة B2B.' : 'Scope is restricted to B2B stores and channel data.' }}</div></div>
        <nav class="links workspace-tabs" aria-label="B2B core modules">
          @foreach(['dashboard','stores','clients','products','orders','drivers','pricing','reports','settings'] as $core)
          <a class="{{ $module===$core?'active':'' }}" href="{{ $core==='dashboard'?route('admin.b2b.dashboard'):route('admin.b2b.module',['module'=>$core]) }}">{{ __('admin.b2b_workspace.modules.'.$core) }}</a>
          @endforeach
        </nav>
      </div>
      @if($module==='settings' && !empty($moduleData['actions']))
      <div class="links workspace-inline-form">
        @foreach($moduleData['actions'] as $action)<a class="foodex-primary" href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach
      </div>
      @endif
      @if($module==='drivers' && $user->hasPermission('drivers.b2b.manage'))
      <form method="post" action="{{ route('admin.b2b.drivers.assign') }}" class="links workspace-inline-form">
        @csrf
        <select name="driver_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر السائق':'Select driver' }}</option>@foreach($moduleData['drivers'] as $driver)<option value="{{ $driver['id'] }}">{{ $driver['name'] }}</option>@endforeach</select>
        <select name="order_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر الطلب':'Select order' }}</option>@foreach($moduleData['orders'] as $order)<option value="{{ $order['id'] }}">{{ $order['number'] }}</option>@endforeach</select>
        <button class="foodex-primary" type="submit">{{ app()->getLocale()==='ar'?'تعيين السائق':'Assign driver' }}</button>
      </form>
      @endif
      @if($module==='pricing' && $user->hasPermission('b2b.pricing.manage'))
      <form method="post" action="{{ route('admin.b2b.pricing.save') }}" class="links workspace-inline-form">
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
      <div class="table-wrap"><table class="data foodex-table"><thead><tr>@foreach($moduleData['columns'] as $column)<th>{{ $labels[$column]??$column }}</th>@endforeach</tr></thead><tbody>
      @foreach($moduleData['rows'] as $row)<tr>@foreach($moduleData['columns'] as $column)<td>
        @if(in_array($column,['status','availability','active'],true) && is_bool($row[$column]))<span class="state {{ $row[$column]?'':'off' }}">{{ $row[$column]?(app()->getLocale()==='ar'?'نشط':'Active'):(app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
        @elseif($column==='status')<span class="badge {{ $row[$column] }}">{{ $row[$column] }}</span>
        @elseif($column==='actions' && $module==='reports')
          <div class="links">@foreach($row['actions'] as $action)<a href="{{ $action['url'] }}">{{ $action['label'] }}</a>@endforeach</div>
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
      <div class="empty-state" role="status">{{ app()->getLocale()==='ar' ? 'لا توجد بيانات متاحة في هذا القسم.' : 'No records are available in this section.' }}</div>
      @endif
    </section>
    @else
    <section class="panel workspace-panel"><strong>{{ __('admin.b2b_workspace.authoritative') }}</strong><p class="muted">{{ __('admin.b2b_workspace.empty_hint') }}</p></section>
    @endif
</main>
</div>
</body>
</html>
