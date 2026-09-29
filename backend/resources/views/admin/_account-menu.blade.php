@php
    $foodexAccountUser = $user ?? auth()->user();
    $foodexAccountAr = app()->getLocale() === 'ar';
    $foodexAccountTargetLocale = $foodexAccountAr ? 'en' : 'ar';
@endphp

@if($foodexAccountUser)
<div class="foodex-account-menu" data-foodex-account-menu>
    <button class="foodex-account-trigger" type="button"
            aria-haspopup="menu" aria-expanded="false"
            aria-label="{{ $foodexAccountAr ? 'قائمة حساب المستخدم' : 'User account menu' }}"
            data-foodex-account-trigger>
        <span class="foodex-account-avatar" aria-hidden="true">
            @include('admin._premium-icon',['name'=>'customers'])
        </span>
    </button>

    <section class="foodex-account-panel" role="menu" hidden data-foodex-account-panel>
        <div class="foodex-account-summary">
            <span class="foodex-account-avatar foodex-account-avatar-lg" aria-hidden="true">
                @include('admin._premium-icon',['name'=>'customers'])
            </span>
            <span class="foodex-account-identity">
                <strong>{{ $foodexAccountUser->name }}</strong>
                <small>{{ $foodexAccountUser->email }}</small>
            </span>
        </div>

        <a class="foodex-account-item" role="menuitem" href="{{ route('admin.profile.index') }}">
            @include('admin._premium-icon',['name'=>'settings'])
            <span>{{ $foodexAccountAr ? 'إعدادات الحساب' : 'Account settings' }}</span>
        </a>

        <form method="post" action="{{ route('admin.profile.locale') }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="locale" value="{{ $foodexAccountTargetLocale }}">
            <button class="foodex-account-item" role="menuitem" type="submit">
                @include('admin._premium-icon',['name'=>'globe'])
                <span>{{ $foodexAccountAr ? 'English' : 'العربية' }}</span>
            </button>
        </form>

        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <button class="foodex-account-item foodex-account-logout" role="menuitem" type="submit">
                <span class="foodex-account-signout" aria-hidden="true">↪</span>
                <span>{{ __('admin.logout') }}</span>
            </button>
        </form>
    </section>
</div>

<style>
.foodex-account-menu{position:relative;display:inline-flex;align-items:center}
.foodex-account-trigger{width:var(--foodex-touch-target);height:var(--foodex-touch-target);display:grid;place-items:center;padding:0;border:1px solid var(--foodex-border);border-radius:50%;background:var(--foodex-surface);color:var(--foodex-ink);cursor:pointer}
.foodex-account-trigger:hover,.foodex-account-trigger[aria-expanded="true"]{background:var(--foodex-green-soft);color:var(--foodex-green-dark);border-color:#b7dfc4}
.foodex-account-avatar{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-account-avatar .foodex-svg-icon{width:20px;height:20px}
.foodex-account-avatar-lg{width:40px;height:40px;flex:0 0 40px}
.foodex-account-avatar-lg .foodex-svg-icon{width:24px;height:24px}
.foodex-account-panel{position:absolute;z-index:90;inset-block-start:calc(100% + 10px);inset-inline-end:0;width:min(300px,calc(100vw - 28px));padding:8px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-raised);text-align:start}
.foodex-account-summary{display:flex;align-items:center;gap:10px;padding:10px 10px 12px;border-bottom:1px solid var(--foodex-border);margin-bottom:6px}
.foodex-account-identity{min-width:0;display:grid;gap:2px}
.foodex-account-identity strong,.foodex-account-identity small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.foodex-account-identity small{color:var(--foodex-muted);font-size:var(--foodex-text-xs)}
.foodex-account-panel form{margin:0}
.foodex-account-item{width:100%;min-height:44px;display:grid;grid-template-columns:22px minmax(0,1fr);align-items:center;gap:9px;padding:8px 10px;border:0;border-radius:10px;background:transparent;color:var(--foodex-ink);text-decoration:none;text-align:start;font:inherit;cursor:pointer}
.foodex-account-item:hover,.foodex-account-item:focus-visible{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
.foodex-account-item .foodex-svg-icon{width:19px;height:19px}
.foodex-account-logout{color:#b42318}
.foodex-account-logout:hover,.foodex-account-logout:focus-visible{background:#fff1f0;color:#b42318}
.foodex-account-signout{font-size:19px;line-height:1;text-align:center}
@media(max-width:767px){.foodex-account-panel{position:fixed;inset-inline:14px;inset-block-start:70px;width:auto}}
</style>

<script>
(() => {
    document.querySelectorAll('[data-foodex-account-menu]').forEach((root) => {
        if (root.dataset.initialized === '1') return;
        root.dataset.initialized = '1';

        const trigger = root.querySelector('[data-foodex-account-trigger]');
        const panel = root.querySelector('[data-foodex-account-panel]');
        if (!trigger || !panel) return;

        const close = () => {
            panel.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        };

        const open = () => {
            panel.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        };

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.hidden ? open() : close();
        });

        panel.addEventListener('click', (event) => event.stopPropagation());

        document.addEventListener('click', close);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                close();
                trigger.focus();
            }
        });
    });
})();
</script>
@endif
