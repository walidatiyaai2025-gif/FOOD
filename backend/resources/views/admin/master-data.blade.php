<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }} · FOODEX</title>
    @include('admin._brand-components')
    <style>
        body{margin:0}
        .master-main{min-width:0;width:100%;max-width:none!important}
        .master-head{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-5)}
        .master-head h1{margin:0;font-size:clamp(1.55rem,2.2vw,var(--foodex-text-2xl));line-height:var(--foodex-leading-tight)}
        .master-head p{margin:6px 0 0;color:var(--foodex-muted)}
        .master-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:var(--foodex-space-5)}
        .master-tabs a{min-height:40px;display:inline-flex;align-items:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:0 13px;background:var(--foodex-surface);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);text-decoration:none;color:var(--foodex-ink)}
        .master-tabs a.active{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff}
        .master-grid{display:grid;grid-template-columns:minmax(320px,.8fr) minmax(0,1.8fr);gap:var(--foodex-space-5);align-items:start}
        .editor,.records{padding:var(--foodex-space-5)!important}
        .editor{position:sticky;top:var(--foodex-space-4)}
        .editor h2,.records h2{margin:0 0 var(--foodex-space-4);font-size:var(--foodex-text-lg)}
        .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--foodex-space-3)}
        .field-grid .span-2{grid-column:1/-1}
        .field-grid label{display:grid;gap:6px;font-weight:var(--foodex-font-weight-medium);font-size:var(--foodex-text-xs)}
        .field-grid textarea{min-height:95px;padding-block:10px;resize:vertical}
        .check-row{display:flex!important;align-items:center;gap:8px;min-height:44px}
        .check-row input{width:18px;height:18px}
        .form-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:var(--foodex-space-4)}
        .ghost{min-height:44px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:0 14px;background:var(--foodex-surface);text-decoration:none;color:var(--foodex-ink);font-weight:var(--foodex-font-weight-bold)}
        .danger-button{min-height:36px;border:1px solid #f4bcbc;border-radius:var(--foodex-radius-control);background:#fff5f5;color:var(--foodex-red);padding:0 11px;font:inherit;font-weight:var(--foodex-font-weight-bold);cursor:pointer}
        .edit-link{min-height:36px;display:inline-flex;align-items:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:0 11px;background:var(--foodex-surface);text-decoration:none;color:var(--foodex-green-dark);font-weight:var(--foodex-font-weight-bold)}
        .row-actions{display:flex;flex-wrap:wrap;gap:6px}
        .records-wrap{overflow:auto;border-radius:var(--foodex-radius-md)}
        .records table{min-width:760px}
        .records th:last-child,.records td:last-child{width:150px}
        .notice-block{padding:12px 14px;border-radius:var(--foodex-radius-md);border:1px solid var(--foodex-border);margin-bottom:var(--foodex-space-4)}
        .help{color:var(--foodex-muted);font-size:var(--foodex-text-xs);margin-top:6px}
        .empty-records{padding:var(--foodex-space-7);text-align:center;color:var(--foodex-muted);border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md)}
        @media(max-width:1100px){.master-grid{grid-template-columns:1fr}.editor{position:static}}
        @media(max-width:620px){.field-grid{grid-template-columns:1fr}.field-grid .span-2{grid-column:auto}.master-head{flex-direction:column}}
    </style>
