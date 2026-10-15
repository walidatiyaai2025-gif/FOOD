@php
    $adminCenterActive = $active ?? 'administration';
@endphp
<style>
.admin-center-switcher{display:flex;align-items:center;justify-content:space-between;gap:14px;max-width:1180px;margin:0 auto 22px;padding:10px;background:#fff;border:1px solid var(--foodex-border);border-radius:16px;box-shadow:var(--foodex-shadow-sm)}
.admin-center-switcher__context{display:flex;align-items:center;gap:10px;min-width:0;padding-inline:4px}
.admin-center-switcher__icon{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font-weight:900;flex:0 0 auto}
.admin-center-switcher__label{font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.admin-center-switcher__tabs{display:flex;gap:6px;align-items:center}
.admin-center-switcher__tab{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 14px;border-radius:10px;color:var(--foodex-ink);text-decoration:none;font-weight:800;border:1px solid transparent;white-space:nowrap}
.admin-center-switcher__tab:hover{background:var(--foodex-background)}
.admin-center-switcher__tab.is-active{background:var(--foodex-action-green);color:#fff}
@media(max-width:680px){.admin-center-switcher{align-items:stretch;flex-direction:column;margin-bottom:16px}.admin-center-switcher__tabs{display:grid;grid-template-columns:1fr 1fr}.admin-center-switcher__tab{width:100%}}
</style>
<nav class="admin-center-switcher" aria-label="{{ __('admin.settings_center.switcher_aria') }}">
    <div class="admin-center-switcher__context">
        <span class="admin-center-switcher__icon" aria-hidden="true">⚙</span>
        <span class="admin-center-switcher__label">{{ __('admin.management_system') }}</span>
    </div>
    <div class="admin-center-switcher__tabs">
        <a class="admin-center-switcher__tab {{ $adminCenterActive === 'administration' ? 'is-active' : '' }}"
           href="{{ route('admin.administration.index') }}"
           @if($adminCenterActive === 'administration') aria-current="page" @endif>
            {{ __('admin.administration_hub.title') }}
        </a>
        <a class="admin-center-switcher__tab {{ $adminCenterActive === 'settings' ? 'is-active' : '' }}"
           href="{{ route('admin.settings.index') }}"
           @if($adminCenterActive === 'settings') aria-current="page" @endif>
            {{ __('admin.settings_center.title') }}
        </a>
    </div>
</nav>
