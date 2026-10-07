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
.store-create-launch{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.store-create-modal[hidden]{display:none}.store-create-modal{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:22px;background:rgba(15,23,42,.64);backdrop-filter:blur(3px)}
.store-create-dialog{width:min(980px,100%);max-height:calc(100vh - 44px);overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:22px;box-shadow:0 26px 80px rgba(15,23,42,.24)}
.store-create-dialog-head{position:sticky;top:0;z-index:3;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px;border-bottom:1px solid var(--foodex-border);background:rgba(255,255,255,.96);backdrop-filter:blur(8px)}
.store-create-dialog-head h2{margin:0}.store-create-dialog-head p{margin:5px 0 0;color:var(--foodex-muted)}
.store-create-close{width:40px;height:40px;border-radius:999px;border:1px solid var(--foodex-border);background:#fff;font-size:24px;line-height:1;cursor:pointer}
.store-wizard{padding:20px 22px 24px}.store-wizard-steps{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin:0 0 20px;padding:0;list-style:none}
.store-wizard-step{display:grid;gap:4px;padding:10px;border:1px solid var(--foodex-border);border-radius:12px;background:#f8fafc;color:var(--foodex-muted);font-size:12px;font-weight:800}
.store-wizard-step strong{font-size:13px;color:inherit}.store-wizard-step[aria-current="step"]{border-color:var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.store-wizard-step[data-state="done"]{color:var(--foodex-green-dark);background:#f0fdf4}
.store-wizard-panel{display:grid;gap:18px}.store-wizard-panel h3{margin:0}.store-wizard-panel>p{margin:-8px 0 0;color:var(--foodex-muted)}
.store-wizard-channel{padding:14px 16px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;display:flex;justify-content:space-between;gap:12px;align-items:center}
.store-review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.store-review-item{padding:14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;display:grid;gap:5px}.store-review-item small{color:var(--foodex-muted)}.store-review-item.wide{grid-column:1/-1}
body.foodex-modal-open{overflow:hidden}
.manager-mode-panel{padding:var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}.commerce-status{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;padding:14px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd}.commerce-status-item{display:grid;gap:4px}.commerce-status-item small{color:var(--foodex-muted)}.store-logo{width:64px;height:64px;border-radius:14px;object-fit:cover;border:1px solid var(--foodex-border);background:#fff}.store-logo-placeholder{width:64px;height:64px;border-radius:14px;display:grid;place-items:center;border:1px dashed var(--foodex-border);background:#f8fafc;font-size:28px}
@media(max-width:900px){.store-wizard-steps{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:720px){.store-panel{padding:var(--foodex-space-4)}.store-accordion summary{padding:14px}.store-accordion-body{padding:var(--foodex-space-4)}.store-logo,.store-logo-placeholder{width:52px;height:52px}.store-accordion-chevron{width:30px;height:30px}.store-create-modal{padding:0}.store-create-dialog{width:100%;height:100%;max-height:none;border-radius:0}.store-create-dialog-head,.store-wizard{padding:16px}.store-wizard-steps{grid-template-columns:repeat(2,minmax(0,1fr))}.store-review-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
@php
$ar=app()->getLocale()==='ar';
$roleLabel = static function ($role): string {
    if (!$role) return '—';
    $key = 'admin.role_names.'.$role->code;
    return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : $role->name;
};
@endphp
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
            <section class="foodex-card store-panel store-create-launch">
                <div>
                    <h2>{{ $ar?'إضافة متجر جديد':'Add a new store' }}</h2>
                    <p>{{ $ar?'أنشئ المتجر من خلال خطوات مرتبة ومراجعة نهائية قبل الحفظ. لا يتم إنشاء أي سجل قبل التأكيد النهائي.':'Create the store through ordered steps with a final review. Nothing is persisted before final confirmation.' }}</p>
                </div>
                <button class="foodex-action-primary" type="button" data-open-store-wizard>＋ {{ $ar?'إضافة متجر':'Add Store' }}</button>
            </section>

            <div class="store-create-modal" data-store-create-modal hidden>
                <div class="store-create-dialog" role="dialog" aria-modal="true" aria-labelledby="store-create-title">
                    <div class="store-create-dialog-head">
                        <div>
                            <span class="foodex-subtitle">{{ $ar?'إنشاء متجر تجزئة':'Retail Store Creation' }}</span>
                            <h2 id="store-create-title">{{ $ar?'إضافة متجر':'Add Store' }}</h2>
                            <p>{{ $ar?'أكمل كل خطوة بالترتيب. لا يمكن تخطي خطوة غير مكتملة.':'Complete every step in order. An incomplete step cannot be skipped.' }}</p>
                        </div>
                        <button class="store-create-close" type="button" aria-label="{{ $ar?'إغلاق':'Close' }}" data-close-store-wizard>×</button>
                    </div>

                    <form class="store-wizard" method="post" action="{{ route('admin.retail-stores.store') }}" enctype="multipart/form-data" id="retail-provision-form" data-foodex-store-wizard>
                        @csrf
                        <ol class="store-wizard-steps" aria-label="{{ $ar?'خطوات إنشاء المتجر':'Store creation steps' }}">
                            <li class="store-wizard-step" data-wizard-step-indicator="identity" aria-current="step"><span>01</span><strong>{{ $ar?'الهوية':'Identity' }}</strong></li>
                            <li class="store-wizard-step" data-wizard-step-indicator="branding"><span>02</span><strong>{{ $ar?'الشعار':'Branding' }}</strong></li>
                            <li class="store-wizard-step" data-wizard-step-indicator="pricing"><span>03</span><strong>{{ $ar?'التسعير':'Pricing' }}</strong></li>
                            <li class="store-wizard-step" data-wizard-step-indicator="campaigns"><span>04</span><strong>{{ $ar?'الحملات':'Campaigns' }}</strong></li>
                            <li class="store-wizard-step" data-wizard-step-indicator="manager"><span>05</span><strong>{{ $ar?'المدير':'Manager' }}</strong></li>
                            <li class="store-wizard-step" data-wizard-step-indicator="review"><span>06</span><strong>{{ $ar?'المراجعة':'Review' }}</strong></li>
                        </ol>

                        <section class="store-wizard-panel" data-wizard-panel="identity">
                            <h3>{{ $ar?'1. هوية المتجر':'1. Store identity' }}</h3>
                            <p>{{ $ar?'حدد الكود والاسم اللذين سيعرّفان المتجر داخل النظام.':'Set the code and display name that identify this store.' }}</p>
                            <div class="store-form-grid">
                                <label>{{ $ar?'كود المتجر':'Store code' }}
                                    <input name="code" value="{{ old('code') }}" required maxlength="80" pattern="[A-Za-z0-9_-]+" placeholder="{{ $ar?'مثال: CAIRO-01':'e.g. CAIRO-01' }}">
                                    <small class="foodex-file-help">{{ $ar?'حروف إنجليزية وأرقام وشرطة وشرطة سفلية فقط.':'Letters, numbers, dash and underscore only.' }}</small>
                                </label>
                                <label>{{ $ar?'اسم المتجر الظاهر':'Store display name' }}
                                    <input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="{{ $ar?'مثال: متجر مدينة نصر':'e.g. Nasr City Store' }}">
                                </label>
                            </div>
                            <div class="foodex-step-actions">
                                <button class="foodex-action-primary" type="button" data-wizard-next="branding">{{ $ar?'التالي: الشعار':'Next: Branding' }} →</button>
                            </div>
                        </section>

                        <section class="store-wizard-panel" data-wizard-panel="branding" hidden>
                            <h3>{{ $ar?'2. الشعار والهوية البصرية':'2. Branding & logo' }}</h3>
                            <p>{{ $ar?'ارفع شعار المتجر قبل الانتقال للخطوة التالية.':'Upload the store logo before continuing.' }}</p>
                            <div class="store-form-grid">
                                <label class="wide">{{ $ar?'شعار المتجر':'Store logo' }}
                                    <input name="logo" type="file" accept="image/jpeg,image/png,image/webp" required>
                                    <small class="foodex-file-help">{{ $ar?'JPG / PNG / WebP حتى 5MB':'JPG / PNG / WebP up to 5MB' }}</small>
                                </label>
                            </div>
                            <div class="foodex-step-actions">
                                <button class="foodex-action-secondary button secondary" type="button" data-wizard-back="identity">← {{ $ar?'السابق':'Back' }}</button>
                                <button class="foodex-action-primary" type="button" data-wizard-next="pricing">{{ $ar?'التالي: التسعير':'Next: Pricing' }} →</button>
                            </div>
                        </section>

                        <section class="store-wizard-panel" data-wizard-panel="pricing" hidden>
                            <h3>{{ $ar?'3. التسعير والقناة':'3. Pricing & channel' }}</h3>
                            <p>{{ $ar?'متجر التجزئة يعمل على قناة B2C، واختر شرائح الجملة المرتبطة به.':'Retail stores use the B2C channel; choose the Wholesale tiers linked to it.' }}</p>
                            <div class="store-wizard-channel">
                                <span>{{ $ar?'القناة':'Channel' }}</span>
                                <strong>{{ $ar?'تجزئة · B2C':'Retail · B2C' }}</strong>
                            </div>
                            <div class="store-form-grid">
                                <label>{{ $ar?'شريحة تسعير طلبات الجملة':'Wholesale order price tier' }}
                                    <select name="price_tier_id" required>
                                        <option value="">{{ $ar?'اختر شريحة التسعير':'Select price tier' }}</option>
                                        @foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((string)old('price_tier_id')===(string)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach
                                    </select>
                                    <small class="foodex-file-help">{{ $ar?'تُستخدم عندما يشتري هذا المتجر من الجملة الرئيسية.':'Used when this Retail store purchases from the main Wholesale operation.' }}</small>
                                </label>
                                <label>{{ $ar?'شريحة الجملة الافتراضية لعملاء المتجر':'Default Wholesale tier for customers registered here' }}
                                    <select name="default_customer_wholesale_price_tier_id">
                                        <option value="">{{ $ar?'STANDARD تلقائيًا':'Automatic STANDARD fallback' }}</option>
                                        @foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((string)old('default_customer_wholesale_price_tier_id')===(string)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach
                                    </select>
                                </label>
                                <label><span>{{ $ar?'حالة المتجر':'Store status' }}</span>
                                    <span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active','1')==='1')> {{ $ar?'نشط ومتاح للإدارة':'Active and manageable' }}</span>
                                </label>
                            </div>
                            <div class="foodex-step-actions">
                                <button class="foodex-action-secondary button secondary" type="button" data-wizard-back="branding">← {{ $ar?'السابق':'Back' }}</button>
                                <button class="foodex-action-primary" type="button" data-wizard-next="campaigns">{{ $ar?'التالي: الحملات':'Next: Campaigns' }} →</button>
                            </div>
                        </section>

                        <section class="store-wizard-panel" data-wizard-panel="campaigns" hidden>
                            <h3>{{ $ar?'4. الحملات والمزايا':'4. Campaigns & promotions' }}</h3>
                            <p>{{ $ar?'حدد المزايا التسويقية التي ستكون متاحة لهذا المتجر.':'Choose the marketing capabilities available to this store.' }}</p>
                            <div class="store-form-grid">
                                <label><span>{{ $ar?'الحملات الإعلانية':'Advertising campaigns' }}</span>
                                    <span><input type="hidden" name="advertising_enabled" value="0"><input type="checkbox" name="advertising_enabled" value="1" @checked(old('advertising_enabled','1')==='1')> {{ $ar?'مفعلة':'Enabled' }}</span>
                                </label>
                                <label><span>{{ $ar?'الإعلانات الحية':'Live ads' }}</span>
                                    <span><input type="hidden" name="live_ads_enabled" value="0"><input type="checkbox" name="live_ads_enabled" value="1" @checked(old('live_ads_enabled','1')==='1')> {{ $ar?'مفعلة':'Enabled' }}</span>
                                </label>
                                <label><span>{{ $ar?'الكوبونات':'Coupons' }}</span>
                                    <span><input type="hidden" name="coupons_enabled" value="0"><input type="checkbox" name="coupons_enabled" value="1" @checked(old('coupons_enabled','1')==='1')> {{ $ar?'مفعلة':'Enabled' }}</span>
                                </label>
                            </div>
                            <div class="foodex-step-actions">
                                <button class="foodex-action-secondary button secondary" type="button" data-wizard-back="pricing">← {{ $ar?'السابق':'Back' }}</button>
                                <button class="foodex-action-primary" type="button" data-wizard-next="manager">{{ $ar?'التالي: المدير':'Next: Manager' }} →</button>
                            </div>
                        </section>

                        <section class="store-wizard-panel" data-wizard-panel="manager" hidden>
                            <h3>{{ $ar?'5. المدير والصلاحية':'5. Manager & access' }}</h3>
                            <p>{{ $ar?'اربط مستخدمًا موجودًا أو أنشئ مديرًا جديدًا للمتجر.':'Link an existing user or create a new store manager.' }}</p>
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
                                <button class="foodex-action-secondary button secondary" type="button" data-wizard-back="campaigns">← {{ $ar?'السابق':'Back' }}</button>
                                <button class="foodex-action-primary" type="button" data-wizard-next="review">{{ $ar?'التالي: المراجعة':'Next: Review' }} →</button>
                            </div>
                        </section>

                        <section class="store-wizard-panel" data-wizard-panel="review" hidden>
                            <h3>{{ $ar?'6. مراجعة وإنشاء':'6. Review & create' }}</h3>
                            <p>{{ $ar?'راجع القيم الفعلية قبل إنشاء المتجر. يمكنك الرجوع لأي خطوة بدون فقد البيانات.':'Review the actual values before creating the store. You can go back without losing entered data.' }}</p>
                            <div class="store-review-grid">
                                <div class="store-review-item"><small>{{ $ar?'الكود':'Code' }}</small><strong data-review="code">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'الاسم':'Name' }}</small><strong data-review="name">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'الشعار':'Logo' }}</small><strong data-review="logo">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'القناة':'Channel' }}</small><strong>{{ $ar?'تجزئة · B2C':'Retail · B2C' }}</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'شريحة شراء المتجر':'Store Wholesale tier' }}</small><strong data-review="price_tier_id">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'شريحة العملاء':'Customer Wholesale tier' }}</small><strong data-review="default_customer_wholesale_price_tier_id">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'الحالة':'Status' }}</small><strong data-review="is_active">—</strong></div>
                                <div class="store-review-item"><small>{{ $ar?'المدير':'Manager' }}</small><strong data-review="manager">—</strong></div>
                                <div class="store-review-item wide"><small>{{ $ar?'المزايا التسويقية':'Marketing capabilities' }}</small><strong data-review="campaigns">—</strong></div>
                            </div>
                            <div class="foodex-step-actions">
                                <button class="foodex-action-secondary button secondary" type="button" data-wizard-back="manager">← {{ $ar?'السابق':'Back' }}</button>
                                <button class="foodex-action-primary" type="submit" data-wizard-final-submit>✓ {{ $ar?'إنشاء المتجر الآن':'Create Store Now' }}</button>
                            </div>
                        </section>
                    </form>
                </div>
            </div>

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
                                    <small class="foodex-file-help">{{ $ar?'المالك / المدير الأساسي':'Primary Owner / Manager' }}: <strong>{{ $store->primary_owner_email ?: ($ar?'غير معيّن':'Not assigned') }}</strong></small>
                                    <small class="foodex-file-help">{{ $ar?'شريحة شراء المتجر من الجملة':'Store Wholesale purchasing tier' }}: <strong>{{ $store->wholesale_price_tier_name ?: ($ar?'غير محددة':'Not assigned') }}</strong></small>
                                    <small class="foodex-file-help">{{ $ar?'شريحة الجملة لعملاء المتجر':'Customer Wholesale tier' }}: <strong>{{ $store->customer_wholesale_price_tier_name ?: 'STANDARD' }}</strong></small>
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

                            <div class="commerce-status" data-commerce-status="{{ $store->id }}">
                                <div class="commerce-status-item">
                                    <small>{{ $ar?'المالك / المدير الأساسي':'Primary Owner / Manager' }}</small>
                                    <strong data-primary-owner="{{ $store->primary_owner_user_id ?: '' }}">{{ $store->primary_owner_name ?: ($ar?'غير معيّن':'Not assigned') }}</strong>
                                    <span>{{ $store->primary_owner_email ?: '—' }}</span>
                                </div>
                                <div class="commerce-status-item">
                                    <small>{{ $ar?'هوية تطبيق العميل':'Customer App identity' }}</small>
                                    <strong>{{ $store->customer_app_identity_linked ? ($ar?'مرتبطة ونشطة':'Linked / active') : ($ar?'غير مرتبطة':'Not linked') }}</strong>
                                </div>
                                <div class="commerce-status-item">
                                    <small>{{ $ar?'حساب الشراء من الجملة':'Wholesale purchasing account' }}</small>
                                    <strong>{{ $store->wholesale_account_linked ? ($ar?'مرتبط':'Linked') : ($ar?'غير مرتبط':'Not linked') }}</strong>
                                    @if($store->wholesale_b2b_customer_id)<span>B2B #{{ $store->wholesale_b2b_customer_id }}</span>@endif
                                </div>
                                <div class="commerce-status-item">
                                    <small>{{ $ar?'شريحة شراء المتجر من الجملة':'Wholesale price tier' }}</small>
                                    <strong>{{ $store->wholesale_price_tier_name ?: ($ar?'غير محددة':'Not assigned') }}</strong>
                                </div>
                                <div class="commerce-status-item">
                                    <small>{{ $ar?'تسجيل الدخول':'Authentication' }}</small>
                                    <strong>{{ $ar?'نفس حساب المستخدم في المنصة':'Same platform User login' }}</strong>
                                    <span>{{ $ar?'لا توجد كلمة مرور منفصلة للجملة.':'No separate Wholesale password.' }}</span>
                                </div>
                            </div>

                        <form method="post" action="{{ route('admin.retail-stores.update',$store) }}" enctype="multipart/form-data" class="store-form-grid">
                            @csrf @method('patch')
                            <label>{{ $ar?'كود المتجر':'Store code' }}<input name="code" value="{{ $store->code }}" required maxlength="80" placeholder="STORE-01"></label>
                            <label>{{ $ar?'اسم المتجر':'Store name' }}<input name="name" value="{{ $store->name }}" required maxlength="255" placeholder="{{ $ar?'اسم المتجر':'Store name' }}"></label>
                            <label>{{ $ar?'استبدال الشعار':'Replace logo' }}<input name="logo" type="file" accept="image/jpeg,image/png,image/webp"><small class="foodex-file-help">{{ $ar?'اتركه فارغًا للاحتفاظ بالشعار الحالي.':'Leave empty to keep the current logo.' }}</small></label>
                            <label>{{ $ar?'شريحة تسعير طلبات الجملة':'Wholesale order price tier' }}<select name="price_tier_id" required><option value="">{{ $ar?'اختر شريحة التسعير':'Select price tier' }}</option>@foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((int)$store->wholesale_price_tier_id===(int)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach</select></label>
                            <label>{{ $ar?'المالك / المدير الأساسي':'Primary Owner / Manager' }}
                                <select name="primary_owner_user_id">
                                    <option value="">{{ $ar?'اترك المالك الحالي بدون تغيير':'Keep current owner unchanged' }}</option>
                                    @foreach($users as $managedUser)
                                        <option value="{{ $managedUser->id }}" @selected((int)$store->primary_owner_user_id===(int)$managedUser->id)>{{ $managedUser->name }} · {{ $managedUser->email }}</option>
                                    @endforeach
                                </select>
                                <small class="foodex-file-help">{{ $ar?'يستخدم هذا المستخدم نفس البريد وكلمة المرور في تطبيق العميل. إعادة التعيين تنقل صلاحية المدير الأساسية بدون إنشاء حساب جملة جديد.':'This User keeps the same email/password in Customer App. Reassignment moves the primary manager entitlement without creating another Wholesale account.' }}</small>
                            </label>
                            <label>{{ $ar?'شريحة الجملة الافتراضية لعملاء المتجر':'Default Wholesale tier for customers registered here' }}<select name="default_customer_wholesale_price_tier_id"><option value="">{{ $ar?'STANDARD تلقائيًا':'Automatic STANDARD fallback' }}</option>@foreach($priceTiers as $tier)<option value="{{ $tier->id }}" @selected((int)$store->default_customer_wholesale_price_tier_id===(int)$tier->id)>{{ $tier->name }} · {{ $tier->code }}</option>@endforeach</select></label>
                            <label><span>{{ $ar?'الحالة':'Status' }}</span><span><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($store->is_active)> {{ $ar?'نشط':'Active' }}</span></label>
                            <button class="foodex-action-primary" type="submit">✓ {{ $ar?'حفظ بيانات المتجر':'Save store details' }}</button>
                        </form>

                        <div>
                            <h4>{{ $ar?'المدراء والأدوار':'Managers & store roles' }}</h4>
                            <div class="store-assignments">
                                @forelse($store->storeRoleAssignments as $assignment)
                                    <div class="store-assignment">
                                        <span>
                                            {{ $assignment->user?->name }} · {{ $assignment->user?->email }} · <strong>{{ $roleLabel($assignment->role) }}</strong>
                                            @if((int)$store->primary_owner_user_id===(int)$assignment->user_id && $assignment->role?->code==='B2C_STORE_ADMIN')
                                                <span class="badge active">{{ $ar?'المالك الأساسي':'Primary Owner' }}</span>
                                            @endif
                                        </span>
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
    const modal = document.querySelector('[data-store-create-modal]');
    const form = document.querySelector('[data-foodex-store-wizard]');
    const steps = ['identity', 'branding', 'pricing', 'campaigns', 'manager', 'review'];
    const nextByStep = {identity:'branding', branding:'pricing', pricing:'campaigns', campaigns:'manager', manager:'review'};
    let currentStep = 'identity';

    const setModalOpen = (open) => {
        if (!modal) return;
        modal.hidden = !open;
        document.body.classList.toggle('foodex-modal-open', open);
        if (open) {
            window.setTimeout(() => form?.querySelector('[data-wizard-panel]:not([hidden]) input, [data-wizard-panel]:not([hidden]) select')?.focus(), 0);
        }
    };

    const activate = (name) => {
        if (!form || !steps.includes(name)) return;
        currentStep = name;
        form.querySelectorAll('[data-wizard-panel]').forEach(panel => {
            panel.hidden = panel.dataset.wizardPanel !== name;
        });
        form.querySelectorAll('[data-wizard-step-indicator]').forEach((indicator) => {
            const index = steps.indexOf(indicator.dataset.wizardStepIndicator);
            const activeIndex = steps.indexOf(name);
            indicator.setAttribute('aria-current', index === activeIndex ? 'step' : 'false');
            indicator.dataset.state = index < activeIndex ? 'done' : (index === activeIndex ? 'current' : 'upcoming');
        });
        if (name === 'review') updateReview();
    };

    const mode = document.getElementById('manager_mode');
    const syncManagerMode = () => {
        if (!form || !mode) return;
        const isExisting = mode.value === 'existing';
        form.querySelectorAll('[data-manager-panel]').forEach(panel => {
            panel.hidden = panel.dataset.managerPanel !== mode.value;
        });
        const existing = form.querySelector('[name="manager_user_id"]');
        const managerName = form.querySelector('[name="manager_name"]');
        const managerEmail = form.querySelector('[name="manager_email"]');
        const managerPassword = form.querySelector('[name="manager_password"]');
        if (existing) existing.required = isExisting;
        [managerName, managerEmail, managerPassword].forEach(input => {
            if (input) input.required = !isExisting;
        });
    };

    const validateStep = (name) => {
        if (!form) return false;
        syncManagerMode();
        const panel = form.querySelector('[data-wizard-panel="' + name + '"]');
        if (!panel) return false;
        const fields = [...panel.querySelectorAll('input, select, textarea')].filter(field => {
            if (field.disabled || field.type === 'hidden') return false;
            return field.closest('[hidden]') === null;
        });
        for (const field of fields) {
            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
                return false;
            }
        }
        return true;
    };

    const selectedText = (name, fallback = '—') => {
        const select = form?.querySelector('[name="' + name + '"]');
        if (!(select instanceof HTMLSelectElement) || select.selectedIndex < 0) return fallback;
        const option = select.options[select.selectedIndex];
        return option?.value ? option.textContent.trim() : fallback;
    };

    const checked = (name) => form?.querySelector('input[name="' + name + '"][type="checkbox"]')?.checked === true;
    const reviewText = (key, value) => {
        const target = form?.querySelector('[data-review="' + key + '"]');
        if (target) target.textContent = value || '—';
    };

    const updateReview = () => {
        if (!form) return;
        const code = form.querySelector('[name="code"]')?.value?.trim() || '—';
        const name = form.querySelector('[name="name"]')?.value?.trim() || '—';
        const logo = form.querySelector('[name="logo"]')?.files?.[0]?.name || '—';
        const managerMode = mode?.value || 'existing';
        const manager = managerMode === 'existing'
            ? selectedText('manager_user_id')
            : [form.querySelector('[name="manager_name"]')?.value?.trim(), form.querySelector('[name="manager_email"]')?.value?.trim()].filter(Boolean).join(' · ');
        const on = @json($ar?'مفعل':'Enabled');
        const off = @json($ar?'غير مفعل':'Disabled');
        const campaigns = [
            @json($ar?'الحملات':'Advertising') + ': ' + (checked('advertising_enabled') ? on : off),
            @json($ar?'الإعلانات الحية':'Live ads') + ': ' + (checked('live_ads_enabled') ? on : off),
            @json($ar?'الكوبونات':'Coupons') + ': ' + (checked('coupons_enabled') ? on : off)
        ].join(' · ');

        reviewText('code', code);
        reviewText('name', name);
        reviewText('logo', logo);
        reviewText('price_tier_id', selectedText('price_tier_id'));
        reviewText('default_customer_wholesale_price_tier_id', selectedText('default_customer_wholesale_price_tier_id', 'STANDARD'));
        reviewText('is_active', checked('is_active') ? @json($ar?'نشط':'Active') : @json($ar?'غير نشط':'Inactive'));
        reviewText('manager', manager || '—');
        reviewText('campaigns', campaigns);
    };

    document.querySelectorAll('[data-open-store-wizard]').forEach(button => button.addEventListener('click', () => {
        setModalOpen(true);
        activate(currentStep);
    }));
    document.querySelectorAll('[data-close-store-wizard]').forEach(button => button.addEventListener('click', () => setModalOpen(false)));
    modal?.addEventListener('click', event => {
        if (event.target === modal) setModalOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal && !modal.hidden) setModalOpen(false);
    });

    mode?.addEventListener('change', syncManagerMode);
    syncManagerMode();

    form?.querySelectorAll('[data-wizard-next]').forEach(button => button.addEventListener('click', () => {
        const panel = button.closest('[data-wizard-panel]');
        const from = panel?.dataset.wizardPanel;
        if (!from || !validateStep(from)) return;
        activate(button.dataset.wizardNext);
    }));
    form?.querySelectorAll('[data-wizard-back]').forEach(button => button.addEventListener('click', () => {
        activate(button.dataset.wizardBack);
    }));

    form?.addEventListener('submit', event => {
        if (currentStep !== 'review') {
            event.preventDefault();
            if (!validateStep(currentStep)) return;
            const next = nextByStep[currentStep];
            if (next) activate(next);
            return;
        }
        for (const step of steps.slice(0, -1)) {
            if (!validateStep(step)) {
                event.preventDefault();
                activate(step);
                return;
            }
        }
        updateReview();
    });

    const errorKeys = @json($errors->keys());
    if (errorKeys.length) {
        const stepForError = (key) => {
            if (['code','name'].includes(key)) return 'identity';
            if (key === 'logo') return 'branding';
            if (['price_tier_id','default_customer_wholesale_price_tier_id','is_active'].includes(key)) return 'pricing';
            if (['advertising_enabled','live_ads_enabled','coupons_enabled'].includes(key)) return 'campaigns';
            return 'manager';
        };
        setModalOpen(true);
        activate(stepForError(errorKeys[0]));
    } else {
        activate('identity');
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
