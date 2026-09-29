<!doctype html>
<html lang="{{ str_replace('_','-',app()->getLocale()) }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'إدارة متاجر التجزئة':'Retail Store Provisioning' }} · FOODEX</title>
@include('admin._brand-components')
<style>
.store-shell{display:grid;gap:var(--foodex-space-5)}.store-panel{padding:var(--foodex-space-5)}
.store-head{display:flex;gap:var(--foodex-space-4);align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.store-head h2,.store-card h3{margin:0}.store-head p{margin:6px 0 0;color:var(--foodex-muted)}
.store-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--foodex-space-4)}
.store-form-grid label{display:grid;gap:7px;font-weight:700}.store-form-grid .wide{grid-column:1/-1}
.store-card{padding:0;overflow:hidden}
.store-card-top{display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap}
.store-accordion{border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm);margin-top:12px}
.store-accordion summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;user-select:none;transition:background .18s ease}
.store-accordion summary::-webkit-details-marker{display:none}
.store-accordion summary:hover{background:var(--foodex-green-soft)}
.store-accordion[open] summary{background:linear-gradient(180deg,var(--foodex-green-soft),#fff)}
.store-accordion-summary-main{display:flex;align-items:center;gap:12px;min-width:0}
.store-accordion-summary-text{min-width:0}
.store-accordion-chevron{width:34px;height:34px;border-radius:999px;display:grid;place-items:center;border:1px solid var(--foodex-border);background:#fff;color:var(--foodex-green-dark);font-size:18px;font-weight:900;transition:transform .18s ease}
.store-accordion[open] .store-accordion-chevron{transform:rotate(180deg)}
.store-accordion-body{padding:var(--foodex-space-5);display:grid;gap:var(--foodex-space-4);border-top:1px solid var(--foodex-border)}
.store-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.store-assignments{display:grid;gap:8px}
.store-assignment{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:#fbfcfd;flex-wrap:wrap}
.store-inline-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.store-search{display:flex;gap:8px;flex-wrap:wrap;align-items:end}
.manager-mode-panel{padding:var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}.store-logo{width:64px;height:64px;border-radius:14px;object-fit:cover;border:1px solid var(--foodex-border);background:#fff}.store-logo-placeholder{width:64px;height:64px;border-radius:14px;display:grid;place-items:center;border:1px dashed var(--foodex-border);background:#f8fafc;font-size:28px}
@media(max-width:720px){.store-panel{padding:var(--foodex-space-4)}.store-accordion summary{padding:14px}.store-accordion-body{padding:var(--foodex-space-4)}.store-logo,.store-logo-placeholder{width:52px;height:52px}.store-accordion-chevron{width:30px;height:30px}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
    <aside class="sidebar">@include('admin._sidebar')</aside>
    <main class="foodex-admin-main foodex-admin-page">
        <header class="foodex-page-header">
            <div>
                <span class="foodex-subtitle">FOODEX · SUPER_ADMIN</span>
                <h1>{{ $ar?'إدارة متاجر التجزئة':'Retail Store Provisioning' }}</h1>
                <p>{{ $ar?'إنشاء متاجر التجزئة وتعيين المدراء والأدوار والدخول للدعم من شاشة واحدة منظمة.':'Provision retail stores, assign managers and roles, and enter support context from one organized control plane.' }}</p>
            </div>
            @include('admin._live-notifications',['user'=>auth()->user()])
        </header>

        <div class="store-shell">
            <section class="foodex-card store-panel">
                <div class="store-head">
                    <div><h2>{{ $ar?'إضافة متجر تجزئة جديد':'Add a retail store' }}</h2><p>{{ $ar?'أكمل بيانات المتجر أولًا ثم اختر أو أنشئ مدير المتجر.':'Complete store details first, then select or create the store manager.' }}</p></div>
                </div>

                <form method="post" action="{{ route('admin.retail-stores.store') }}" enctype="multipart/form-data" id="retail-provision-form" data-foodex-stepper>
                    @csrf
                    <div class="foodex-step-tabs" role="tablist" aria-label="{{ $ar?'خطوات إضافة المتجر':'Store provisioning steps' }}">
                        <button class="foodex-step-tab" type="button" role="tab" aria-selected="true" data-step-target="store-details">
                            @include('admin._premium-icon',['name'=>'storefront']) <span>{{ $ar?'1. بيانات المتجر':'1. Store details' }}</span>
                        </button>
                        <button class="foodex-step-tab" type="button" role="tab" aria-selected="false" data-step-target="store-manager">
                            @include('admin._premium-icon',['name'=>'customers']) <span>{{ $ar?'2. المدير والصلاحية':'2. Manager & access' }}</span>
                        </button>
                    </div>

                    <section class="foodex-step-panel" data-step-panel="store-details">
                        <div class="store-form-grid">
                            <label>{{ $ar?'كود المتجر':'Store code' }}
                                <input name="code" value="{{ old('code') }}" required maxlength="80" pattern="[A-Za-z0-9_-]+" placeholder="{{ $ar?'مثال: CAIRO-01':'e.g. CAIRO-01' }}">
                                <small class="foodex-file-help">{{ $ar?'حروف إنجليزية وأرقام وشرطة فقط؛ لا يتغير تلقائيًا بعد الاستخدام.':'Letters, numbers, dash and underscore only.' }}</small>
                            </label>
                            <label>{{ $ar?'اسم المتجر الظاهر':'Store display name' }}
                                <input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="{{ $ar?'مثال: متجر مدينة نصر':'e.g. Nasr City Store' }}">
                            </label>
                            <label>{{ $ar?'شعار المتجر':'Store logo' }}
                                <input name="logo" type="file" accept="image/jpeg,image/png,image/webp" required>
                                <small class="foodex-file-help">{{ $ar?'مطلوب عند إنشاء المتجر · JPG / PNG / WebP حتى 5MB':'Required when creating the store · JPG / PNG / WebP up to 5MB' }}</small>
                            </label>
                            <label>{{ $ar?'شريحة تسعير طلبات الجملة':'Wholesale order price tier' }}
                                <select name="price_tier_id" required>
                                    <option value="">{{ $ar?'اختر شريحة التسعير':'Select price tier' }}</option>
                                    @foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((string)old('price_tier_id')===(string)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach
                                </select>
                                <small class="foodex-file-help">{{ $ar?'هذه الشريحة ستُطبق تلقائيًا عندما يطلب متجر التجزئة من متجر الجملة الرئيسي.':'This tier is applied automatically when the Retail store purchases from the main Wholesale operation.' }}</small>
                            </label>
                            <label><span>{{ $ar?'حالة المتجر':'Store status' }}</span>
                                <span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active','1')==='1')> {{ $ar?'نشط ومتاح للإدارة':'Active and manageable' }}</span>
                            </label>
                            <label><span>{{ $ar?'الحملات الإعلانية':'Advertising campaigns' }}</span>
                                <span><input type="hidden" name="advertising_enabled" value="0"><input type="checkbox" name="advertising_enabled" value="1" @checked(old('advertising_enabled','1')==='1')> {{ $ar?'السماح للمتجر باستخدام الحملات الإعلانية':'Allow this store to use advertising campaigns' }}</span>
                            </label>
                            <label><span>{{ $ar?'الكوبونات':'Coupons' }}</span>
                                <span><input type="hidden" name="coupons_enabled" value="0"><input type="checkbox" name="coupons_enabled" value="1" @checked(old('coupons_enabled','1')==='1')> {{ $ar?'السماح للمتجر بإنشاء واستخدام الكوبونات':'Allow this store to create and use coupons' }}</span>
                            </label>
                        </div>
                        <div class="foodex-step-actions">
                            <button class="foodex-action-primary" type="button" data-step-next="store-manager">{{ $ar?'التالي: المدير':'Next: Manager' }} →</button>
                        </div>
                    </section>

                    <section class="foodex-step-panel" data-step-panel="store-manager" hidden>
                        <div class="store-form-grid">
                            <label>{{ $ar?'طريقة تعيين المدير':'Manager assignment' }}
                                <select name="manager_mode" id="manager_mode" required>
                                    <option value="existing" @selected(old('manager_mode','existing')==='existing')>{{ $ar?'اختيار مستخدم موجود':'Select existing user' }}</option>
                                    <option value="new" @selected(old('manager_mode')==='new')>{{ $ar?'إنشاء مدير جديد':'Create new manager' }}</option>
                                </select>
                            </label>
                            <div class="manager-mode-panel wide" data-manager-panel="existing">
                                <label>{{ $ar?'المستخدم الذي سيصبح مدير المتجر':'Existing manager user' }}
                                    <select name="manager_user_id">
                                        <option value="">{{ $ar?'اختر المستخدم':'Select user' }}</option>
                                        @foreach($users as $managedUser)<option value="{{ $managedUser->id }}" @selected((string)old('manager_user_id')===(string)$managedUser->id)>{{ $managedUser->name }} · {{ $managedUser->email }}</option>@endforeach
                                    </select>
                                </label>
                            </div>
                            <div class="manager-mode-panel wide" data-manager-panel="new" hidden>
                                <div class="store-form-grid">
                                    <label>{{ $ar?'اسم المدير الجديد':'New manager name' }}<input name="manager_name" value="{{ old('manager_name') }}" maxlength="255" placeholder="{{ $ar?'الاسم الكامل':'Full name' }}"></label>
                                    <label>{{ $ar?'البريد الإلكتروني للمدير':'Manager email' }}<input name="manager_email" type="email" value="{{ old('manager_email') }}" maxlength="255" placeholder="manager@example.com"></label>
                                    <label>{{ $ar?'كلمة مرور المدير':'Manager password' }}<input name="manager_password" type="password" minlength="8" maxlength="255" placeholder="{{ $ar?'8 أحرف على الأقل':'At least 8 characters' }}"></label>
                                </div>
                            </div>
                        </div>
                        <div class="foodex-step-actions">
                            <button class="foodex-action-secondary button secondary" type="button" data-step-next="store-details">← {{ $ar?'السابق':'Back' }}</button>
                            <button class="foodex-action-primary" type="submit">{{ $ar?'إنشاء المتجر وتعيين المدير':'Create store & assign manager' }}</button>
                        </div>
                    </section>
                </form>
            </section>

            <section class="foodex-card store-panel">
                <div class="store-head">
                    <div><h2>{{ $ar?'متاجر التجزئة':'Retail Stores' }}</h2><p>{{ $ar?'تعديل البيانات الأساسية وإدارة الأدوار والدخول إلى سياق المتجر.':'Edit core details, manage roles and enter the store context.' }}</p></div>
                    <form method="get" class="store-search">
                        <label>{{ $ar?'بحث بالاسم أو الكود':'Search name or code' }}<input name="q" value="{{ $search }}" placeholder="{{ $ar?'اكتب اسم المتجر أو الكود':'Store name or code' }}"></label>
                        <button class="foodex-action-primary foodex-filter-action" type="submit">{{ $ar?'بحث':'Search' }}</button>
                    </form>
                </div>

                @forelse($stores as $store)
                    <details class="foodex-card store-card store-accordion" data-store-accordion="{{ $store->id }}" @if($loop->first) open="open" @endif>
                        <summary aria-label="{{ $ar?'فتح أو إغلاق بيانات المتجر':'Expand or collapse store details' }}">
                            <div class="store-accordion-summary-main">
                                @if($store->logo_path)
                                    <img class="store-logo" src="{{ asset(ltrim($store->logo_path,'/')) }}" alt="{{ $store->name }}">
                                @else
                                    <span class="store-logo-placeholder" aria-label="{{ $ar?'لا يوجد شعار':'No logo' }}">🏪</span>
                                @endif
                                <div class="store-accordion-summary-text">
                                    <div class="store-meta"><h3>{{ $store->name }}</h3><span class="badge">{{ $store->code }}</span><span class="badge {{ $store->is_active?'active':'' }}">{{ $store->is_active?($ar?'نشط':'Active'):($ar?'غير نشط':'Inactive') }}</span></div>
                                    <small class="foodex-file-help">{{ $ar?'شريحة الجملة':'Wholesale tier' }}: <strong>{{ $store->wholesale_price_tier_name ?: ($ar?'غير محددة':'Not assigned') }}</strong></small>
                                </div>
                            </div>
                            <span class="store-accordion-chevron" aria-hidden="true">⌄</span>
                        </summary>
                        <div class="store-accordion-body">
                            <div class="store-card-top">
                                <form method="post" action="{{ route('admin.retail-stores.inspect',$store) }}">@csrf
                                    <button class="foodex-action-secondary button secondary" type="submit" @disabled(!$store->is_active)>⌕ {{ $ar?'إدارة / فحص المتجر':'Manage / Inspect Store' }}</button>
                                </form>
                            </div>

                        <form method="post" action="{{ route('admin.retail-stores.update',$store) }}" enctype="multipart/form-data" class="store-form-grid">
                            @csrf @method('patch')
                            <label>{{ $ar?'كود المتجر':'Store code' }}<input name="code" value="{{ $store->code }}" required maxlength="80" placeholder="STORE-01"></label>
                            <label>{{ $ar?'اسم المتجر':'Store name' }}<input name="name" value="{{ $store->name }}" required maxlength="255" placeholder="{{ $ar?'اسم المتجر':'Store name' }}"></label>
                            <label>{{ $ar?'استبدال الشعار':'Replace logo' }}<input name="logo" type="file" accept="image/jpeg,image/png,image/webp"><small class="foodex-file-help">{{ $ar?'اتركه فارغًا للاحتفاظ بالشعار الحالي.':'Leave empty to keep the current logo.' }}</small></label>
                            <label>{{ $ar?'شريحة تسعير طلبات الجملة':'Wholesale order price tier' }}<select name="price_tier_id" required><option value="">{{ $ar?'اختر شريحة التسعير':'Select price tier' }}</option>@foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((int)$store->wholesale_price_tier_id===(int)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach</select></label>
                            <label><span>{{ $ar?'الحالة':'Status' }}</span><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($store->is_active)> {{ $ar?'نشط':'Active' }}</span></label>
                            <button class="foodex-action-primary" type="submit">✓ {{ $ar?'حفظ بيانات المتجر':'Save store details' }}</button>
                        </form>

                        <div>
                            <h4>{{ $ar?'المدراء والأدوار':'Managers & store roles' }}</h4>
                            <div class="store-assignments">
                                @forelse($store->storeRoleAssignments as $assignment)
                                    <div class="store-assignment">
                                        <span>{{ $assignment->user?->name }} · {{ $assignment->user?->email }} · <strong>{{ $assignment->role?->code }}</strong></span>
                                        <form method="post" action="{{ route('admin.retail-stores.roles.remove',[$store,$assignment->id]) }}">@csrf @method('delete')
                                            <button class="danger btn" type="submit">{{ $ar?'إزالة الإسناد':'Remove assignment' }}</button>
                                        </form>
                                    </div>
                                @empty
                                    <div class="foodex-empty-state">{{ $ar?'لا توجد أدوار معينة لهذا المتجر.':'No store role assignments yet.' }}</div>
                                @endforelse
                            </div>
                        </div>

                        <form method="post" action="{{ route('admin.retail-stores.roles.assign',$store) }}" class="store-form-grid">
                            @csrf
                            <label>{{ $ar?'المستخدم':'User' }}<select name="user_id" required><option value="">{{ $ar?'اختر المستخدم':'Select user' }}</option>@foreach($users as $managedUser)<option value="{{ $managedUser->id }}">{{ $managedUser->name }} · {{ $managedUser->email }}</option>@endforeach</select></label>
                            <label>{{ $ar?'الدور داخل المتجر':'Store role' }}<select name="role_id" required><option value="">{{ $ar?'اختر الدور':'Select role' }}</option>@foreach($storeRoles as $role)<option value="{{ $role->id }}">{{ $role->name }} ({{ $role->code }})</option>@endforeach</select></label>
                            <button class="foodex-action-secondary button secondary" type="submit">＋ {{ $ar?'إسناد الدور':'Assign role' }}</button>
                        </form>
                        </div>
                    </details>
                @empty
                    <div class="foodex-empty-state">{{ $ar?'لا توجد متاجر مطابقة.':'No matching retail stores.' }}</div>
                @endforelse

                {{ $stores->links() }}
            </section>
        </div>
    </main>
</div>
<script>
(() => {
    const form = document.querySelector('[data-foodex-stepper]');
    if (!form) return;
    const tabs = [...form.querySelectorAll('[data-step-target]')];
    const panels = [...form.querySelectorAll('[data-step-panel]')];
    const activate = (name) => {
        tabs.forEach(tab => tab.setAttribute('aria-selected', tab.dataset.stepTarget === name ? 'true' : 'false'));
        panels.forEach(panel => panel.hidden = panel.dataset.stepPanel !== name);
    };
    tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.stepTarget)));
    form.querySelectorAll('[data-step-next]').forEach(button => button.addEventListener('click', () => activate(button.dataset.stepNext)));

    const mode = document.getElementById('manager_mode');
    const syncManagerMode = () => {
        form.querySelectorAll('[data-manager-panel]').forEach(panel => panel.hidden = panel.dataset.managerPanel !== mode.value);
    };
    mode?.addEventListener('change', syncManagerMode);
    syncManagerMode();

    if (@json($errors->any())) {
        @php($hasStoreDetailErrors = $errors->has('code') || $errors->has('name') || $errors->has('logo') || $errors->has('price_tier_id') || $errors->has('is_active') || $errors->has('advertising_enabled') || $errors->has('coupons_enabled'))
        const storeDetailErrors = @json($hasStoreDetailErrors);
        activate(storeDetailErrors ? 'store-details' : 'store-manager');
    }

    const accordions = [...document.querySelectorAll('[data-store-accordion]')];
    accordions.forEach((accordion) => {
        accordion.addEventListener('toggle', () => {
            if (!accordion.open) return;
            accordions.forEach((other) => {
                if (other !== accordion) other.open = false;
            });
        });
    });
})();
</script>
</body></html>
