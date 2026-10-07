<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar'?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ app()->getLocale()==='ar'?'الملف الشخصي':'My Profile' }} · FOODEX</title>
@include('admin._brand-components')
<style>
.profile-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:var(--foodex-space-5)}
.profile-card{padding:var(--foodex-space-5)}.profile-identity{display:flex;gap:16px;align-items:center;margin-bottom:20px}
.profile-avatar{width:68px;height:68px;border-radius:20px;display:grid;place-items:center;background:linear-gradient(145deg,var(--foodex-green-soft),#fff);color:var(--foodex-green-dark);border:1px solid var(--foodex-border);font-size:1.65rem;font-weight:800}
.profile-identity h2{margin:0}.profile-identity p{margin:5px 0 0;color:var(--foodex-muted)}
.profile-section{margin-top:22px}.profile-section h3{margin:0 0 10px}.chips{display:flex;flex-wrap:wrap;gap:7px}.permission-chip{display:inline-flex;align-items:center;min-height:30px;padding:4px 10px;border-radius:999px;background:#f3f6f4;border:1px solid var(--foodex-border);font-size:.76rem}
.assignment{padding:13px;border:1px solid var(--foodex-border);border-radius:12px;background:#fbfcfd;margin-bottom:9px}.assignment strong{display:block}.assignment small{color:var(--foodex-muted)}
.profile-form{display:grid;gap:13px}.profile-form label{display:grid;gap:7px;font-weight:700}.profile-actions{display:flex;gap:10px;flex-wrap:wrap}
.language-cards{display:grid;grid-template-columns:1fr 1fr;gap:10px}.language-card{min-height:86px;border:1px solid var(--foodex-border);border-radius:14px;background:#fff;display:grid;place-content:center;text-align:center;gap:4px;cursor:pointer;font:inherit}.language-card.active{border-color:var(--foodex-green);background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
@media(max-width:900px){.profile-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
@php($ar=app()->getLocale()==='ar')
<div class="foodex-admin-layout">
<aside class="sidebar collapsed">@include('admin._sidebar')</aside>
<main class="foodex-admin-main foodex-admin-page">
<header class="foodex-page-header"><div><span class="foodex-subtitle">FOODEX Account</span><h1>{{ $ar?'الملف الشخصي والصلاحيات':'Profile & Permissions' }}</h1><p>{{ $ar?'راجع حسابك والأدوار والصلاحيات الفعلية وغير كلمة المرور أو لغة لوحة الإدارة.':'Review your account, effective roles and permissions, and manage password or administration language.' }}</p></div>@include('admin._live-notifications',['user'=>auth()->user()])</header>
<div class="profile-grid">
<section class="foodex-card profile-card">
    <div class="profile-identity"><div class="profile-avatar">{{ mb_strtoupper(mb_substr($user->name,0,1)) }}</div><div><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p><span class="badge active">{{ $user->is_active?($ar?'حساب نشط':'Active account'):($ar?'غير نشط':'Inactive') }}</span></div></div>

    <div class="profile-section"><h3>{{ $ar?'الأدوار العامة':'Global roles' }}</h3><div class="chips">@forelse($globalRoles as $role)<span class="permission-chip">{{ $role->name }}</span>@empty<span class="foodex-subtitle">{{ $ar?'لا توجد أدوار عامة.':'No global roles.' }}</span>@endforelse</div></div>

    <div class="profile-section"><h3>{{ $ar?'أدوار المتاجر':'Store assignments' }}</h3>
        @forelse($storeAssignments as $assignment)
            <div class="assignment"><strong>{{ $assignment->store?->name ?? '—' }} · {{ $assignment->role?->name ?? '—' }}</strong><small>{{ $assignment->store?->code }}</small>
            @if($assignment->store)<div class="chips" style="margin-top:9px">@foreach($storePermissions[(int)$assignment->store->id] ?? [] as $permission)<span class="permission-chip">{{ $permission }}</span>@endforeach</div>@endif</div>
        @empty<div class="foodex-empty-state">{{ $ar?'لا توجد أدوار مرتبطة بمتاجر.':'No store-scoped role assignments.' }}</div>@endforelse
    </div>

    <div class="profile-section"><h3>{{ $ar?'الصلاحيات الفعلية العامة':'Effective global permissions' }}</h3><div class="chips">@foreach($globalPermissions as $permission)<span class="permission-chip">{{ $permission }}</span>@endforeach</div></div>
</section>

<div style="display:grid;gap:var(--foodex-space-5);align-content:start">
<section class="foodex-card profile-card"><h2>{{ $ar?'اللغة':'Language' }}</h2><p class="foodex-subtitle">{{ $ar?'تغيير اللغة يتم فورًا ويُحفظ على حسابك.':'The selected language is saved to your account immediately.' }}</p>
    <div class="language-cards" style="margin-top:14px">
        <form method="post" action="{{ route('admin.profile.locale') }}">@csrf @method('PATCH')<input type="hidden" name="locale" value="ar"><button class="language-card {{ app()->getLocale()==='ar'?'active':'' }}" type="submit">🌐<strong>العربية</strong></button></form>
        <form method="post" action="{{ route('admin.profile.locale') }}">@csrf @method('PATCH')<input type="hidden" name="locale" value="en"><button class="language-card {{ app()->getLocale()==='en'?'active':'' }}" type="submit">🌐<strong>English</strong></button></form>
    </div>
</section>
<section class="foodex-card profile-card"><h2>{{ $ar?'تغيير كلمة المرور':'Reset password' }}</h2><p class="foodex-subtitle">{{ $ar?'استخدم كلمة المرور الحالية ثم اختر كلمة مرور جديدة قوية.':'Confirm your current password and choose a strong new password.' }}</p>
    <form method="post" action="{{ route('admin.profile.password') }}" class="profile-form">@csrf @method('PATCH')
        <label>{{ $ar?'كلمة المرور الحالية':'Current password' }}<input type="password" name="current_password" autocomplete="current-password" required placeholder="{{ $ar?'أدخل كلمة المرور الحالية':'Enter current password' }}"></label>
        <label>{{ $ar?'كلمة المرور الجديدة':'New password' }}<input type="password" name="password" autocomplete="new-password" minlength="10" required placeholder="{{ $ar?'10 أحرف على الأقل مع أرقام وحروف':'10+ chars with letters and numbers' }}"></label>
        <label>{{ $ar?'تأكيد كلمة المرور الجديدة':'Confirm new password' }}<input type="password" name="password_confirmation" autocomplete="new-password" minlength="10" required placeholder="{{ $ar?'أعد كتابة كلمة المرور الجديدة':'Repeat the new password' }}"></label>
        <button class="foodex-action-primary" type="submit">🔐 {{ $ar?'تغيير كلمة المرور':'Change password' }}</button>
    </form>
</section>
</div>
</div>
</main></div>
</body></html>
