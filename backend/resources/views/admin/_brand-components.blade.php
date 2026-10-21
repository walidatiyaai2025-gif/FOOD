@include('admin._brand')
@php
    $foodexCustomerServiceProfile = auth()->check()
        && app(\App\Support\AdminNavigation::class)->usesCustomerServiceDashboardProfile(auth()->user());
@endphp
@if($foodexCustomerServiceProfile)
<script>document.documentElement.classList.add('foodex-customer-service-profile')</script> {{-- localization-gate: allow — technical CSS class toggle; no user-facing copy. --}}
@endif
<style id="foodex-admin-component-tokens">
    /* Shared extended palette tokens: dashboard views reference these instead of local hex literals. */
    :root{--foodex-palette-0891b2:#0891b2;--foodex-palette-0e7490:#0e7490;--foodex-palette-0f172a:#0f172a;--foodex-palette-111827:#111827;--foodex-palette-13221a:#13221a;--foodex-palette-14532d:#14532d;--foodex-palette-146c36:#146c36;--foodex-palette-15803d:#15803d;--foodex-palette-158a3a:#158a3a;--foodex-palette-166534:#166534;--foodex-palette-16a34a:#16a34a;--foodex-palette-179c52:#179c52;--foodex-palette-18251f:#18251f;--foodex-palette-1d4ed8:#1d4ed8;--foodex-palette-22c55e:#22c55e;--foodex-palette-22d3ee:#22d3ee;--foodex-palette-243029:#243029;--foodex-palette-2563eb:#2563eb;--foodex-palette-2dd4bf:#2dd4bf;--foodex-palette-334155:#334155;--foodex-palette-38bdf8:#38bdf8;--foodex-palette-4338ca:#4338ca;--foodex-palette-475569:#475569;--foodex-palette-52605a:#52605a;--foodex-palette-60a5fa:#60a5fa;--foodex-palette-64748b:#64748b;--foodex-palette-667085:#667085;--foodex-palette-67e8f9:#67e8f9;--foodex-palette-6d28d9:#6d28d9;--foodex-palette-72ad82:#72ad82;--foodex-palette-78ba87:#78ba87;--foodex-palette-7c3aed:#7c3aed;--foodex-palette-7db98c:#7db98c;--foodex-palette-83bd91:#83bd91;--foodex-palette-86efac:#86efac;--foodex-palette-8abd96:#8abd96;--foodex-palette-93c5fd:#93c5fd;--foodex-palette-a5b4fc:#a5b4fc;--foodex-palette-a5f3fc:#a5f3fc;--foodex-palette-a78bfa:#a78bfa;--foodex-palette-a9ceb2:#a9ceb2;--foodex-palette-afd4b8:#afd4b8;--foodex-palette-b45309:#b45309;--foodex-palette-b8d9c2:#b8d9c2;--foodex-palette-b91c1c:#b91c1c;--foodex-palette-b9d8c1:#b9d8c1;--foodex-palette-bbf7d0:#bbf7d0;--foodex-palette-be123c:#be123c;--foodex-palette-bedbc5:#bedbc5;--foodex-palette-bfd5c6:#bfd5c6;--foodex-palette-bfdbfe:#bfdbfe;--foodex-palette-bfe0c7:#bfe0c7;--foodex-palette-c084fc:#c084fc;--foodex-palette-c2410c:#c2410c;--foodex-palette-c4b5fd:#c4b5fd;--foodex-palette-c7d1cb:#c7d1cb;--foodex-palette-c7d2fe:#c7d2fe;--foodex-palette-ca8a04:#ca8a04;--foodex-palette-cbd5e1:#cbd5e1;--foodex-palette-cfd9d3:#cfd9d3;--foodex-palette-d6dfda:#d6dfda;--foodex-palette-d7e1db:#d7e1db;--foodex-palette-d97706:#d97706;--foodex-palette-dbeafe:#dbeafe;--foodex-palette-dc2626:#dc2626;--foodex-palette-dce4df:#dce4df;--foodex-palette-dce5df:#dce5df;--foodex-palette-dcfce7:#dcfce7;--foodex-palette-ddd6fe:#ddd6fe;--foodex-palette-e2e8e4:#e2e8e4;--foodex-palette-e2e8f0:#e2e8f0;--foodex-palette-e6ece8:#e6ece8;--foodex-palette-e7ece9:#e7ece9;--foodex-palette-e7f6eb:#e7f6eb;--foodex-palette-e9f7ed:#e9f7ed;--foodex-palette-ea580c:#ea580c;--foodex-palette-eaf0ec:#eaf0ec;--foodex-palette-eaf7ef:#eaf7ef;--foodex-palette-ecfdf5:#ecfdf5;--foodex-palette-ecfeff:#ecfeff;--foodex-palette-edf1ee:#edf1ee;--foodex-palette-edf1ef:#edf1ef;--foodex-palette-edf1f4:#edf1f4;--foodex-palette-edf8f0:#edf8f0;--foodex-palette-eef2ff:#eef2ff;--foodex-palette-eef7f0:#eef7f0;--foodex-palette-eef9f1:#eef9f1;--foodex-palette-eff6ff:#eff6ff;--foodex-palette-eff8f2:#eff8f2;--foodex-palette-f1f5f9:#f1f5f9;--foodex-palette-f1faf3:#f1faf3;--foodex-palette-f3c36b:#f3c36b;--foodex-palette-f3f6f4:#f3f6f4;--foodex-palette-f3fbf5:#f3fbf5;--foodex-palette-f472b6:#f472b6;--foodex-palette-f4f7f5:#f4f7f5;--foodex-palette-f59e0b:#f59e0b;--foodex-palette-f5f3ff:#f5f3ff;--foodex-palette-f5f7f6:#f5f7f6;--foodex-palette-f5fbf6:#f5fbf6;--foodex-palette-f6f8fa:#f6f8fa;--foodex-palette-f7efe6:#f7efe6;--foodex-palette-f7faf8:#f7faf8;--foodex-palette-f7fcf8:#f7fcf8;--foodex-palette-f87171:#f87171;--foodex-palette-f8faf9:#f8faf9;--foodex-palette-f8fafc:#f8fafc;--foodex-palette-f8fcf9:#f8fcf9;--foodex-palette-f97316:#f97316;--foodex-palette-f9fbfa:#f9fbfa;--foodex-palette-facc15:#facc15;--foodex-palette-fb7185:#fb7185;--foodex-palette-fb923c:#fb923c;--foodex-palette-fbfcfb:#fbfcfb;--foodex-palette-fca5a5:#fca5a5;--foodex-palette-fda4af:#fda4af;--foodex-palette-fdba74:#fdba74;--foodex-palette-fecaca:#fecaca;--foodex-palette-fecdd3:#fecdd3;--foodex-palette-fed7aa:#fed7aa;--foodex-palette-fee2e2:#fee2e2;--foodex-palette-fef2f2:#fef2f2;--foodex-palette-fff1f2:#fff1f2;--foodex-palette-fff7e6:#fff7e6;--foodex-palette-fff7ed:#fff7ed;--foodex-palette-fff7f7:#fff7f7;--foodex-palette-fffbeb:#fffbeb}
    *,*::before,*::after{box-sizing:border-box}
    html,body{max-width:100%;overflow-x:hidden;background:var(--foodex-background)!important;color:var(--foodex-ink)!important}
    body{font-family:var(--foodex-font-ui)!important;font-size:var(--foodex-text-sm);font-weight:var(--foodex-font-weight-regular);line-height:var(--foodex-leading-normal);text-rendering:optimizeLegibility}
    :lang(en),.foodex-en,.foodex-number{font-family:var(--foodex-font-en)!important}
    .foodex-number{font-variant-numeric:tabular-nums lining-nums}
    h1,h2,h3,h4,h5,h6,strong,b{font-weight:var(--foodex-font-weight-bold)}
    small,.muted,.empty{color:var(--foodex-muted)}
    a{color:var(--foodex-green-dark)}
    button,input,select,textarea{font-family:inherit}
    input:not([type="checkbox"]):not([type="radio"]),select,textarea,.foodex-control{min-height:var(--foodex-control-height);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink);padding-inline:var(--foodex-space-3)}
    input:focus,select:focus,textarea:focus,.foodex-control:focus{outline:none;border-color:var(--foodex-action-green)!important;box-shadow:0 0 0 3px rgba(21,138,58,.11)}
    :focus-visible{outline:2px solid var(--foodex-green);outline-offset:2px}

    aside.sidebar,.sidebar{background:var(--foodex-surface)!important;color:var(--foodex-ink)!important;border-color:var(--foodex-border)!important}
    .foodex-card,.panel,.card,.filters,.back{background:var(--foodex-surface)!important;border:1px solid var(--foodex-border)!important;border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
    /* #2078 Single Global Admin Header: platform chrome renders once; page identity/actions stay in content toolbars. */
    .foodex-global-header{position:relative;z-index:60;display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,560px) minmax(0,1fr);align-items:center;gap:var(--foodex-header-gap);width:100%;max-width:100%;min-width:0;height:var(--foodex-header-height);min-height:var(--foodex-header-height);max-height:var(--foodex-header-height);margin-bottom:12px;padding:5px 8px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:10px;box-shadow:var(--foodex-shadow-sm)}
    .foodex-global-header>*{min-width:0}
    .foodex-global-header-spacer{display:block;width:100%;min-width:0}
    .foodex-global-header-center{grid-column:2;display:flex;align-items:center;justify-content:center;gap:8px;min-width:0;width:100%;margin-inline:auto}
    .foodex-global-header-search{min-width:180px;width:100%;justify-self:center}
    .foodex-global-header-extra{display:flex;align-items:center;min-width:0;flex:0 0 auto}
    .foodex-global-header--with-extra{grid-template-columns:minmax(0,.45fr) minmax(720px,1100px) minmax(0,.45fr)}
    .foodex-global-header--with-extra .foodex-global-header-search{flex:1 1 360px;max-width:460px}
    .foodex-global-header--with-extra .foodex-global-header-extra{flex:0 1 auto;max-width:650px}
    .foodex-global-header-utilities{justify-self:end;display:flex;align-items:center;min-width:0}
    @media(max-width:1250px){.foodex-global-header--with-extra{grid-template-columns:minmax(0,1fr) minmax(420px,760px) minmax(0,1fr)}.foodex-global-header--with-extra .foodex-global-header-search{max-width:520px}}
    @media(max-width:767px){.foodex-global-header-center{grid-column:2;gap:5px}.foodex-global-header--with-extra{grid-template-columns:36px minmax(0,1fr) auto}.foodex-global-header--with-extra .foodex-global-header-search{min-width:0;max-width:none}.foodex-global-header--with-extra .foodex-global-header-extra{max-width:none}}
    .foodex-page-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-3);width:100%;max-width:100%;min-width:0;margin-bottom:var(--foodex-space-4);padding:0;overflow:visible}
    .foodex-page-toolbar>*{min-width:0}
    .foodex-page-toolbar>*:has(>h1){min-width:0}
    .foodex-page-toolbar h1{margin:0!important;max-width:100%;font-size:var(--foodex-text-lg)!important;line-height:var(--foodex-leading-tight)!important;font-weight:var(--foodex-font-weight-bold)}
    .foodex-page-toolbar p{margin:3px 0 0!important;max-width:72ch;color:var(--foodex-muted);font-size:var(--foodex-text-xs)!important;line-height:1.45!important}
    .foodex-page-toolbar .foodex-subtitle{display:block!important;margin-bottom:2px;color:var(--foodex-muted);font-size:var(--foodex-text-xs)!important}
    .foodex-header-actions{display:flex;align-items:center;justify-content:flex-end;gap:var(--foodex-header-gap);flex-wrap:nowrap;min-width:0;max-width:58%;overflow-x:auto;scrollbar-width:none}
    .foodex-header-actions::-webkit-scrollbar{display:none}
    .foodex-page-toolbar .foodex-header-actions :is(a,button,select){white-space:nowrap}
    .foodex-topbar{height:var(--foodex-header-height);min-height:var(--foodex-header-height);max-height:var(--foodex-header-height);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border)}
    .foodex-search{min-height:var(--foodex-control-height);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
    .foodex-subtitle{display:block;margin-top:2px;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-regular)}
    .foodex-icon{inline-size:var(--foodex-icon-md);block-size:var(--foodex-icon-md);display:inline-grid;place-items:center;flex:0 0 auto}

    .primary,.button,.btn.primary,button.foodex-primary,.foodex-action-primary,.foodex-filter-action{min-height:var(--foodex-touch-target);border:0;border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-4);display:inline-flex;align-items:center;justify-content:center;gap:var(--foodex-space-2);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-action-green)!important;color:#fff!important;text-decoration:none;cursor:pointer}
    .primary:hover,.button:hover,.btn.primary:hover,button.foodex-primary:hover,.foodex-action-primary:hover,.foodex-filter-action:hover{background:var(--foodex-action-green-hover)!important}
    .secondary,.button.secondary,.btn.secondary,.foodex-action-secondary{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}
    .danger,.btn.danger{background:var(--foodex-danger-soft)!important;color:var(--foodex-danger-text)!important}
    .success,.notice,.flash{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important;border-color:#b7dfc4!important}
    .warning,.error,.errors{background:var(--foodex-orange-soft)!important;color:#9a4b0b!important;border-color:#ffd0a6!important}
    .pill.active{background:var(--foodex-action-green)!important;color:#fff!important;border-color:var(--foodex-action-green)!important}
    .back{color:var(--foodex-green-dark)!important;box-shadow:none}
    .badge.active,.active.badge{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}

    /* Shared semantic status / priority vocabulary. Text remains authoritative; color only reinforces meaning. */
    .foodex-status-badge,.foodex-priority-badge{--foodex-badge-bg:#f6f8fa;--foodex-badge-fg:var(--foodex-ink);--foodex-badge-border:var(--foodex-border);display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:28px;max-width:100%;padding:4px 9px;border:1px solid var(--foodex-badge-border);border-radius:999px;background:var(--foodex-badge-bg)!important;color:var(--foodex-badge-fg)!important;font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);line-height:1.2;white-space:normal;text-align:center}
    .foodex-status-badge[data-tone="success"],.foodex-status-badge[data-state="active"],.foodex-status-badge[data-state="approved"],.foodex-status-badge[data-state="completed"],.foodex-status-badge[data-state="delivered"],.foodex-status-badge[data-state="paid"],.foodex-status-badge[data-state="posted"],.foodex-status-badge[data-state="confirmed"],.foodex-status-badge[data-state="ready"],.foodex-status-badge[data-state="success"]{--foodex-badge-bg:var(--foodex-green-soft);--foodex-badge-fg:var(--foodex-green-dark);--foodex-badge-border:#b7dfc4}
    .foodex-status-badge[data-tone="warning"],.foodex-status-badge[data-state="pending"],.foodex-status-badge[data-state="draft"],.foodex-status-badge[data-state="processing"],.foodex-status-badge[data-state="assigned"],.foodex-status-badge[data-state="awaiting_dispatch"],.foodex-status-badge[data-state="scheduled"],.foodex-status-badge[data-state="warning"]{--foodex-badge-bg:var(--foodex-orange-soft);--foodex-badge-fg:#8a4308;--foodex-badge-border:#ffd0a6}
    .foodex-status-badge[data-tone="danger"],.foodex-status-badge[data-state="failed"],.foodex-status-badge[data-state="cancelled"],.foodex-status-badge[data-state="canceled"],.foodex-status-badge[data-state="inactive"],.foodex-status-badge[data-state="blocked"],.foodex-status-badge[data-state="rejected"],.foodex-status-badge[data-state="error"]{--foodex-badge-bg:var(--foodex-danger-soft);--foodex-badge-fg:var(--foodex-danger-text);--foodex-badge-border:#f2b8b5}
    .foodex-status-badge[data-tone="info"],.foodex-status-badge[data-state="open"],.foodex-status-badge[data-state="in_progress"],.foodex-status-badge[data-state="picked_up"],.foodex-status-badge[data-state="out_for_delivery"]{--foodex-badge-bg:#eef6ff;--foodex-badge-fg:#245a8d;--foodex-badge-border:#c6ddf4}
    .foodex-priority-badge[data-priority="critical"],.foodex-priority-badge[data-priority="high"]{--foodex-badge-bg:var(--foodex-danger-soft);--foodex-badge-fg:var(--foodex-danger-text);--foodex-badge-border:#f2b8b5}
    .foodex-priority-badge[data-priority="medium"]{--foodex-badge-bg:var(--foodex-orange-soft);--foodex-badge-fg:#8a4308;--foodex-badge-border:#ffd0a6}
    .foodex-priority-badge[data-priority="low"]{--foodex-badge-bg:var(--foodex-green-soft);--foodex-badge-fg:var(--foodex-green-dark);--foodex-badge-border:#b7dfc4}

    /* Shared sequential workflow pattern. Native buttons preserve keyboard/focus semantics. */
    .foodex-stepper{display:grid;grid-template-columns:repeat(var(--foodex-step-columns,4),minmax(0,1fr));gap:var(--foodex-space-2);min-width:0;max-width:100%;margin-block:2px 4px}
    .foodex-step{min-width:0;width:100%;min-height:var(--foodex-touch-target);padding:7px 10px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-muted);font:inherit;font-weight:var(--foodex-font-weight-bold);line-height:1.35;white-space:normal;overflow-wrap:anywhere;cursor:pointer}
    .foodex-step[aria-current="step"]{background:var(--foodex-green-soft);border-color:#b7dfc4;color:var(--foodex-green-dark)}
    .foodex-step[data-complete="true"]{border-color:#b7dfc4;color:var(--foodex-green-dark)}

    /* Shared exact-record / Record 360 drawer. */
    .foodex-record-drawer{position:fixed;inset-block:0;inset-inline-end:0;width:min(860px,94vw);height:100vh;height:100dvh;max-height:100vh;max-height:100dvh;margin:0;border:0;border-inline-start:1px solid var(--foodex-border);padding:0;background:var(--foodex-surface);box-shadow:0 0 50px rgba(15,23,42,.18);z-index:100}
    .foodex-record-drawer::backdrop{background:rgba(15,23,42,.35)}
    .foodex-record-drawer-shell{height:100%;overflow:auto;padding:var(--foodex-space-5);overscroll-behavior:contain}
    .foodex-record-drawer-header{position:sticky;top:0;z-index:2;display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-3);margin-bottom:var(--foodex-space-4);padding-bottom:var(--foodex-space-3);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border)}
    .foodex-record-drawer-close{min-width:var(--foodex-touch-target);min-height:var(--foodex-touch-target);display:inline-grid;place-items:center;border-radius:var(--foodex-radius-control);text-decoration:none;font-size:1.35rem;font-weight:900;color:var(--foodex-ink)}
    .foodex-record-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-4);min-width:0}
    .foodex-record-section{min-width:0}
    @media(max-width:767px){.foodex-stepper{grid-template-columns:repeat(2,minmax(0,1fr))}.foodex-record-drawer{width:100vw;max-width:100vw;border-inline-start:0}.foodex-record-drawer-shell{padding:var(--foodex-space-4)}.foodex-record-grid{grid-template-columns:minmax(0,1fr)}}

    .foodex-admin-page{width:100%;max-width:none!important;min-width:0;margin:0!important;padding:clamp(var(--foodex-space-4),1.8vw,var(--foodex-space-8))}
    .foodex-form{display:grid;gap:var(--foodex-space-3);background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);padding:var(--foodex-space-5);box-shadow:var(--foodex-shadow-sm);margin-bottom:var(--foodex-space-5)}
    .foodex-form label{display:grid;gap:var(--foodex-space-2);font-weight:var(--foodex-font-weight-medium);color:var(--foodex-ink)}
    .foodex-form button{justify-self:start}
    .foodex-table{width:100%;border-collapse:separate;border-spacing:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);overflow:hidden}
    .foodex-table th,.foodex-table td{padding:var(--foodex-table-cell-y) var(--foodex-table-cell-x);text-align:start;border-bottom:1px solid var(--foodex-border)}
    .foodex-table th{background:#FAFBFC;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold)}
    .foodex-table tr:last-child td{border-bottom:0}

    /* PH-06.7 shared utility shells and UI states. */
    .foodex-admin-layout{direction:ltr;display:grid;grid-template-columns:minmax(0,1fr) var(--foodex-sidebar-width);min-height:100vh;background:var(--foodex-background)}
    .foodex-admin-layout>.sidebar{grid-column:2;grid-row:1;direction:rtl;min-height:100vh;padding:var(--foodex-space-5);border-inline-start:1px solid var(--foodex-border)}
    .foodex-admin-layout>.foodex-admin-main{grid-column:1;grid-row:1;direction:rtl;min-width:0;width:100%;max-width:none;padding:clamp(var(--foodex-space-4),1.8vw,var(--foodex-space-8))}
    html[dir=ltr] .foodex-admin-layout{grid-template-columns:var(--foodex-sidebar-width) minmax(0,1fr)}
    html[dir=ltr] .foodex-admin-layout>.sidebar{grid-column:1;direction:ltr;border-inline-start:0;border-inline-end:1px solid var(--foodex-border)}
    html[dir=ltr] .foodex-admin-layout>.foodex-admin-main{grid-column:2;direction:ltr}
    .foodex-alert,[role="alert"].foodex-state,[role="status"].foodex-state{padding:var(--foodex-space-3) var(--foodex-space-4);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);margin-bottom:var(--foodex-space-4);background:var(--foodex-surface)}
    .foodex-alert-success,[role="status"].foodex-state{background:var(--foodex-green-soft);border-color:#b7dfc4;color:var(--foodex-green-dark)}
    .foodex-alert-error,[role="alert"].foodex-state{background:var(--foodex-orange-soft);border-color:#ffd0a6;color:#9a4b0b}
    .foodex-empty-state{min-height:140px;display:grid;place-items:center;text-align:center;padding:var(--foodex-space-6);border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;color:var(--foodex-muted)}
    .foodex-tabs{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);border-bottom:1px solid var(--foodex-border);margin-bottom:var(--foodex-space-4)}
    .foodex-tabs a,.foodex-tab{min-height:var(--foodex-touch-target);display:inline-flex;align-items:center;padding:0 var(--foodex-space-3);border-bottom:2px solid transparent;color:var(--foodex-muted);text-decoration:none;font-weight:var(--foodex-font-weight-medium)}
    .foodex-tabs a.active,.foodex-tab.active{border-color:var(--foodex-green);color:var(--foodex-green-dark)}
    .foodex-modal-backdrop{position:fixed;inset:0;z-index:80;display:grid;place-items:center;padding:var(--foodex-space-4);background:rgba(23,32,51,.42)}
    .foodex-modal{width:min(100%,620px);max-height:min(88vh,760px);overflow:auto;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-lg);box-shadow:var(--foodex-shadow-raised);padding:var(--foodex-space-6)}
    .foodex-operational-dialog-host .foodex-modal{width:min(100%,920px)}
    .foodex-modal-backdrop[hidden]{display:none!important}
    .foodex-modal-header{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);margin-bottom:var(--foodex-space-4)}
    .foodex-modal-header h2,.foodex-modal-header h3{margin:0}
    .foodex-modal-close{width:var(--foodex-touch-target);height:var(--foodex-touch-target);display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);font:inherit;font-size:22px;cursor:pointer}
    body.foodex-modal-open{overflow:hidden}
    .pagination,.pager{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center;margin-top:var(--foodex-space-5)}
    .pagination a,.pagination span,.pager a,.pager span{min-width:var(--foodex-touch-target);min-height:var(--foodex-touch-target);display:inline-grid;place-items:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;padding:0 var(--foodex-space-2)}
    .pagination [aria-current="page"],.pager [aria-current="page"]{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
    .pagination nav,.pager nav{max-width:100%}
    .pagination svg,.pager svg{width:18px;height:18px}
    html[dir=rtl] .pagination svg,html[dir=rtl] .pager svg{transform:scaleX(-1)}
    .pagination [aria-disabled="true"],.pager [aria-disabled="true"]{opacity:.48}
    nav[role="navigation"] svg{width:18px!important;height:18px!important;max-width:18px!important;display:block}
    .foodex-pagination{display:flex;align-items:center;justify-content:space-between;gap:var(--foodex-space-3);flex-wrap:wrap;margin-top:var(--foodex-space-5)}
    .foodex-pagination-pages,.foodex-pagination-controls{display:flex;align-items:center;gap:var(--foodex-space-2);flex-wrap:wrap}
    .foodex-pagination a,.foodex-pagination span{min-width:40px;min-height:40px;display:inline-grid;place-items:center;padding:0 10px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none}
    .foodex-pagination .foodex-pagination-current{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff;font-weight:var(--foodex-font-weight-bold)}
    .foodex-pagination .foodex-pagination-disabled{opacity:.45;cursor:not-allowed}
    .foodex-pagination-summary{color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
    @media(max-width:767px){.pagination a,.pagination span,.pager a,.pager span{min-width:38px;min-height:38px;padding-inline:7px;font-size:.82rem}.foodex-pagination{justify-content:center}.foodex-pagination-summary{width:100%;text-align:center}.foodex-pagination-pages{justify-content:center}}
    .badge{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:3px 9px;border-radius:999px;background:var(--foodex-background);color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-medium)}


    /* Wide-screen + responsive administration geometry. */
    .foodex-admin-layout,.shell,.layout,.catalog-layout,.lookup-layout,.module-layout{width:100%;max-width:none}
    .foodex-admin-main,.foodex-admin-page,.main{min-width:0}
    .table-wrap,.module-table-wrap,.foodex-table-wrap{width:100%;max-width:100%;overflow:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
    .foodex-table{width:100%;max-width:100%}
    .foodex-form,.foodex-premium-auto-form{min-width:0}
    img,svg,video,canvas{max-width:100%}

    @media(min-width:1440px){
        :root{--foodex-sidebar-width:248px;--foodex-table-cell-x:16px}
        .foodex-admin-page,.foodex-admin-layout>.foodex-admin-main{padding-inline:clamp(28px,2vw,44px)}
        .foodex-page-toolbar{margin-bottom:var(--foodex-space-5)}
        .foodex-premium-auto-form{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
    }
    @media(min-width:1800px){
        :root{--foodex-sidebar-width:260px}
        .foodex-admin-page,.foodex-admin-layout>.foodex-admin-main{padding-inline:48px}
        .foodex-premium-auto-form{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    }
    @media(max-width:1023px){
        .foodex-admin-page{padding:var(--foodex-space-6)}
        .foodex-global-header{height:var(--foodex-header-height-tablet);min-height:var(--foodex-header-height-tablet);max-height:var(--foodex-header-height-tablet)}
        .foodex-page-toolbar{gap:var(--foodex-space-2)}
        .table-wrap,.module-table-wrap,.foodex-table-wrap{border-radius:var(--foodex-radius-md)}
    }
    @media(max-width:767px){
        .foodex-admin-page{padding:var(--foodex-space-4)}
        .foodex-page-toolbar{width:100%;align-items:flex-start!important}
        .foodex-page-toolbar>*{min-width:0}
        .foodex-header-actions{justify-content:flex-end}
        .foodex-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px;scrollbar-width:thin}
        .foodex-tabs a,.foodex-tab{flex:0 0 auto;white-space:nowrap}
        .foodex-premium-auto-form{grid-template-columns:minmax(0,1fr)}
        .pagination,.pager{justify-content:center}
    }
    @media(max-width:479px){
        .foodex-admin-page{padding:12px}
        .foodex-card,.panel,.card,.filters,.back{border-radius:12px}
        .foodex-modal{padding:var(--foodex-space-4)}
    }

    @media(max-width:1023px){
        .foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{grid-template-columns:1fr}
        .foodex-admin-layout>.sidebar,html[dir=ltr] .foodex-admin-layout>.sidebar{grid-column:1;grid-row:1;min-height:auto;height:auto;max-height:320px;overflow:auto;position:relative;border-inline:0;border-bottom:1px solid var(--foodex-border)}
        .foodex-admin-layout>.foodex-admin-main,html[dir=ltr] .foodex-admin-layout>.foodex-admin-main{grid-column:1;grid-row:2;padding:var(--foodex-space-6)}
    }
    @media(max-width:767px){
        .foodex-admin-page{padding:var(--foodex-space-4)}
        .foodex-page-toolbar{flex-direction:row!important}
        .foodex-admin-layout>.foodex-admin-main,html[dir=ltr] .foodex-admin-layout>.foodex-admin-main{padding:var(--foodex-space-4)}
    }

    /* Field Operations shared management primitives. Reuse these instead of page-local variants. */
    .foodex-ops-shell{display:grid;gap:var(--foodex-space-5);min-width:0}
    .foodex-ops-toolbar{display:grid;grid-template-columns:minmax(220px,2fr) repeat(auto-fit,minmax(160px,1fr));gap:var(--foodex-space-3);align-items:end}
    .foodex-ops-grid{width:100%;border-collapse:separate;border-spacing:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);overflow:hidden}
    .foodex-ops-grid th,.foodex-ops-grid td{padding:var(--foodex-table-cell-y) var(--foodex-table-cell-x);text-align:start;border-bottom:1px solid var(--foodex-border);vertical-align:middle}
    .foodex-ops-grid th{background:#FAFBFC;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);white-space:nowrap}
    .foodex-ops-grid tbody tr:hover{background:#fbfcfd}
    .foodex-ops-grid tbody tr:last-child td{border-bottom:0}
    .foodex-ops-actions{position:relative;display:inline-block}
    .foodex-ops-actions>summary{list-style:none;width:var(--foodex-touch-target);height:var(--foodex-touch-target);display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-ink);cursor:pointer;font-size:20px;line-height:1}
    .foodex-ops-actions>summary::-webkit-details-marker{display:none}
    .foodex-ops-actions>summary:hover,.foodex-ops-actions[open]>summary{background:var(--foodex-green-soft);border-color:#b7dfc4;color:var(--foodex-green-dark)}
    .foodex-ops-menu{position:fixed;z-index:10050;min-width:180px;max-width:min(320px,calc(100vw - 16px));padding:6px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);box-shadow:var(--foodex-shadow-raised);visibility:hidden}
    .foodex-ops-actions[open]>.foodex-ops-menu{visibility:visible}
    .foodex-ops-menu a,.foodex-ops-menu button{width:100%;min-height:40px;display:flex;align-items:center;justify-content:flex-start;padding:0 var(--foodex-space-3);border:0;border-radius:8px;background:transparent;color:var(--foodex-ink);text-decoration:none;font:inherit;cursor:pointer}
    .foodex-ops-menu a:hover,.foodex-ops-menu button:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
    .foodex-ops-menu .danger{color:var(--foodex-danger-text)!important;background:transparent!important}
    .foodex-ops-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-4)}
    .foodex-ops-state{min-height:140px;display:grid;place-items:center;text-align:center;padding:var(--foodex-space-6);border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;color:var(--foodex-muted)}
    @media(max-width:1023px){.foodex-ops-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:767px){.foodex-ops-toolbar,.foodex-ops-detail-grid{grid-template-columns:minmax(0,1fr)}.foodex-ops-grid .foodex-ops-hide-mobile{display:none}}


    /* #1296 Dashboard Mobile Web foundation: shared by existing and future admin pages. */
    .foodex-mobile-only,.foodex-mobile-web-bar,.foodex-mobile-nav-backdrop{display:none}
    .foodex-mobile-action-rail{display:flex;align-items:center;gap:var(--foodex-space-2);min-width:0}
    .foodex-mobile-filter-sheet{min-width:0}
    .foodex-mobile-sticky-actions{display:flex;align-items:center;gap:var(--foodex-space-2)}

    @media(max-width:767px){
        html{scroll-padding-top:64px}
        body.foodex-mobile-web{min-width:0;padding:var(--foodex-header-height-mobile) 0 0!important}
        .foodex-mobile-only{display:initial}
        .foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{display:grid;grid-template-columns:minmax(0,1fr)!important}
        .foodex-admin-layout>.foodex-admin-main,html[dir=ltr] .foodex-admin-layout>.foodex-admin-main{grid-column:1!important;grid-row:1!important;width:100%!important;max-width:100%!important;min-width:0!important;padding:12px!important}
        .foodex-admin-page{width:100%!important;max-width:100%!important;min-width:0!important;padding:12px!important}
        .foodex-global-header{position:fixed;z-index:10110;inset-block-start:0;inset-inline:0;grid-template-columns:minmax(0,1fr) auto;height:var(--foodex-header-height-mobile);min-height:var(--foodex-header-height-mobile);max-height:var(--foodex-header-height-mobile);margin:0;padding:6px 12px;padding-inline-start:64px;border-inline:0;border-top:0;border-radius:0}
        .foodex-global-header-spacer{display:none}
        .foodex-global-header-search{grid-column:1;grid-row:1}
        .foodex-global-header-utilities{grid-column:2;grid-row:1}
        .foodex-page-toolbar{flex-direction:row!important;align-items:flex-start!important;gap:var(--foodex-space-2);margin-bottom:12px!important}
        .foodex-page-toolbar h1{font-size:var(--foodex-text-md)!important;line-height:1.35!important}
        .foodex-page-toolbar p{font-size:var(--foodex-text-xs)!important;line-height:1.35!important}
        .foodex-page-toolbar .foodex-header-actions :is(a,button,select){min-height:var(--foodex-touch-target)!important;font-size:.75rem!important}
        .foodex-header-actions,.foodex-mobile-action-rail{justify-content:flex-end;flex-wrap:nowrap;overflow-x:auto;max-width:58%;padding-bottom:0;overscroll-behavior-inline:contain;scrollbar-width:none}
        .foodex-header-actions::-webkit-scrollbar,.foodex-mobile-action-rail::-webkit-scrollbar{display:none}
        .foodex-header-actions>*{flex:0 0 auto}
        .foodex-tabs{margin-inline:-12px;padding-inline:12px;scrollbar-width:none}
        .foodex-tabs::-webkit-scrollbar{display:none}
        input:not([type="checkbox"]):not([type="radio"]),select,textarea,.foodex-control{max-width:100%;font-size:16px}
        textarea{min-height:96px}
        .foodex-form{padding:14px;gap:12px;margin-bottom:14px}
        .foodex-form button{width:100%;justify-self:stretch}
        .foodex-card,.panel,.card,.filters,.back{max-width:100%;min-width:0}
        .foodex-ops-toolbar{grid-template-columns:minmax(0,1fr)!important;gap:10px}
        .foodex-ops-detail-grid{grid-template-columns:minmax(0,1fr)!important}
        .foodex-modal-backdrop{padding:0;align-items:end;justify-items:stretch}
        .foodex-modal,.foodex-operational-dialog-host .foodex-modal{width:100%;max-width:none;max-height:94dvh;border-radius:18px 18px 0 0;padding:16px}
        .foodex-modal-header{position:sticky;top:0;z-index:1;background:var(--foodex-surface);padding-block:2px 8px}
        .foodex-pagination{justify-content:center;gap:8px}
        .foodex-pagination-summary{width:100%;text-align:center}
        .foodex-pagination-pages,.foodex-pagination-controls{justify-content:center;flex-wrap:nowrap;max-width:100%;overflow-x:auto;scrollbar-width:none}
        .foodex-pagination-pages::-webkit-scrollbar,.foodex-pagination-controls::-webkit-scrollbar{display:none}
        .foodex-mobile-filter-sheet{position:fixed;z-index:10070;inset-inline:0;inset-block-end:0;max-height:min(82dvh,720px);overflow:auto;padding:16px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:18px 18px 0 0;box-shadow:var(--foodex-shadow-raised)}
        .foodex-mobile-sticky-actions{position:sticky;z-index:20;inset-block-end:0;margin:12px -12px -12px;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.96);border-top:1px solid var(--foodex-border);backdrop-filter:blur(10px)}
        .foodex-mobile-sticky-actions>*{flex:1 1 0}

        table[data-foodex-mobile-table-ready="1"]{display:block;width:100%;min-width:0!important;max-width:100%!important;border:0!important;background:transparent!important;box-shadow:none!important;overflow:visible!important}
        table[data-foodex-mobile-table-ready="1"] thead{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
        table[data-foodex-mobile-table-ready="1"] tbody{display:grid;gap:10px;width:100%}
        table[data-foodex-mobile-table-ready="1"] tbody tr{display:grid;width:100%;min-width:0;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:14px;box-shadow:var(--foodex-shadow-sm);overflow:hidden}
        table[data-foodex-mobile-table-ready="1"] tbody td{display:grid;grid-template-columns:minmax(92px,36%) minmax(0,1fr);align-items:start;gap:10px;width:100%;min-width:0;padding:10px 12px!important;border:0!important;border-bottom:1px solid var(--foodex-border)!important;text-align:start!important;white-space:normal!important;overflow-wrap:anywhere}
        table[data-foodex-mobile-table-ready="1"] tbody td:last-child{border-bottom:0!important}
        table[data-foodex-mobile-table-ready="1"] tbody td::before{content:attr(data-foodex-label);display:block;color:var(--foodex-muted);font-size:.75rem;font-weight:700;line-height:1.35}
        table[data-foodex-mobile-table-ready="1"] tbody td[colspan]{grid-template-columns:minmax(0,1fr)}
        table[data-foodex-mobile-table-ready="1"] tbody td[colspan]::before,
        table[data-foodex-mobile-table-ready="1"] tbody td[data-foodex-label=""]::before{display:none}
        table[data-foodex-mobile-table="scroll"]{min-width:max-content}
        .foodex-table-wrap:has(>table[data-foodex-mobile-table="scroll"]),
        .table-wrap:has(>table[data-foodex-mobile-table="scroll"]),
        .module-table-wrap:has(>table[data-foodex-mobile-table="scroll"]){overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
    }

    /* Enterprise saved-view primitive: shared by bounded operational workspaces. */
    .foodex-enterprise-viewbar{display:grid;gap:12px;margin-block:16px;padding:14px 16px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
    .foodex-enterprise-viewbar__head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
    .foodex-enterprise-viewbar__head p{margin:4px 0 0;color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
    .foodex-enterprise-viewbar__save>summary{list-style:none;min-height:44px;display:inline-flex;align-items:center;justify-content:center;padding-inline:14px;cursor:pointer}
    .foodex-enterprise-viewbar__save>summary::-webkit-details-marker{display:none}
    .foodex-enterprise-viewbar__form{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,.7fr) auto;gap:10px;align-items:end;margin-top:10px;padding:12px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-md);background:var(--foodex-background)}
    .foodex-enterprise-viewbar__form label{display:grid;gap:5px;font-weight:700;min-width:0}
    .foodex-enterprise-viewbar__form input,.foodex-enterprise-viewbar__form select{width:100%;min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding-inline:10px;background:#fff;font:inherit}
    .foodex-enterprise-viewbar__items{display:flex;flex-wrap:wrap;gap:8px}
    .foodex-enterprise-viewbar__item{display:flex;align-items:center;gap:7px;min-width:0;padding:6px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff}
    .foodex-enterprise-viewbar__item>a{min-height:32px;display:inline-flex;align-items:center;padding-inline:7px;color:var(--foodex-green-dark);font-weight:800;text-decoration:none}
    .foodex-enterprise-viewbar__item form{margin:0}
    .foodex-enterprise-viewbar__delete{min-height:32px;border:0;border-radius:999px;padding-inline:9px;background:transparent;color:var(--foodex-danger-text);font:inherit;font-weight:700;cursor:pointer}
    .foodex-enterprise-viewbar__delete:hover{background:var(--foodex-orange-soft)}
    .foodex-enterprise-viewbar__empty{color:var(--foodex-muted);font-size:var(--foodex-text-sm)}
    @media(max-width:767px){.foodex-enterprise-viewbar{padding:12px}.foodex-enterprise-viewbar__head{flex-direction:column}.foodex-enterprise-viewbar__save{width:100%}.foodex-enterprise-viewbar__save>summary{width:100%}.foodex-enterprise-viewbar__form{grid-template-columns:minmax(0,1fr)}.foodex-enterprise-viewbar__items{display:grid}.foodex-enterprise-viewbar__item{border-radius:12px;flex-wrap:wrap}.foodex-enterprise-viewbar__item>a{flex:1 1 150px}}

    @media print{.foodex-global-header{display:none!important}}
</style>

<script id="foodex-mobile-web-foundation">
(() => {
    const enhanceTable = (table) => {
        if (!(table instanceof HTMLTableElement)) return;
        if (table.dataset.foodexMobileTableReady === '1') return;
        if (table.dataset.foodexMobileTable === 'scroll' || table.dataset.foodexMobileTable === 'off') return;
        if (!table.matches('table:not([data-foodex-mobile-table="scroll"]):not([data-foodex-mobile-table="off"])')) return;

        const headers = [...table.querySelectorAll(':scope > thead > tr:last-child > th')];
        const rows = [...table.querySelectorAll(':scope > tbody > tr')];
        if (headers.length < 2 || rows.length === 0) return;

        const labels = headers.map((header) => (header.textContent || '').replace(/\s+/g, ' ').trim());
        rows.forEach((row) => {
            const cells = [...row.children].filter((cell) => cell instanceof HTMLTableCellElement);
            let column = 0;
            cells.forEach((cell) => {
                const span = Math.max(1, Number(cell.colSpan || 1));
                if (!cell.hasAttribute('data-foodex-label')) {
                    cell.dataset.foodexLabel = span > 1 ? '' : (labels[column] || '');
                }
                column += span;
            });
        });
        table.dataset.foodexMobileTableReady = '1';
    };

    const scan = (root = document) => {
        if (root instanceof HTMLTableElement) enhanceTable(root);
        root.querySelectorAll?.('table:not([data-foodex-mobile-table="scroll"]):not([data-foodex-mobile-table="off"])').forEach(enhanceTable);
    };

    const start = () => {
        scan();
        const observer = new MutationObserver((records) => {
            records.forEach((record) => record.addedNodes.forEach((node) => {
                if (node instanceof Element) scan(node);
            }));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
</script>
<script id="foodex-operational-modal-runtime">
(() => {
    const modalA11y = window.FoodexModalA11y || (() => {
        const focusableSelector = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
        const activeStack = [];
        const focusables = (root) => [...root.querySelectorAll(focusableSelector)].filter((node) => {
            if (!(node instanceof HTMLElement) || node.hidden || node.closest('[hidden],[aria-hidden="true"]')) return false;
            const style = window.getComputedStyle(node);
            return style.display !== 'none' && style.visibility !== 'hidden' && node.getClientRects().length > 0;
        });
        const isolateOutside = (root) => {
            const changed = [];
            let current = root;
            while (current instanceof HTMLElement && current !== document.body) {
                const parent = current.parentElement;
                if (!parent) break;
                [...parent.children].forEach((sibling) => {
                    if (sibling === current || !(sibling instanceof HTMLElement) || sibling.inert) return;
                    sibling.inert = true;
                    changed.push(sibling);
                });
                current = parent;
            }
            return () => changed.reverse().forEach((node) => { node.inert = false; });
        };
        const activate = ({ root, dialog, initialFocus = null, returnFocus = null, fallbackFocus = null, onEscape = null }) => {
            if (!(root instanceof HTMLElement) || !(dialog instanceof HTMLElement)) return null;
            const priorFocus = returnFocus instanceof HTMLElement ? returnFocus : document.activeElement;
            const token = Symbol('foodex-modal-session');
            activeStack.push(token);
            const restoreIsolation = isolateOutside(root);
            if (!dialog.hasAttribute('tabindex')) dialog.setAttribute('tabindex', '-1');
            let active = true;
            const focusInitial = () => {
                if (!active || activeStack[activeStack.length - 1] !== token) return;
                const targets = focusables(dialog);
                const target = initialFocus instanceof HTMLElement && !initialFocus.disabled ? initialFocus : (targets[0] || dialog);
                target.focus();
            };
            const onKeydown = (event) => {
                if (!active || activeStack[activeStack.length - 1] !== token || event.defaultPrevented) return;
                if (event.key === 'Escape' && typeof onEscape === 'function') {
                    event.preventDefault();
                    onEscape();
                    return;
                }
                if (event.key !== 'Tab') return;
                const targets = focusables(dialog);
                if (!targets.length) {
                    event.preventDefault();
                    dialog.focus();
                    return;
                }
                const first = targets[0];
                const last = targets[targets.length - 1];
                const outside = !dialog.contains(document.activeElement);
                if (event.shiftKey && (document.activeElement === first || outside)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && (document.activeElement === last || outside)) {
                    event.preventDefault();
                    first.focus();
                }
            };
            document.addEventListener('keydown', onKeydown);
            queueMicrotask(focusInitial);
            return {
                deactivate({ restoreFocus = true } = {}) {
                    if (!active) return;
                    active = false;
                    document.removeEventListener('keydown', onKeydown);
                    const stackIndex = activeStack.lastIndexOf(token);
                    if (stackIndex !== -1) activeStack.splice(stackIndex, 1);
                    restoreIsolation();
                    if (!restoreFocus) return;
                    const target = priorFocus instanceof HTMLElement && priorFocus.isConnected && !priorFocus.inert
                        ? priorFocus
                        : (fallbackFocus instanceof HTMLElement && fallbackFocus.isConnected && !fallbackFocus.inert ? fallbackFocus : null);
                    target?.focus();
                },
            };
        };
        return { activate, focusables };
    })();
    window.FoodexModalA11y = modalA11y;

    const enhance = (details, index) => {
        if (!(details instanceof HTMLDetailsElement) || details.dataset.foodexModalReady === '1') return;
        const summary = details.querySelector(':scope > summary');
        if (!(summary instanceof HTMLElement)) return;
        details.dataset.foodexModalReady = '1';
        const title = summary.textContent.trim();
        const host = document.createElement('div');
        host.className = ['foodex-operational-dialog-host', ...details.classList].join(' ');
        host.style.cssText = details.style.cssText;
        Object.entries(details.dataset).forEach(([key, value]) => { host.dataset[key] = value; });

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = details.dataset.modalButtonClass || summary.className || 'foodex-action-secondary';
        trigger.dataset.foodexDialogOpen = String(index);
        trigger.textContent = title;

        const backdrop = document.createElement('div');
        backdrop.className = 'foodex-modal-backdrop';
        backdrop.hidden = true;
        backdrop.dataset.foodexDialog = String(index);

        const dialog = document.createElement('section');
        dialog.className = 'foodex-modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        const headingId = 'foodex-dialog-title-' + index;
        dialog.setAttribute('aria-labelledby', headingId);

        const header = document.createElement('div');
        header.className = 'foodex-modal-header';
        const heading = document.createElement('h3');
        heading.id = headingId;
        heading.textContent = title;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'foodex-modal-close';
        close.setAttribute('aria-label', document.documentElement.dir === 'rtl' ? 'إغلاق' : 'Close');
        close.textContent = '×';
        header.append(heading, close);

        const body = document.createElement('div');
        body.className = 'foodex-modal-body';
        [...details.children].filter((child) => child !== summary).forEach((child) => body.appendChild(child));
        dialog.append(header, body);
        backdrop.appendChild(dialog);
        host.append(trigger, backdrop);
        details.replaceWith(host);

        let modalSession = null;
        const shut = () => {
            if (backdrop.hidden) return;
            modalSession?.deactivate();
            modalSession = null;
            backdrop.hidden = true;
            document.body.classList.remove('foodex-modal-open');
        };
        const open = () => {
            if (!backdrop.hidden) return;
            backdrop.hidden = false;
            document.body.classList.add('foodex-modal-open');
            modalSession = modalA11y.activate({
                root: backdrop,
                dialog,
                initialFocus: close,
                returnFocus: trigger,
                fallbackFocus: trigger,
                onEscape: shut,
            });
        };
        trigger.addEventListener('click', open);
        close.addEventListener('click', shut);
        backdrop.addEventListener('click', (event) => { if (event.target === backdrop) shut(); });
    };
    const initOperationalModals = () => {
        document.querySelectorAll('details[data-foodex-operational-modal]').forEach(enhance);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initOperationalModals, { once: true });
    } else {
        initOperationalModals();
    }
})();
</script>
<script id="foodex-ops-popover-runtime">
(() => {
    const portals = new WeakMap();
    const openIntents = new WeakMap();
    const focusableSelector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');
    let disclosureSequence = 0;

    const isEllipsisActions = (details) => {
        if (!(details instanceof HTMLDetailsElement)) return false;
        const summary = details.querySelector(':scope > summary');
        const label = (summary?.textContent || '').trim();
        return label === '⋮' || label === '…' || label === '...'
            || details.classList.contains('foodex-ops-actions')
            || details.classList.contains('row-actions')
            || details.classList.contains('catalog-actions');
    };

    const actionMenus = () => [...document.querySelectorAll('details[open]')].filter(isEllipsisActions);

    const findMenu = (details) => {
        const portal = portals.get(details);
        if (portal?.menu?.isConnected) return portal.menu;
        return details.querySelector(
            ':scope > .foodex-ops-menu, :scope > .row-action-menu, :scope > .catalog-action-menu'
        ) || [...details.children].find((child) => child instanceof HTMLElement && child.tagName !== 'SUMMARY');
    };

    const configureDisclosure = (details, menu = findMenu(details)) => {
        if (!(details instanceof HTMLDetailsElement) || !(menu instanceof HTMLElement)) return null;
        const summary = details.querySelector(':scope > summary');
        if (!(summary instanceof HTMLElement)) return null;

        disclosureSequence += 1;
        if (!summary.id) summary.id = 'foodex-row-action-trigger-' + disclosureSequence;
        if (!menu.id) menu.id = 'foodex-row-action-panel-' + disclosureSequence;
        summary.setAttribute('aria-controls', menu.id);
        summary.setAttribute('aria-expanded', details.open ? 'true' : 'false');
        menu.setAttribute('aria-labelledby', summary.id);
        return { summary, menu };
    };

    const portalMenu = (details) => {
        const existing = portals.get(details);
        if (existing?.menu?.isConnected) return existing.menu;

        const menu = findMenu(details);
        if (!(menu instanceof HTMLElement)) return null;
        configureDisclosure(details, menu);

        const placeholder = document.createComment('foodex-action-menu-portal');
        menu.parentNode?.insertBefore(placeholder, menu);
        document.body.appendChild(menu);
        menu.dataset.foodexActionPortal = '1';
        portals.set(details, { menu, placeholder });
        return menu;
    };

    const restoreMenu = (details) => {
        const portal = portals.get(details);
        if (!portal) return;
        const { menu, placeholder } = portal;
        if (placeholder?.parentNode && menu) placeholder.parentNode.insertBefore(menu, placeholder);
        placeholder?.remove();
        menu?.removeAttribute('data-foodex-action-portal');
        if (menu instanceof HTMLElement) {
            menu.style.position = '';
            menu.style.zIndex = '';
            menu.style.visibility = '';
            menu.style.inset = '';
            menu.style.insetInlineStart = '';
            menu.style.insetInlineEnd = '';
            menu.style.right = '';
            menu.style.bottom = '';
            menu.style.maxWidth = '';
            menu.style.top = '';
            menu.style.left = '';
        }
        portals.delete(details);
    };

    const positionMenu = (details) => {
        if (!isEllipsisActions(details) || !details.open) return;
        const summary = details.querySelector(':scope > summary');
        const menu = portalMenu(details);
        if (!(summary instanceof HTMLElement) || !(menu instanceof HTMLElement)) return;

        const trigger = summary.getBoundingClientRect();
        const margin = 8;

        menu.style.position = 'fixed';
        menu.style.zIndex = '10050';
        menu.style.visibility = 'hidden';
        menu.style.inset = 'auto';
        menu.style.insetInlineStart = 'auto';
        menu.style.insetInlineEnd = 'auto';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';
        menu.style.maxWidth = 'min(320px, calc(100vw - 16px))';
        menu.style.top = '0px';
        menu.style.left = '0px';

        const menuRect = menu.getBoundingClientRect();
        const inwardLeft = trigger.right - menuRect.width;
        const outwardLeft = trigger.left;
        const fitsInward = inwardLeft >= margin && inwardLeft + menuRect.width <= window.innerWidth - margin;
        const fitsOutward = outwardLeft >= margin && outwardLeft + menuRect.width <= window.innerWidth - margin;
        let left = fitsInward ? inwardLeft : (fitsOutward ? outwardLeft : inwardLeft);
        left = Math.max(margin, Math.min(left, window.innerWidth - menuRect.width - margin));

        let top = trigger.bottom + 6;
        if (top + menuRect.height > window.innerHeight - margin && trigger.top - menuRect.height - 6 >= margin) {
            top = trigger.top - menuRect.height - 6;
        }
        top = Math.max(margin, Math.min(top, window.innerHeight - menuRect.height - margin));

        menu.style.left = Math.round(left) + 'px';
        menu.style.top = Math.round(top) + 'px';
        menu.style.visibility = 'visible';
    };

    const focusFirstAction = (details) => {
        const menu = findMenu(details);
        if (!(menu instanceof HTMLElement)) return;
        const target = [...menu.querySelectorAll(focusableSelector)]
            .find((element) => element instanceof HTMLElement && element.getClientRects().length > 0);
        target?.focus();
    };

    const closeMenu = (details, { restoreFocus = false } = {}) => {
        if (!(details instanceof HTMLDetailsElement)) return;
        const summary = details.querySelector(':scope > summary');
        details.open = false;
        if (summary instanceof HTMLElement) summary.setAttribute('aria-expanded', 'false');
        restoreMenu(details);
        if (restoreFocus && summary instanceof HTMLElement && summary.isConnected) {
            summary.focus();
        }
    };

    const repositionOpenMenus = () => actionMenus().forEach(positionMenu);

    const initDisclosures = () => {
        document.querySelectorAll('details').forEach((details) => {
            if (isEllipsisActions(details)) configureDisclosure(details);
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDisclosures, { once: true });
    } else {
        initDisclosures();
    }

    document.addEventListener('pointerdown', (event) => {
        const summary = event.target instanceof Element ? event.target.closest('summary') : null;
        const details = summary?.parentElement;
        if (isEllipsisActions(details)) openIntents.set(details, 'pointer');
    }, true);

    document.addEventListener('keydown', (event) => {
        const summary = event.target instanceof Element ? event.target.closest('summary') : null;
        const details = summary?.parentElement;
        if (isEllipsisActions(details) && (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar')) {
            openIntents.set(details, 'keyboard');
            return;
        }

        if (event.key !== 'Escape') return;
        const active = document.activeElement;
        const opened = actionMenus();
        const detailsToClose = opened.find((item) => {
            const menu = portals.get(item)?.menu;
            return menu instanceof HTMLElement && active instanceof Node && menu.contains(active);
        }) || opened.at(-1);
        if (!detailsToClose) return;
        event.preventDefault();
        event.stopPropagation();
        closeMenu(detailsToClose, { restoreFocus: true });
    }, true);

    document.addEventListener('toggle', (event) => {
        const details = event.target;
        if (!isEllipsisActions(details)) return;
        const configured = configureDisclosure(details);

        if (details.open) {
            actionMenus().forEach((other) => {
                if (other !== details) closeMenu(other, { restoreFocus: false });
            });
            const intent = openIntents.get(details);
            openIntents.delete(details);
            requestAnimationFrame(() => {
                positionMenu(details);
                if (intent === 'keyboard') focusFirstAction(details);
            });
        } else {
            if (configured?.summary instanceof HTMLElement) configured.summary.setAttribute('aria-expanded', 'false');
            restoreMenu(details);
        }
    }, true);

    document.addEventListener('click', (event) => {
        actionMenus().forEach((details) => {
            const portal = portals.get(details);
            const insideTrigger = details.contains(event.target);
            const insideMenu = portal?.menu instanceof HTMLElement && portal.menu.contains(event.target);
            if (insideTrigger || insideMenu) return;
            const focusWasInsideMenu = portal?.menu instanceof HTMLElement
                && document.activeElement instanceof Node
                && portal.menu.contains(document.activeElement);
            closeMenu(details, { restoreFocus: focusWasInsideMenu });
        });
    });

    window.addEventListener('resize', repositionOpenMenus, { passive: true });
    document.addEventListener('scroll', repositionOpenMenus, true);
})();
</script>

<script id="foodex-placeholder-audit">
document.addEventListener('DOMContentLoaded', () => {
    const isArabic = document.documentElement.lang.toLowerCase().startsWith('ar');
    const eligible = 'input:not([type]),input[type="text"],input[type="email"],input[type="password"],input[type="number"],input[type="url"],input[type="search"],input[type="tel"],textarea';
    document.querySelectorAll(eligible).forEach((field) => {
        if (field.hasAttribute('placeholder')) return;
        const label = field.closest('label');
        let labelText = label ? label.cloneNode(true) : null;
        if (labelText) labelText.querySelectorAll('input,textarea,select,button').forEach((node) => node.remove());
        const raw = (field.getAttribute('aria-label') || labelText?.textContent || field.name || '').trim().replace(/\s+/g, ' ');
        const fallback = isArabic ? 'أدخل القيمة المطلوبة' : 'Enter the required value';
        field.setAttribute('placeholder', raw ? (isArabic ? 'أدخل ' + raw : 'Enter ' + raw) : fallback);
    });
});
</script>


<style id="foodex-premium-form-sweep">
    .foodex-premium-auto-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:var(--foodex-space-4);align-items:end;padding:var(--foodex-space-5);background:linear-gradient(180deg,#fff,#fbfcfd);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
    .foodex-premium-auto-form>button,.foodex-premium-auto-form>.foodex-action-primary,.foodex-premium-auto-form>.foodex-action-secondary{align-self:end}
    .foodex-auto-field{display:grid!important;gap:7px!important;min-width:0;font-weight:var(--foodex-font-weight-medium)!important;color:var(--foodex-ink)}
    .foodex-auto-field>.foodex-field-caption,.foodex-field-caption{display:flex;align-items:center;gap:5px;font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-bold);color:#344054;line-height:1.35}
    .foodex-required-mark{color:var(--foodex-danger-text);font-family:var(--foodex-font-en)}
    .foodex-control-premium{width:100%;transition:border-color .16s ease,box-shadow .16s ease,background .16s ease}
    .foodex-control-premium:hover{border-color:#b6c2d1}
    .foodex-control-premium[aria-invalid="true"],.foodex-control-premium:invalid:not(:placeholder-shown){border-color:#e36a6a}
    textarea.foodex-control-premium{min-height:108px;padding-block:10px;resize:vertical}
    select.foodex-control-premium{padding-inline-end:36px}
    input[type="file"].foodex-control-premium{min-height:52px;padding:6px;background:#fbfcfd;border-style:dashed}
    input[type="file"].foodex-control-premium::file-selector-button{min-height:36px;margin-inline-end:10px;border:0;border-radius:8px;padding:0 13px;background:var(--foodex-green-soft);color:var(--foodex-green-dark);font:inherit;font-weight:700;cursor:pointer}
    .foodex-file-help{font-size:11px;color:var(--foodex-muted);line-height:1.5}
    .foodex-image-preview{display:flex;flex-wrap:wrap;gap:7px;margin-top:4px}
    .foodex-image-preview img{width:58px;height:58px;object-fit:cover;border:1px solid var(--foodex-border);border-radius:10px;background:#fff}
    .foodex-tabs a,.tabs a,.workspace-tabs a,.foodex-tab{gap:7px!important}
    .foodex-tab-icon{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:8px;background:rgba(21,138,58,.08);font-size:13px;line-height:1}
    .active>.foodex-tab-icon,.foodex-tabs a.active .foodex-tab-icon,.tabs a.active .foodex-tab-icon{background:rgba(255,255,255,.18)}
    .foodex-step-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 var(--foodex-space-4);padding-bottom:var(--foodex-space-2);border-bottom:1px solid var(--foodex-border)}
    .foodex-step-tab{min-height:44px;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);padding:0 14px;background:#fff;color:var(--foodex-ink);display:inline-flex;align-items:center;gap:8px;font:inherit;font-weight:700;cursor:pointer}
    .foodex-step-tab[aria-selected="true"]{background:var(--foodex-action-green);border-color:var(--foodex-action-green);color:#fff}
    .foodex-step-panel[hidden]{display:none!important}
    .foodex-step-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:var(--foodex-space-4)}
    .foodex-feedback-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:20px;background:rgba(17,24,39,.48);backdrop-filter:blur(2px)}
    .foodex-feedback-modal .modal-dialog{width:min(100%,520px)}
    .foodex-feedback-modal .modal-content{overflow:hidden;background:#fff;border:1px solid var(--foodex-border);border-radius:18px;box-shadow:0 28px 72px rgba(16,24,40,.28)}
    .foodex-feedback-modal .modal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--foodex-border)}
    .foodex-feedback-modal .modal-title{margin:0;font-size:1.08rem;font-weight:800}
    .foodex-feedback-modal .modal-body{padding:20px;line-height:1.7}
    .foodex-feedback-modal .modal-body ul{margin:0;padding-inline-start:20px}
    .foodex-feedback-modal .modal-body a[data-foodex-validation-link]{color:inherit;font-weight:800;text-decoration:underline;text-underline-offset:2px}
    .foodex-field-error{margin-top:6px;color:#b42318;font-size:var(--foodex-text-xs);line-height:1.5}
    .foodex-field-error[hidden]{display:none!important}
    .foodex-feedback-modal .btn-close{width:36px;height:36px;border:0;border-radius:10px;background:#f2f4f7;color:#475467;cursor:pointer;font-size:20px}
    .foodex-feedback-modal.is-success .modal-header{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
    .foodex-feedback-modal.is-error .modal-header{background:#fff0f0;color:#b42318}
    .foodex-feedback-icon{width:34px;height:34px;display:inline-grid;place-items:center;border-radius:999px;background:#fff;font-weight:900}
    .foodex-feedback-title-wrap{display:flex;align-items:center;gap:10px}
    .foodex-password-control{position:relative;display:flex;align-items:center;min-width:0}
    .foodex-password-control>input{width:100%;padding-inline-end:48px!important}
    .foodex-password-toggle{position:absolute;inset-inline-end:6px;width:38px;height:38px;border:0;border-radius:10px;background:transparent;color:var(--foodex-muted);display:grid;place-items:center;cursor:pointer;font-size:19px;line-height:1}
    .foodex-password-toggle:hover{background:var(--foodex-green-soft);color:var(--foodex-green-dark)}
    @media(max-width:640px){.foodex-premium-auto-form{grid-template-columns:1fr;padding:var(--foodex-space-4)}}

    /* Customer-service role visual profile: keep every authorized admin page aligned with the CS dashboard shell. */
    html.foodex-customer-service-profile{
        --foodex-cs-card-radius:18px;
        --foodex-cs-control-radius:12px;
        --foodex-cs-page-gap:14px;
    }
    html.foodex-customer-service-profile .foodex-admin-layout{
        background:linear-gradient(180deg,#f8fafc 0%,var(--foodex-background) 34%,var(--foodex-background) 100%);
    }
    html.foodex-customer-service-profile .foodex-admin-layout>.foodex-admin-main,
    html.foodex-customer-service-profile .foodex-admin-main.foodex-admin-page{
        padding:clamp(18px,1.45vw,28px)!important;
    }
    html.foodex-customer-service-profile .foodex-global-header{
        margin-bottom:16px;
        border-radius:14px;
        box-shadow:0 7px 22px rgba(15,23,42,.055);
    }
    html.foodex-customer-service-profile .foodex-page-toolbar{
        min-height:64px;
        align-items:center;
        margin-bottom:14px;
        padding:11px 14px;
        border:1px solid var(--foodex-border);
        border-radius:16px;
        background:var(--foodex-surface);
        box-shadow:var(--foodex-shadow-sm);
    }
    html.foodex-customer-service-profile .foodex-page-toolbar h1{
        font-size:1.02rem!important;
        line-height:1.3!important;
    }
    html.foodex-customer-service-profile .foodex-page-toolbar p{
        margin-top:2px!important;
        font-size:.72rem!important;
    }
    html.foodex-customer-service-profile :is(.foodex-card,.panel,.card,.filters,.back,.foodex-form){
        border-radius:var(--foodex-cs-card-radius)!important;
        border-color:#dde5ec!important;
        box-shadow:0 7px 22px rgba(15,23,42,.045);
    }
    html.foodex-customer-service-profile :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea,.foodex-control){
        border-radius:var(--foodex-cs-control-radius);
    }
    html.foodex-customer-service-profile :is(.primary,.button,.btn.primary,button.foodex-primary,.foodex-action-primary,.foodex-filter-action,.secondary,.button.secondary,.btn.secondary,.foodex-action-secondary){
        border-radius:11px;
    }
    html.foodex-customer-service-profile .foodex-tabs{
        gap:8px;
        margin-bottom:14px;
        padding:0;
        border-bottom:0;
        overflow-x:auto;
        flex-wrap:nowrap;
        scrollbar-width:thin;
    }
    html.foodex-customer-service-profile .foodex-tabs :is(a,.foodex-tab){
        flex:0 0 auto;
        min-height:38px;
        padding:0 16px;
        border:1px solid var(--foodex-border);
        border-radius:999px;
        background:var(--foodex-surface);
        color:var(--foodex-muted);
        font-size:.78rem;
        font-weight:800;
        white-space:nowrap;
        transition:background .16s ease,color .16s ease,border-color .16s ease,transform .16s ease;
    }
    html.foodex-customer-service-profile .foodex-tabs :is(a,.foodex-tab):hover{
        transform:translateY(-1px);
        border-color:#b8d9c2;
        color:var(--foodex-green-dark);
    }
    html.foodex-customer-service-profile .foodex-tabs :is(a.active,.foodex-tab.active,[aria-selected="true"]){
        border-color:var(--foodex-action-green)!important;
        background:var(--foodex-action-green)!important;
        color:#fff!important;
    }
    html.foodex-customer-service-profile :is(.table-wrap,.module-table-wrap,.foodex-table-wrap){
        border-radius:16px;
        scrollbar-width:thin;
    }
    html.foodex-customer-service-profile :is(.foodex-table,.foodex-ops-grid){
        border-radius:16px;
    }
    html.foodex-customer-service-profile :is(.foodex-table,.foodex-ops-grid) th{
        background:#f8fafc;
    }
    html.foodex-customer-service-profile :is(.foodex-modal,.foodex-record-drawer){
        border-color:#dde5ec;
        box-shadow:0 28px 80px rgba(15,23,42,.22);
    }
    html.foodex-customer-service-profile .foodex-modal{
        border-radius:20px;
    }
    html.foodex-customer-service-profile .foodex-record-drawer-shell{
        scrollbar-width:thin;
        overscroll-behavior:contain;
    }
    html.foodex-customer-service-profile .foodex-sidebar-shell{
        gap:12px;
    }
    html.foodex-customer-service-profile .foodex-nav-group[open]{
        border-radius:14px;
        background:#fbfcfd;
    }
    html.foodex-customer-service-profile .foodex-nav-link{
        border-radius:11px;
    }
    html.foodex-customer-service-profile .foodex-nav-link.active{
        background:var(--foodex-action-green);
        box-shadow:0 8px 20px rgba(21,138,58,.16);
    }
    @media(max-width:767px){
        html.foodex-customer-service-profile .foodex-admin-layout>.foodex-admin-main,
        html.foodex-customer-service-profile .foodex-admin-main.foodex-admin-page{
            padding:12px!important;
        }
        html.foodex-customer-service-profile .foodex-page-toolbar{
            min-height:0;
            padding:10px 12px;
            border-radius:14px;
        }
        html.foodex-customer-service-profile :is(.foodex-card,.panel,.card,.filters,.back,.foodex-form){
            border-radius:14px!important;
        }
    }

</style>

@php
    $foodexUiStatus = session('status');
    $foodexUiErrors = isset($errors) && $errors->any() ? $errors->messages() : [];
@endphp
<script id="foodex-premium-form-runtime">
(() => {
    const isArabic = document.documentElement.lang.toLowerCase().startsWith('ar');
    const inspectorEnabled = @json(auth()->check());
    const inspectorUrl = @json(route('admin.inspector.client-events'));
    const csrfToken = @json(csrf_token());
    let inspectorSuppressed = false;
    let inspectorSuppressedUntil = 0;
    const currentXsrfToken = () => {
        const row = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
        return row ? decodeURIComponent(row.substring('XSRF-TOKEN='.length)) : null;
    };
    const feedback = {
        status: @json($foodexUiStatus),
        errors: @json($foodexUiErrors),
    };

    const fieldLabels = {
        code:['الكود','Code'], name:['الاسم','Name'], name_ar:['الاسم بالعربية','Arabic name'], name_en:['الاسم بالإنجليزية','English name'],
        email:['البريد الإلكتروني','Email'], phone:['رقم الهاتف','Phone'], password:['كلمة المرور','Password'], manager_name:['اسم المدير','Manager name'],
        manager_email:['بريد المدير','Manager email'], manager_password:['كلمة مرور المدير','Manager password'], manager_mode:['طريقة تعيين المدير','Manager mode'],
        manager_user_id:['المستخدم المدير','Manager user'], store_id:['المتجر','Store'], store_type_id:['نوع المتجر','Store type'], role_id:['الدور','Role'],
        user_id:['المستخدم','User'], sku:['رمز المنتج','SKU'], description:['الوصف','Description'], category_id:['التصنيف','Category'],
        parent_id:['التصنيف الأب','Parent category'], brand_id:['العلامة التجارية','Brand'], unit_id:['وحدة القياس','Unit'], price:['السعر','Price'],
        quantity:['الكمية','Quantity'], quantity_delta:['التغيير في الكمية','Quantity adjustment'], reason:['السبب','Reason'], slug:['المعرّف النصي','Slug'],
        decimal_places:['المنازل العشرية','Decimal places'], sort_order:['الترتيب','Sort order'], title:['العنوان','Title'], target_url:['الرابط المستهدف','Target URL'],
        key:['المفتاح','Key'], value:['القيمة','Value'], images:['صور المنتج','Product images'], category_image:['صورة التصنيف','Category image'],
        brand_image:['صورة العلامة التجارية','Brand image'], q:['بحث','Search'], status:['الحالة','Status'], type:['النوع','Type'], unit_price:['سعر الوحدة','Unit price'],
        minimum_quantity:['الحد الأدنى للكمية','Minimum quantity'], inventory_id:['الصنف والمخزن','Inventory item'], driver_id:['السائق','Driver'],
        order_id:['الطلب','Order'], confirmation:['التأكيد','Confirmation']
    };

    const nativeFetch = typeof window.fetch === 'function' ? window.fetch.bind(window) : null;
    const cleanName = (field) => (field.name || field.id || '').replace(/\[\]$/,'').split('.').pop();
    const labelFor = (field) => {
        const key = cleanName(field);
        if (fieldLabels[key]) return fieldLabels[key][isArabic ? 0 : 1];
        const aria = field.getAttribute('aria-label');
        if (aria) return aria;
        const placeholder = field.getAttribute('placeholder');
        if (placeholder && !/^e\.g\.|^مثال[:：]?/i.test(placeholder)) return placeholder;
        return key ? key.replace(/[_-]+/g,' ').replace(/\b\w/g, ch => ch.toUpperCase()) : (isArabic ? 'الحقل' : 'Field');
    };

    const normalizeValidationKey = (value) => String(value || '')
        .replace(/\[([^\]]*)\]/g, (_, segment) => '.'+(segment === '' ? '*' : segment))
        .replace(/^\./,'')
        .replace(/\.+/g,'.')
        .replace(/\.$/,'');
    const wildcardValidationKey = (value) => normalizeValidationKey(value)
        .split('.')
        .map((segment) => /^\d+$/.test(segment) ? '*' : segment)
        .join('.');
    const validationErrorId = (value) => {
        const normalized = normalizeValidationKey(value) || 'form';
        return 'foodex-server-error-'+normalized.replace(/[^A-Za-z0-9_-]+/g,'-').replace(/^-+|-+$/g,'');
    };
    const feedbackEntries = (messages) => {
        if (messages === null || messages === undefined || messages === '') return [];
        if (Array.isArray(messages)) {
            return messages.flatMap((message) => {
                if (message && typeof message === 'object' && !Array.isArray(message)) return feedbackEntries(message);
                return message === null || message === undefined || message === '' ? [] : [{ key:null, message:String(message) }];
            });
        }
        if (typeof messages === 'object') {
            return Object.entries(messages).flatMap(([key, values]) => {
                const list = Array.isArray(values) ? values : [values];
                return list
                    .filter((message) => message !== null && message !== undefined && message !== '')
                    .map((message) => ({ key:normalizeValidationKey(key), message:String(message) }));
            });
        }
        return [{ key:null, message:String(messages) }];
    };
    const hasFeedbackErrors = (messages) => feedbackEntries(messages).length > 0;
    const namedValidationControls = () => [...document.querySelectorAll('input[name],select[name],textarea[name]')];
    const controlsForValidationKey = (key) => {
        const normalized = normalizeValidationKey(key);
        if (!normalized) return [];
        const controls = namedValidationControls();
        const exact = controls.filter((field) => normalizeValidationKey(field.name) === normalized);
        if (exact.length) return exact;
        const wildcard = wildcardValidationKey(normalized);
        return controls.filter((field) => {
            const fieldKey = normalizeValidationKey(field.name);
            return fieldKey === wildcard || wildcardValidationKey(fieldKey) === wildcard;
        });
    };
    const isVisibleValidationControl = (field) => {
        if (!(field instanceof HTMLElement) || field.hidden || field.closest('[hidden],[aria-hidden="true"],[inert]')) return false;
        const style = window.getComputedStyle(field);
        return style.display !== 'none' && style.visibility !== 'hidden' && field.getClientRects().length > 0;
    };
    const ensureValidationControlId = (field, key, index = 0) => {
        if (field.id) return field.id;
        const base = 'foodex-validation-field-'+normalizeValidationKey(key).replace(/[^A-Za-z0-9_-]+/g,'-');
        field.id = index > 0 ? base+'-'+index : base;
        return field.id;
    };
    const addDescribedBy = (field, id) => {
        const tokens = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (!tokens.includes(id)) tokens.push(id);
        field.setAttribute('aria-describedby', tokens.join(' '));
    };
    const removeDescribedBy = (field, id) => {
        const tokens = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter((token) => token && token !== id);
        if (tokens.length) field.setAttribute('aria-describedby', tokens.join(' '));
        else field.removeAttribute('aria-describedby');
    };
    const removeNativeSummaryKey = (key) => {
        const normalized = normalizeValidationKey(key);
        document.querySelectorAll('[data-foodex-error-summary-key]').forEach((item) => {
            if (normalizeValidationKey(item.getAttribute('data-foodex-error-summary-key')) === normalized) item.remove();
        });
        document.querySelectorAll('[data-foodex-native-error-summary]').forEach((summary) => {
            if (!summary.querySelector('[data-foodex-error-summary-key]')) summary.remove();
        });
    };
    const clearFieldValidation = (field) => {
        if (!(field instanceof HTMLElement)) return;
        const errorId = field.dataset.foodexServerErrorId;
        const key = field.dataset.foodexServerErrorKey || normalizeValidationKey(field.getAttribute('name'));
        if (errorId) {
            removeDescribedBy(field, errorId);
            if (field.getAttribute('aria-errormessage') === errorId) field.removeAttribute('aria-errormessage');
            const node = document.getElementById(errorId);
            if (node?.dataset.foodexServerError === '1') node.remove();
        }
        if (field.dataset.foodexServerInvalid === '1') field.removeAttribute('aria-invalid');
        delete field.dataset.foodexServerInvalid;
        delete field.dataset.foodexServerErrorId;
        delete field.dataset.foodexServerErrorKey;
        if (key) removeNativeSummaryKey(key);
    };
    const bindValidationClearing = (field) => {
        if (field.dataset.foodexServerValidationReady === '1') return;
        field.dataset.foodexServerValidationReady = '1';
        const clear = () => clearFieldValidation(field);
        field.addEventListener('input', clear);
        field.addEventListener('change', clear);
    };
    const clearServerValidation = () => {
        namedValidationControls().forEach((field) => {
            if (field.dataset.foodexServerInvalid === '1') clearFieldValidation(field);
        });
        document.querySelectorAll('[data-foodex-server-error="1"]').forEach((node) => node.remove());
    };
    const applyServerValidation = (messages, scope = null) => {
        const entries = feedbackEntries(messages);
        const grouped = new Map();
        entries.forEach((entry) => {
            if (!entry.key) return;
            if (!grouped.has(entry.key)) grouped.set(entry.key, []);
            grouped.get(entry.key).push(entry.message);
        });
        const associations = new Map();
        const scopedRoot = scope instanceof Element ? scope : null;

        grouped.forEach((keyMessages, key) => {
            const controls = controlsForValidationKey(key)
                .filter((field) => !scopedRoot || scopedRoot.contains(field));
            controls.forEach((field, index) => {
                const actualKey = normalizeValidationKey(field.name) || key;
                const errorId = validationErrorId(actualKey);
                ensureValidationControlId(field, actualKey, index);
                let inline = document.getElementById(errorId);
                if (!(inline instanceof HTMLElement)) {
                    inline = document.createElement('div');
                    inline.id = errorId;
                    inline.className = 'foodex-field-error';
                    inline.dataset.foodexServerError = '1';
                    const placement = field.closest('.foodex-password-control') || field;
                    placement.insertAdjacentElement('afterend', inline);
                }
                inline.dataset.foodexServerError = '1';
                inline.dataset.foodexServerErrorKey = key;
                inline.textContent = [...new Set(keyMessages)].join(' ');
                field.setAttribute('aria-invalid','true');
                field.setAttribute('aria-errormessage', errorId);
                addDescribedBy(field, errorId);
                field.dataset.foodexServerInvalid = '1';
                field.dataset.foodexServerErrorId = errorId;
                field.dataset.foodexServerErrorKey = key;
                bindValidationClearing(field);
                if (!associations.has(key) && isVisibleValidationControl(field)) associations.set(key, field);
            });
        });

        return associations;
    };
    window.FoodexValidationA11y = {
        apply: applyServerValidation,
        clear: clearServerValidation,
        controlsForKey: controlsForValidationKey,
        normalizeKey: normalizeValidationKey,
    };

    const enhanceForms = () => {
        document.querySelectorAll('form').forEach((form) => {
            const controls = [...form.querySelectorAll('input,select,textarea')].filter((field) => {
                const type = (field.getAttribute('type') || '').toLowerCase();
                return !['hidden','submit','button','reset','checkbox','radio'].includes(type);
            });

            const compact = form.matches('.inline-form,.module-inline-form,.workspace-inline-form,.toolbar,.global-search,.date-control,.logout-form') || !!form.closest('td');
            if (!compact && controls.length > 1) form.classList.add('foodex-premium-auto-form');

            controls.forEach((field) => {
                field.classList.add('foodex-control-premium');
                const type = (field.getAttribute('type') || '').toLowerCase();
                const label = labelFor(field);
                const isWrapped = !!field.closest('label');
                const labelled = isWrapped || !!field.getAttribute('aria-label') || (field.id && document.querySelector('label[for="'+CSS.escape(field.id)+'"]'));

                if (!labelled) {
                    const wrapper = document.createElement('label');
                    wrapper.className = 'foodex-auto-field';
                    const caption = document.createElement('span');
                    caption.className = 'foodex-field-caption';
                    caption.textContent = label;
                    if (field.required) {
                        const required = document.createElement('span');
                        required.className = 'foodex-required-mark';
                        required.textContent = '*';
                        caption.appendChild(required);
                    }
                    field.parentNode?.insertBefore(wrapper, field);
                    wrapper.appendChild(caption);
                    wrapper.appendChild(field);
                } else if (!field.getAttribute('aria-label')) {
                    field.setAttribute('aria-label', label);
                }

                if (!field.getAttribute('placeholder') && ['text','email','password','number','url','search','tel',''].includes(type) && field.tagName !== 'SELECT') {
                    field.setAttribute('placeholder', isArabic ? 'أدخل '+label : 'Enter '+label.toLowerCase());
                }

                if (type === 'file' && (field.accept || '').includes('image')) {
                    const owner = field.closest('label') || field.parentElement;
                    field.addEventListener('change', () => {
                        const owner = field.closest('label') || field.parentElement;
                        if (!owner) return;
                        owner.querySelector('.foodex-image-preview')?.remove();
                        const files = [...(field.files || [])].filter(file => file.type.startsWith('image/'));
                        if (!files.length) return;
                        const preview = document.createElement('div');
                        preview.className = 'foodex-image-preview';
                        files.slice(0,8).forEach((file) => {
                            const img = document.createElement('img');
                            const objectUrl = URL.createObjectURL(file);
                            img.src = objectUrl;
                            img.alt = file.name;
                            img.onload = () => URL.revokeObjectURL(objectUrl);
                            preview.appendChild(img);
                        });
                        owner.appendChild(preview);
                    });
                }
            });
        });
    };

    const enhancePasswords = () => {
        document.querySelectorAll('input[type="password"]').forEach((field) => {
            if (field.dataset.foodexPasswordReady === '1') return;
            field.dataset.foodexPasswordReady = '1';
            const holder = document.createElement('span');
            holder.className = 'foodex-password-control';
            field.parentNode?.insertBefore(holder, field);
            holder.appendChild(field);
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'foodex-password-toggle';
            toggle.setAttribute('aria-label', isArabic ? 'إظهار كلمة المرور' : 'Show password');
            toggle.setAttribute('aria-pressed', 'false');
            toggle.textContent = '◉';
            toggle.addEventListener('click', () => {
                const showing = field.type === 'text';
                field.type = showing ? 'password' : 'text';
                toggle.setAttribute('aria-pressed', showing ? 'false' : 'true');
                toggle.setAttribute('aria-label', isArabic
                    ? (showing ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور')
                    : (showing ? 'Show password' : 'Hide password'));
                toggle.textContent = showing ? '◉' : '⊘';
            });
            holder.appendChild(toggle);
        });
    };

    const enhanceTabs = () => {
        const iconFor = (element) => {
            const haystack = ((element.getAttribute('href') || '')+' '+element.textContent).toLowerCase();
            if (/product|منتج/.test(haystack)) return '▣';
            if (/categor|تصنيف/.test(haystack)) return '▤';
            if (/brand|علام/.test(haystack)) return '◆';
            if (/unit|وحد/.test(haystack)) return '⌁';
            if (/order|طلب/.test(haystack)) return '▧';
            if (/customer|client|عميل/.test(haystack)) return '◎';
            if (/invent|مخزون/.test(haystack)) return '▦';
            if (/report|تقرير/.test(haystack)) return '▥';
            if (/setting|إعداد/.test(haystack)) return '⚙';
            if (/driver|سائق|delivery|توصيل/.test(haystack)) return '↗';
            if (/notification|إشعار/.test(haystack)) return '◉';
            if (/store|متجر/.test(haystack)) return '⌂';
            return '•';
        };
        document.querySelectorAll('.foodex-tabs a,.tabs a,.workspace-tabs a,[role="tab"]').forEach((tab) => {
            if (tab.querySelector('.foodex-tab-icon')) return;
            const icon = document.createElement('span');
            icon.className = 'foodex-tab-icon';
            icon.setAttribute('aria-hidden','true');
            icon.textContent = iconFor(tab);
            tab.prepend(icon);
        });
    };

    const showFeedback = (kind, messages) => {
        const entries = feedbackEntries(messages);
        const clean = entries.map((entry) => entry.message).filter(Boolean);
        if (!clean.length) return;
        window.FoodexFeedbackClose?.();

        const validationScope = kind === 'error' && document.activeElement instanceof HTMLElement
            ? document.activeElement.closest('form')
            : null;
        const associations = kind === 'error' ? applyServerValidation(messages, validationScope) : new Map();
        const overlay = document.createElement('div');
        overlay.className = 'foodex-feedback-modal modal fade show '+(kind === 'success' ? 'is-success' : 'is-error');
        overlay.setAttribute('role','dialog');
        overlay.setAttribute('aria-modal','true');
        const headingId = 'foodex-feedback-title';
        overlay.setAttribute('aria-labelledby', headingId);
        overlay.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-header"><div class="foodex-feedback-title-wrap"><span class="foodex-feedback-icon" aria-hidden="true">'+(kind === 'success' ? '✓' : '!')+'</span><h2 class="modal-title" id="'+headingId+'"></h2></div><button class="btn-close" type="button">×</button></div><div class="modal-body"></div></div></div>';
        overlay.querySelector('.modal-title').textContent = kind === 'success'
            ? (isArabic ? 'تمت العملية بنجاح' : 'Saved successfully')
            : (isArabic ? 'تعذر إكمال العملية' : 'Action could not be completed');
        const closeButton = overlay.querySelector('.btn-close');
        closeButton.setAttribute('aria-label', isArabic ? 'إغلاق' : 'Close');
        const body = overlay.querySelector('.modal-body');
        const focusLinks = [];
        const renderMessage = (entry) => {
            const target = entry.key ? associations.get(entry.key) : null;
            if (!(target instanceof HTMLElement)) return document.createTextNode(entry.message);
            const link = document.createElement('a');
            link.href = '#'+ensureValidationControlId(target, entry.key);
            link.dataset.foodexValidationLink = entry.key;
            link.textContent = entry.message;
            focusLinks.push({ link, target });
            return link;
        };
        if (entries.length === 1) {
            body.appendChild(renderMessage(entries[0]));
        } else {
            const ul = document.createElement('ul');
            entries.forEach((entry) => {
                const li = document.createElement('li');
                li.appendChild(renderMessage(entry));
                ul.appendChild(li);
            });
            body.appendChild(ul);
        }

        const priorFocus = document.activeElement;
        const fallbackFocus = document.querySelector('[autofocus],main input:not([type="hidden"]),main select,main textarea,main button,main a[href]');
        let modalSession = null;
        let dismissTimer = null;
        const close = ({ restoreFocus = true } = {}) => {
            if (!overlay.isConnected) return;
            if (dismissTimer !== null) window.clearTimeout(dismissTimer);
            modalSession?.deactivate({ restoreFocus });
            modalSession = null;
            overlay.remove();
            if (window.FoodexFeedbackClose === close) window.FoodexFeedbackClose = null;
        };
        window.FoodexFeedbackClose = close;
        focusLinks.forEach(({ link, target }) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                close({ restoreFocus: false });
                target.focus({ preventScroll:false });
                requestAnimationFrame(() => {
                    target.scrollIntoView({ block:'center', inline:'nearest' });
                });
            });
        });
        closeButton.addEventListener('click', close);
        overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
        document.body.appendChild(overlay);
        modalSession = window.FoodexModalA11y?.activate({
            root: overlay,
            dialog: overlay,
            initialFocus: closeButton,
            returnFocus: priorFocus instanceof HTMLElement ? priorFocus : null,
            fallbackFocus: fallbackFocus instanceof HTMLElement ? fallbackFocus : null,
            onEscape: close,
        }) || null;
        if (kind === 'success') dismissTimer = window.setTimeout(() => overlay.isConnected && close(), 5500);
    };
    window.FoodexValidationA11y.showFeedback = showFeedback;

    const reportInspector = (payload) => {
        if (inspectorSuppressed || Date.now() < inspectorSuppressedUntil || !inspectorEnabled || !nativeFetch || location.pathname.startsWith('/admin/inspector')) return;
        const xsrfToken = currentXsrfToken();
        nativeFetch(inspectorUrl, {
            method:'POST',
            credentials:'same-origin',
            headers:{
                'Content-Type':'application/json',
                'Accept':'application/json',
                ...(xsrfToken ? {'X-XSRF-TOKEN':xsrfToken} : {'X-CSRF-TOKEN':csrfToken}),
                'X-FOODEX-INSPECTOR':'1',
            },
            body:JSON.stringify(payload),
        }).then((response) => {
            if (response.status === 419 || response.status === 401) inspectorSuppressed = true;
            if (response.status === 503) inspectorSuppressedUntil = Date.now() + 60000;
        }).catch(() => {});
    };

    window.addEventListener('error', (event) => {
        reportInspector({
            source:'javascript', severity:'error',
            message:event.message || 'JavaScript runtime error',
            url:location.href, filename:event.filename || null,
            line:event.lineno || null, column:event.colno || null,
            stack:event.error?.stack || null,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        reportInspector({
            source:'javascript', severity:'error',
            message:reason?.message || String(reason || 'Unhandled promise rejection'),
            url:location.href, stack:reason?.stack || null,
        });
    });

    if (nativeFetch) {
        window.fetch = async (...args) => {
            const request = args[0];
            const options = args[1] || {};
            const requestUrl = typeof request === 'string' ? request : request?.url;
            const requestHeaders = new Headers(options.headers || (request instanceof Request ? request.headers : undefined));
            const backgroundRequest = requestHeaders.get('X-FOODEX-BACKGROUND') === '1';
            if (requestHeaders.get('X-FOODEX-INSPECTOR') === '1' || requestUrl === inspectorUrl) return nativeFetch(...args);
            let sameOriginRequest = true;
            try {
                sameOriginRequest = !requestUrl || new URL(requestUrl, location.href).origin === location.origin;
            } catch (_) {}

            try {
                const response = await nativeFetch(...args);
                if (!response.ok) {
                    const method = String(options.method || request?.method || 'GET').toUpperCase();
                    let responseMessage = '';
                    let responseErrors = null;
                    try {
                        const clone = response.clone();
                        const contentType = clone.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            const payload = await clone.json();
                            responseMessage = payload?.message || payload?.error || '';
                            if (payload?.errors && typeof payload.errors === 'object') {
                                responseErrors = payload.errors;
                                if (!responseMessage) responseMessage = Object.values(payload.errors).flat().filter(Boolean).join('\n');
                            }
                        }
                    } catch (_) {}
                    if (sameOriginRequest && !backgroundRequest) {
                        const category = response.status === 503 ? 'maintenance'
                            : response.status === 422 ? 'validation_rejection'
                            : response.status === 409 ? 'domain_rejection'
                            : (response.status === 401 || response.status === 403 || response.status === 419) ? 'authorization_rejection'
                            : response.status >= 500 ? 'server_failure'
                            : 'http_rejection';
                        reportInspector({
                            source:'fetch', severity:response.status >= 500 ? 'error' : 'warning',
                            category,
                            message:responseMessage || ('HTTP '+response.status+' '+response.statusText),
                            status:response.status, method,
                            url:requestUrl || location.href, response_url:response.url,
                        });
                    }
                    if (method !== 'GET') {
                        showFeedback('error', hasFeedbackErrors(responseErrors) ? responseErrors : (responseMessage || (isArabic
                            ? 'تعذر تنفيذ العملية ('+response.status+'). راجع البيانات وحاول مرة أخرى.'
                            : 'The action could not be completed ('+response.status+'). Review the data and try again.')));
                    }
                }
                return response;
            } catch (error) {
                const intentionalAbort = error?.name === 'AbortError' || options?.signal?.aborted === true || request?.signal?.aborted === true;
                if (sameOriginRequest && !backgroundRequest && !intentionalAbort) {
                    reportInspector({
                        source:'fetch', severity:'error',
                        category:error?.name === 'TimeoutError' ? 'timeout' : 'network_failure',
                        message:error?.message || 'Fetch request failed',
                        method:options.method || request?.method || 'GET',
                        url:requestUrl || location.href, stack:error?.stack || null,
                    });
                }
                throw error;
            }
        };
    }

    document.addEventListener('DOMContentLoaded', () => {
        enhanceForms();
        enhancePasswords();
        enhanceTabs();
        if (hasFeedbackErrors(feedback.errors)) {
            if (document.body.dataset.foodexValidationSummaryNative === '1') applyServerValidation(feedback.errors);
            else showFeedback('error', feedback.errors);
        } else if (feedback.status) showFeedback('success', feedback.status);
    });
})();
</script>
@include('admin._new-order-popup')
@include('admin._whatsapp-inbound-popup')