</head>
<body>
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main master-main">
        <div class="master-head">
            <div>
                <p><a href="{{ route('admin.index') }}">{{ __('admin.overview') }}</a> / {{ $title }}</p>
                <h1>{{ $title }}</h1>
                <p>{{ app()->getLocale()==='ar' ? 'إضافة وتعديل وحذف البيانات الأساسية من مكان واحد مع احترام الصلاحيات والارتباطات التشغيلية.' : 'Create, edit and remove master data from one place while preserving authorization and operational references.' }}</p>
            </div>
            @if($canCreate)
                <a class="foodex-action-primary" href="{{ route('admin.manage.'.$resource) }}#editor">{{ app()->getLocale()==='ar' ? '＋ إضافة جديد' : '+ Add new' }}</a>
            @endif
        </div>

        <nav class="master-tabs" aria-label="{{ app()->getLocale()==='ar' ? 'إدارة البيانات' : 'Data management' }}">
            @foreach($resourceLinks as $item)
                <a class="{{ $item['key']===$resource ? 'active' : '' }}" href="{{ route($item['route']) }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        @if(session('status'))
            <div class="foodex-alert foodex-alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="foodex-alert foodex-alert-error" role="alert">
                <strong>{{ app()->getLocale()==='ar' ? 'تعذر تنفيذ العملية' : 'Action could not be completed' }}</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="master-grid">
            <section id="editor" class="editor foodex-card">
                <h2>{{ $edit ? (app()->getLocale()==='ar' ? 'تعديل السجل' : 'Edit record') : (app()->getLocale()==='ar' ? 'إضافة سجل جديد' : 'Add new record') }}</h2>
                @if(!$canCreate && !$edit)
                    <div class="notice-block">{{ app()->getLocale()==='ar' ? 'لديك صلاحية عرض فقط في هذا القسم.' : 'You have read-only access to this section.' }}</div>
                @else
                <form method="post" action="{{ $edit ? route('admin.manage.update',['resource'=>$resource,'id'=>$edit['id']]) : route('admin.manage.store',['resource'=>$resource]) }}">
                    @csrf
                    @if($edit) @method('PUT') @endif
                    <div class="field-grid">
                        @if($resource==='categories')
                            <label class="span-2">{{ app()->getLocale()==='ar'?'اسم التصنيف':'Category name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'Slug (اختياري)':'Slug (optional)' }}<input name="slug" value="{{ old('slug',$edit['slug']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'التصنيف الأب':'Parent category' }}
                                <select name="parent_id"><option value="">{{ app()->getLocale()==='ar'?'بدون':'None' }}</option>
                                    @foreach($options['categories'] as $category)
                                        @if(!$edit || (int)$category->id!==(int)$edit['id'])<option value="{{ $category->id }}" @selected((string)old('parent_id',$edit['parent_id']??'')===(string)$category->id)>{{ $category->name }}</option>@endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        @elseif($resource==='brands')
                            <label class="span-2">{{ app()->getLocale()==='ar'?'اسم العلامة التجارية':'Brand name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label class="span-2">Slug<input name="slug" value="{{ old('slug',$edit['slug']??'') }}"></label>
                        @elseif($resource==='units')
                            <label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" value="{{ old('code',$edit['code']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'الخانات العشرية':'Decimal places' }}<input type="number" min="0" max="6" name="decimal_places" value="{{ old('decimal_places',$edit['decimal_places']??0) }}" required></label>
                        @elseif($resource==='products')
                            @if(count($options['units'])===0)
                                <div class="notice-block span-2">{{ app()->getLocale()==='ar'?'أضف وحدة قياس أولاً قبل إنشاء منتج.':'Add a unit of measure before creating a product.' }} <a href="{{ route('admin.manage.units') }}">{{ app()->getLocale()==='ar'?'إدارة الوحدات':'Manage units' }}</a></div>
                            @endif
                            <label>{{ app()->getLocale()==='ar'?'SKU':'SKU' }}<input name="sku" value="{{ old('sku',$edit['sku']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'اسم المنتج':'Product name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'التصنيف':'Category' }}<select name="category_id"><option value="">{{ app()->getLocale()==='ar'?'بدون تصنيف':'Uncategorized' }}</option>@foreach($options['categories'] as $category)<option value="{{ $category->id }}" @selected((string)old('category_id',$edit['category_id']??'')===(string)$category->id)>{{ $category->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'العلامة التجارية':'Brand' }}<select name="brand_id"><option value="">{{ app()->getLocale()==='ar'?'بدون':'None' }}</option>@foreach($options['brands'] as $brand)<option value="{{ $brand->id }}" @selected((string)old('brand_id',$edit['brand_id']??'')===(string)$brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'الوحدة':'Unit' }}<select name="unit_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر الوحدة':'Select unit' }}</option>@foreach($options['units'] as $unit)<option value="{{ $unit->id }}" @selected((string)old('unit_id',$edit['unit_id']??'')===(string)$unit->id)>{{ $unit->code }} · {{ $unit->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'إسناد/تحديث متجر':'Assign/update store' }}<select name="store_id"><option value="">{{ app()->getLocale()==='ar'?'بدون إسناد':'No store assignment' }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)old('store_id',$edit['store_id']??'')===(string)$store->id)>{{ $store->code }} · {{ $store->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'السعر لهذا المتجر':'Store price' }}<input type="number" min="0" step="0.001" name="price" value="{{ old('price',$edit['price']??'') }}"></label>
                            <label class="check-row"><input type="hidden" name="listing_active" value="0"><input type="checkbox" name="listing_active" value="1" @checked((bool)old('listing_active',$edit['listing_active']??true))> {{ app()->getLocale()==='ar'?'متاح في المتجر':'Active listing' }}</label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'الوصف':'Description' }}<textarea name="description">{{ old('description',$edit['description']??'') }}</textarea></label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'المنتج نشط':'Product active' }}</label>
                        @elseif($resource==='stores')
                            <label>{{ app()->getLocale()==='ar'?'نوع المتجر':'Store type' }}<select name="store_type_id" required><option value="">{{ app()->getLocale()==='ar'?'اختر النوع':'Select type' }}</option>@foreach($options['store_types'] as $type)<option value="{{ $type->id }}" @selected((string)old('store_type_id',$edit['store_type_id']??'')===(string)$type->id)>{{ $type->code }} · {{ $type->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" value="{{ old('code',$edit['code']??'') }}" required></label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'اسم المتجر':'Store name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        @elseif($resource==='warehouses')
                            <label>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}<select name="store_id"><option value="">{{ app()->getLocale()==='ar'?'مخزن عام':'Global warehouse' }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)old('store_id',$edit['store_id']??'')===(string)$store->id)>{{ $store->code }} · {{ $store->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" value="{{ old('code',$edit['code']??'') }}" required></label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'اسم المخزن':'Warehouse name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        @elseif($resource==='customers')
                            <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="type" required><option value="b2c" @selected(old('type',$edit['type']??'b2c')==='b2c')>B2C</option><option value="b2b" @selected(old('type',$edit['type']??'')==='b2b')>B2B</option></select></label>
                            <label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'الهاتف':'Phone' }}<input name="phone" value="{{ old('phone',$edit['phone']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'البريد':'Email' }}<input type="email" name="email" value="{{ old('email',$edit['email']??'') }}"></label>
                        @elseif($resource==='b2b-clients')
                            <label>{{ app()->getLocale()==='ar'?'اسم الشركة':'Company name' }}<input name="company_name" value="{{ old('company_name',$edit['company_name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'اسم المسؤول':'Contact name' }}<input name="contact_name" value="{{ old('contact_name',$edit['contact_name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'البريد':'Email' }}<input type="email" name="contact_email" value="{{ old('contact_email',$edit['contact_email']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'الهاتف':'Phone' }}<input name="contact_phone" value="{{ old('contact_phone',$edit['contact_phone']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'شريحة السعر':'Price tier' }}<select name="price_tier_id"><option value="">{{ app()->getLocale()==='ar'?'بدون':'None' }}</option>@foreach($options['price_tiers'] as $tier)<option value="{{ $tier->id }}" @selected((string)old('price_tier_id',$edit['price_tier_id']??'')===(string)$tier->id)>{{ $tier->code }} · {{ $tier->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'الحالة':'Status' }}<select name="status" required>@foreach(['active','inactive','suspended'] as $status)<option value="{{ $status }}" @selected(old('status',$edit['status']??'active')===$status)>{{ $status }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'الرقم الضريبي':'Tax number' }}<input name="tax_number" value="{{ old('tax_number',$edit['tax_number']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'حد الائتمان':'Credit limit' }}<input type="number" min="0" step="0.001" name="credit_limit" value="{{ old('credit_limit',$edit['credit_limit']??0) }}" required></label>
                        @elseif($resource==='price-tiers')
                            <label>{{ app()->getLocale()==='ar'?'الكود':'Code' }}<input name="code" value="{{ old('code',$edit['code']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'الاسم':'Name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'الأولوية':'Priority' }}<input type="number" min="0" max="9999" name="priority" value="{{ old('priority',$edit['priority']??0) }}" required></label>
                        @elseif($resource==='promotions')
                            <label>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}<select name="store_id"><option value="">{{ app()->getLocale()==='ar'?'كل المتاجر':'All stores' }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)old('store_id',$edit['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'اسم العرض':'Promotion name' }}<input name="name" value="{{ old('name',$edit['name']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'النوع':'Type' }}<select name="type" required>@foreach(['percentage','fixed','free_delivery'] as $type)<option value="{{ $type }}" @selected(old('type',$edit['type']??'percentage')===$type)>{{ $type }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'القيمة':'Value' }}<input type="number" min="0" step="0.001" name="value" value="{{ old('value',$edit['value']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'من':'Starts at' }}<input type="datetime-local" name="starts_at" value="{{ old('starts_at',isset($edit['starts_at']) && $edit['starts_at'] ? \Carbon\Carbon::parse($edit['starts_at'])->format('Y-m-d\\TH:i') : '') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'إلى':'Ends at' }}<input type="datetime-local" name="ends_at" value="{{ old('ends_at',isset($edit['ends_at']) && $edit['ends_at'] ? \Carbon\Carbon::parse($edit['ends_at'])->format('Y-m-d\\TH:i') : '') }}"></label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        @elseif($resource==='banners')
                            <label>{{ app()->getLocale()==='ar'?'المتجر':'Store' }}<select name="store_id"><option value="">{{ app()->getLocale()==='ar'?'كل المتاجر':'All stores' }}</option>@foreach($options['stores'] as $store)<option value="{{ $store->id }}" @selected((string)old('store_id',$edit['store_id']??'')===(string)$store->id)>{{ $store->name }}</option>@endforeach</select></label>
                            <label>{{ app()->getLocale()==='ar'?'العنوان':'Title' }}<input name="title" value="{{ old('title',$edit['title']??'') }}" required></label>
                            <label class="span-2">{{ app()->getLocale()==='ar'?'مسار الصورة أو الرابط':'Image path or URL' }}<input name="image_path" value="{{ old('image_path',$edit['image_path']??'') }}" required></label>
                            <label>{{ app()->getLocale()==='ar'?'الرابط المستهدف':'Target URL' }}<input name="target_url" value="{{ old('target_url',$edit['target_url']??'') }}"></label>
                            <label>{{ app()->getLocale()==='ar'?'الترتيب':'Sort order' }}<input type="number" min="0" name="sort_order" value="{{ old('sort_order',$edit['sort_order']??0) }}" required></label>
                            <label class="check-row span-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked((bool)old('is_active',$edit['is_active']??true))> {{ app()->getLocale()==='ar'?'نشط':'Active' }}</label>
                        @endif
                    </div>
                    <div class="form-actions">
                        <button class="foodex-primary" type="submit">{{ $edit ? (app()->getLocale()==='ar'?'حفظ التعديلات':'Save changes') : (app()->getLocale()==='ar'?'إضافة':'Create') }}</button>
                        @if($edit)<a class="ghost" href="{{ route('admin.manage.'.$resource) }}#editor">{{ app()->getLocale()==='ar'?'إلغاء التعديل':'Cancel edit' }}</a>@endif
                    </div>
                </form>
                @endif
            </section>

            <section class="records foodex-card">
                <h2>{{ app()->getLocale()==='ar' ? 'السجلات الحالية' : 'Current records' }}</h2>
                @if(count($rows))
                    <div class="records-wrap">
                        <table class="foodex-table">
                            <thead><tr>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach<th>{{ app()->getLocale()==='ar'?'إجراءات':'Actions' }}</th></tr></thead>
                            <tbody>
                            @foreach($rows as $row)
                                <tr>
                                    @foreach(array_keys($columns) as $key)
                                        <td>
                                            @if($key==='status' && is_bool($row[$key]))
                                                <span class="badge {{ $row[$key]?'active':'' }}">{{ $row[$key] ? (app()->getLocale()==='ar'?'نشط':'Active') : (app()->getLocale()==='ar'?'غير نشط':'Inactive') }}</span>
                                            @else
                                                {{ $row[$key] }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        <div class="row-actions">
                                            @if($canEdit)<a class="edit-link" href="{{ route('admin.manage.'.$resource,['edit'=>$row['id']]) }}#editor">{{ app()->getLocale()==='ar'?'تعديل':'Edit' }}</a>@endif
                                            @if($canDelete)
                                                <form method="post" action="{{ route('admin.manage.destroy',['resource'=>$resource,'id'=>$row['id']]) }}" onsubmit="return confirm(@js(app()->getLocale()==='ar'?'هل تريد حذف هذا السجل؟':'Delete this record?'))">
                                                    @csrf @method('DELETE')
                                                    <button class="danger-button" type="submit">{{ app()->getLocale()==='ar'?'حذف':'Delete' }}</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty-records">{{ app()->getLocale()==='ar' ? 'لا توجد سجلات بعد. استخدم نموذج الإضافة لبدء الإدارة.' : 'No records yet. Use the create form to start managing this section.' }}</div>
                @endif
            </section>
        </div>
    </main>
</div>
</body>
</html>
