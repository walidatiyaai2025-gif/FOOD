<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'العلامات والوحدات':'Brands & Units' }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0;background:var(--foodex-background);color:var(--foodex-ink)}
.lookup-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh}
.lookup-layout aside{grid-column:2;grid-row:1;direction:rtl;background:var(--foodex-surface);border-left:1px solid var(--foodex-border);padding:var(--foodex-space-5)}
.lookup-layout main{grid-column:1;grid-row:1;direction:rtl;padding:var(--foodex-space-6);min-width:0}
html[dir=ltr] .lookup-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
html[dir=ltr] .lookup-layout aside{grid-column:1;direction:ltr;border-left:0;border-right:1px solid var(--foodex-border)}
html[dir=ltr] .lookup-layout main{grid-column:2;direction:ltr}
.header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.header h1{margin:0 0 6px}.muted{color:var(--foodex-muted)}
.card{background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:16px;box-shadow:var(--foodex-shadow-sm);margin-bottom:16px}
.toolbar,.form-grid,.inline-form{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.form-grid label,.inline-form label{display:grid;gap:5px;font-size:12px;font-weight:700}
input,select{border:1px solid var(--foodex-border);border-radius:9px;padding:9px;background:#fff;font:inherit;min-width:130px}.wide{min-width:210px}
.btn{border:1px solid var(--foodex-border);background:#fff;border-radius:9px;padding:9px 12px;text-decoration:none;color:inherit;cursor:pointer;font:inherit}.btn.primary,.tab.active{background:var(--foodex-green);color:#fff;border-color:var(--foodex-green)}.btn.danger{color:var(--foodex-red)}
.tabs{display:flex;gap:8px;margin-bottom:16px}.tab{padding:9px 14px;border:1px solid var(--foodex-border);border-radius:10px;text-decoration:none;color:inherit;background:#fff}
.notice{padding:10px 12px;border-radius:10px;margin-bottom:12px}.ok{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.err{background:#fff1f0;color:var(--foodex-red)}
.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:1040px}.table th,.table td{padding:10px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.table th{background:var(--foodex-background)}
.badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px;background:#eef2f6}.badge.on{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}.badge.off{background:#f2f4f7;color:#667085}
.actions{display:flex;gap:6px;flex-wrap:wrap}.brand-thumb{width:56px;height:56px;object-fit:contain;border:1px solid var(--foodex-border);border-radius:10px;background:#fff;padding:4px;box-sizing:border-box}.image-help{font-size:11px;color:var(--foodex-muted);max-width:240px}.support{font-size:11px;color:var(--foodex-muted);display:flex!important;align-items:center;grid-auto-flow:column}.support input{min-width:auto}
@media(max-width:1000px){.lookup-layout{grid-template-columns:1fr}.lookup-layout aside,.lookup-layout main,html[dir=ltr] .lookup-layout aside,html[dir=ltr] .lookup-layout main{grid-column:1}.lookup-layout aside{grid-row:1}.lookup-layout main{grid-row:2}}
</style>
</head>
<body>
@php
$ar=app()->getLocale()==='ar';
$scopeLabels=['global'=>$ar?'عام للمنصة':'Platform global','b2b'=>$ar?'الجملة B2B':'Wholesale / B2B','store'=>$ar?'متجر تجزئة':'Retail store'];
@endphp
<div class="lookup-layout">
<aside>@include('admin._sidebar',['navContext'=>'lookup_management'])</aside>
<main>
<div class="header"><div><h1>{{ $ar?'العلامات والوحدات':'Brands & Units' }}</h1><div class="muted">{{ $ar?'إدارة العلامات التجارية ووحدات القياس حسب صلاحية ونطاق المستخدم الحالي.':'Manage brands and units inside the current authorized business scope.' }}</div></div><a class="btn" href="{{ route('admin.index') }}">{{ $ar?'لوحة الإدارة':'Dashboard' }}</a></div>

@if(session('status'))<div class="notice ok">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice err">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if($isSuperAdmin)<div class="notice">{{ $ar?'تنبيه: تعديل بيانات نطاق متجر تجزئة يتطلب تفعيل «الدعم الصريح» داخل نفس الإجراء. هذا يحافظ على عزل المتاجر ويسجل عملية الدعم.':'Note: Retail store-scoped changes require Explicit support access on the same action. This preserves tenant isolation and audits the support operation.' }}</div>@endif

<nav class="tabs">
<a class="tab {{ $type==='brands'?'active':'' }}" href="{{ route('admin.lookups.index',['type'=>'brands']) }}">{{ $ar?'العلامات التجارية':'Brands' }}</a>
<a class="tab {{ $type==='units'?'active':'' }}" href="{{ route('admin.lookups.index',['type'=>'units']) }}">{{ $ar?'وحدات القياس':'Units of Measure' }}</a>
</nav>

<section class="card">
<form class="toolbar" method="get" action="{{ route('admin.lookups.index') }}">
<input type="hidden" name="type" value="{{ $type }}">
<label>{{ $ar?'بحث':'Search' }}<input class="wide" name="q" value="{{ $filters['q'] }}" placeholder="{{ $ar?'الاسم أو الكود':'Name or code' }}"></label>
@if($isSuperAdmin)<label>{{ $ar?'النطاق':'Scope' }}<select name="scope"><option value="all">{{ $ar?'كل النطاقات':'All scopes' }}</option>@foreach($scopeLabels as $key=>$label)<option value="{{ $key }}" @selected($filters['scope']===$key)>{{ $label }}</option>@endforeach</select></label>@endif
<label>{{ $ar?'الحالة':'Status' }}<select name="status"><option value="all">{{ $ar?'الكل':'All' }}</option><option value="active" @selected($filters['status']==='active')>{{ $ar?'نشط':'Active' }}</option><option value="inactive" @selected($filters['status']==='inactive')>{{ $ar?'غير نشط':'Inactive' }}</option></select></label>
@if($isSuperAdmin && $stores->isNotEmpty())<label>{{ $ar?'المتجر':'Store' }}<select name="store_id"><option value="">{{ $ar?'كل المتاجر المصرح بها':'All authorized stores' }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected($filters['store_id']==$store->id)>{{ $store->name }}</option>@endforeach</select></label>@elseif($isRetailScoped && $stores->count()>1)<label>{{ $ar?'المتجر الحالي':'Current store' }}<select name="store_id" onchange="this.form.submit()">@foreach($stores as $store)<option value="{{ $store->id }}" @selected($currentStoreId===$store->id)>{{ $store->name }}</option>@endforeach</select></label>@endif
<button class="btn">{{ $ar?'تصفية':'Filter' }}</button>
</form>
</section>

@if($manageableScopes!==[])
<section class="card">
<h2>{{ $ar?'إضافة قيمة جديدة':'Add lookup value' }}</h2>
<form class="form-grid" method="post" enctype="multipart/form-data" action="{{ route('admin.lookups.store',$type) }}">@csrf
@if($isSuperAdmin)
<label>{{ $ar?'النطاق':'Scope' }}<select name="scope" required>@foreach($manageableScopes as $scope)<option value="{{ $scope }}">{{ $scopeLabels[$scope] }}</option>@endforeach</select></label>
@if($stores->isNotEmpty())<label>{{ $ar?'المتجر عند اختيار Retail':'Store for Retail scope' }}<select name="store_id"><option value="">—</option>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></label>@endif
@elseif($isB2bAdmin)
<input type="hidden" name="scope" value="b2b">
@elseif($isRetailScoped)
<input type="hidden" name="scope" value="store"><input type="hidden" name="store_id" value="{{ $currentStoreId }}">
@endif
<label>{{ $ar?'الاسم بالعربية':'Arabic name' }}<input name="name_ar" placeholder="مثال: قطعة" required></label>
<label>{{ $ar?'الاسم بالإنجليزية':'English name' }}<input name="name_en" placeholder="e.g. Piece" required></label>
@if($type==='brands')<label>{{ $ar?'المعرّف النصي':'Slug' }}<input name="slug" placeholder="{{ $ar?'مثال: coca-cola':'e.g. coca-cola' }}"></label><label>{{ $ar?'صورة العلامة':'Brand image' }}<input type="file" name="brand_image" accept="image/jpeg,image/png,image/webp" required><span class="image-help">{{ $ar?'يفضل 512×512 بكسل. المسموح 256×256 إلى 2048×2048، JPG/PNG/WebP، حتى 2MB.':'Recommended 512×512 px. Allowed 256×256 to 2048×2048, JPG/PNG/WebP, max 2 MB.' }}</span></label>@else<label>{{ $ar?'الكود':'Code' }}<input name="code" placeholder="{{ $ar?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ $ar?'المنازل العشرية':'Decimal places' }}<input type="number" name="decimal_places" min="0" max="6" value="0" placeholder="0" required></label>@endif
<label><span>{{ $ar?'الحالة':'Status' }}</span><span><input type="checkbox" name="is_active" value="1" checked style="min-width:auto"> {{ $ar?'نشط':'Active' }}</span></label>
@if($isSuperAdmin)<label class="support"><input type="checkbox" name="support_access" value="1"> {{ $ar?'دخول دعم صريح عند إدارة نطاق متجر':'Explicit support access for store-scoped changes' }}</label>@endif
<button class="btn primary">{{ $ar?'إضافة':'Add' }}</button>
</form>
</section>
@endif

<section class="card table-wrap">
<table class="table">
<thead><tr>@if($type==='brands')<th>{{ $ar?'الصورة':'Image' }}</th>@endif<th>{{ $ar?'المفتاح':'Key' }}</th><th>{{ $ar?'العربية':'Arabic' }}</th><th>{{ $ar?'الإنجليزية':'English' }}</th><th>{{ $ar?'النطاق':'Scope' }}</th><th>{{ $ar?'المتجر':'Store' }}</th><th>{{ $ar?'الحالة':'Status' }}</th><th>{{ $ar?'الإجراءات':'Actions' }}</th></tr></thead>
<tbody>
@forelse($records as $record)
<tr>
@if($type==='brands')<td>@if($record->image_path)<img class="brand-thumb" src="{{ url('/'.ltrim($record->image_path,'/')) }}" alt="{{ $record->name_ar ?: $record->name_en }}">@else<span class="muted">—</span>@endif</td>@endif
<td>{{ $type==='brands'?$record->slug:$record->code }}</td>
<td>{{ $record->name_ar }}</td><td>{{ $record->name_en }}</td>
<td><span class="badge">{{ $scopeLabels[$record->scope]??$record->scope }}</span></td>
<td>{{ $record->store_name?:'—' }}</td>
<td><span class="badge {{ $record->is_active?'on':'off' }}">{{ $record->is_active?($ar?'نشط':'Active'):($ar?'غير نشط':'Inactive') }}</span></td>
<td>
@if($record->can_manage)
<form class="inline-form" method="post" enctype="multipart/form-data" action="{{ route('admin.lookups.update',['type'=>$type,'lookup'=>$record->id]) }}">@csrf @method('PATCH')
<label>{{ $ar?'العربية':'AR' }}<input name="name_ar" value="{{ $record->name_ar }}" required></label><label>{{ $ar?'الإنجليزية':'EN' }}<input name="name_en" value="{{ $record->name_en }}" required></label>
@if($type==='brands')<label>{{ $ar?'المعرّف النصي':'Slug' }}<input name="slug" value="{{ $record->slug }}" placeholder="{{ $ar?'مثال: coca-cola':'e.g. coca-cola' }}" placeholder="{{ $ar?'مثال: coca-cola':'e.g. coca-cola' }}" required></label><label>{{ $ar?'استبدال الصورة':'Replace image' }}<input type="file" name="brand_image" accept="image/jpeg,image/png,image/webp" @required(!$record->image_path)><span class="image-help">{{ $ar?'512×512 مفضل؛ 256–2048 بكسل، حتى 2MB.':'512×512 recommended; 256–2048 px, max 2 MB.' }}</span></label>@else<label>{{ $ar?'الكود':'Code' }}<input name="code" value="{{ $record->code }}" placeholder="{{ $ar?'مثال: PCS':'e.g. PCS' }}" placeholder="{{ $ar?'مثال: PCS':'e.g. PCS' }}" required></label><label>{{ $ar?'الدقة':'Precision' }}<input type="number" min="0" max="6" name="decimal_places" value="{{ $record->decimal_places }}" placeholder="0" placeholder="0" required></label>@endif
@if($isSuperAdmin)
<label>{{ $ar?'النطاق':'Scope' }}<select name="scope">@foreach($manageableScopes as $scope)<option value="{{ $scope }}" @selected($record->scope===$scope)>{{ $scopeLabels[$scope] }}</option>@endforeach</select></label>
@if($stores->isNotEmpty())<label>{{ $ar?'المتجر':'Store' }}<select name="store_id"><option value="">—</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected($record->store_id==$store->id)>{{ $store->name }}</option>@endforeach</select></label>@endif
@elseif($record->scope==='b2b')
<input type="hidden" name="scope" value="b2b">
@elseif($record->scope==='store')
<input type="hidden" name="scope" value="store"><input type="hidden" name="store_id" value="{{ $record->store_id }}">
@endif
<input type="hidden" name="is_active" value="{{ $record->is_active?1:0 }}">
@if($isSuperAdmin)<label class="support"><input type="checkbox" name="support_access" value="1"> {{ $ar?'دعم صريح عند النقل/الإدارة بنطاق متجر':'Explicit support access for store scope' }}</label>@endif
<button class="btn">{{ $ar?'حفظ':'Save' }}</button>
</form>
<div class="actions" style="margin-top:8px">
<form method="post" action="{{ route('admin.lookups.toggle',['type'=>$type,'lookup'=>$record->id]) }}">@csrf @method('PATCH') @if($isSuperAdmin && $record->scope==='store')<label class="support"><input type="checkbox" name="support_access" value="1"> {{ $ar?'دعم صريح':'Support access' }}</label>@endif<button class="btn">{{ $record->is_active?($ar?'تعطيل':'Deactivate'):($ar?'تفعيل':'Activate') }}</button></form>
<form method="post" action="{{ route('admin.lookups.destroy',['type'=>$type,'lookup'=>$record->id]) }}" onsubmit="return confirm('{{ $ar?'تأكيد الحذف الآمن؟':'Confirm safe delete?' }}')">@csrf @method('DELETE') @if($isSuperAdmin && $record->scope==='store')<label class="support"><input type="checkbox" name="support_access" value="1"> {{ $ar?'دعم صريح':'Support access' }}</label>@endif<button class="btn danger">{{ $ar?'حذف':'Delete' }}</button></form>
</div>
@else
<span class="muted">{{ $ar?'للقراءة فقط في هذا النطاق':'Read-only in this scope' }}</span>
@endif
</td>
</tr>
@empty<tr><td colspan="{{ $type==='brands'?8:7 }}">{{ $ar?'لا توجد قيم مطابقة.':'No matching lookup values.' }}</td></tr>@endforelse
</tbody></table>
<div>{{ $records->links() }}</div>
</section>
</main></div>
</body></html>
