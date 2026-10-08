@include('admin._brand')
<style id="foodex-admin-component-tokens">
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
    input:focus,select:focus,textarea:focus,.foodex-control:focus{outline:none;border-color:var(--foodex-green)!important;box-shadow:0 0 0 3px rgba(21,138,58,.11)}
    :focus-visible{outline:2px solid var(--foodex-green);outline-offset:2px}

    aside.sidebar,.sidebar{background:var(--foodex-surface)!important;color:var(--foodex-ink)!important;border-color:var(--foodex-border)!important}
    .foodex-card,.panel,.card,.filters,.back{background:var(--foodex-surface)!important;border:1px solid var(--foodex-border)!important;border-radius:var(--foodex-radius-card);box-shadow:var(--foodex-shadow-sm)}
    .foodex-page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--foodex-space-4);margin-bottom:var(--foodex-space-6)}
    .foodex-page-header h1{margin:0;font-size:clamp(1.55rem,2.2vw,var(--foodex-text-2xl));line-height:var(--foodex-leading-tight);font-weight:var(--foodex-font-weight-bold)}
    .foodex-page-header p{margin:var(--foodex-space-1) 0 0;color:var(--foodex-muted)}
    .foodex-header-actions{display:flex;align-items:center;justify-content:flex-end;gap:var(--foodex-space-2);flex-wrap:wrap}
    .foodex-topbar{min-height:var(--foodex-header-height);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border)}
    .foodex-search{min-height:var(--foodex-control-height);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
    .foodex-subtitle{display:block;margin-top:2px;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-regular)}
    .foodex-icon{inline-size:var(--foodex-icon-md);block-size:var(--foodex-icon-md);display:inline-grid;place-items:center;flex:0 0 auto}

    .primary,.button,.btn.primary,button.foodex-primary,.foodex-action-primary,.foodex-filter-action{min-height:var(--foodex-touch-target);border:0;border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-4);display:inline-flex;align-items:center;justify-content:center;gap:var(--foodex-space-2);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-green)!important;color:#fff!important;text-decoration:none;cursor:pointer}
    .primary:hover,.button:hover,.btn.primary:hover,button.foodex-primary:hover,.foodex-action-primary:hover,.foodex-filter-action:hover{background:var(--foodex-green-dark)!important}
    .secondary,.button.secondary,.btn.secondary,.foodex-action-secondary{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}
    .danger,.btn.danger{background:#fff0f0!important;color:var(--foodex-red)!important}
    .success,.notice,.flash{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important;border-color:#b7dfc4!important}
    .warning,.error,.errors{background:var(--foodex-orange-soft)!important;color:#9a4b0b!important;border-color:#ffd0a6!important}
    .pill.active{background:var(--foodex-green)!important;color:#fff!important;border-color:var(--foodex-green)!important}
    .back{color:var(--foodex-green-dark)!important;box-shadow:none}
    .badge.active,.active.badge{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}

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
    .pagination,.pager{display:flex;flex-wrap:wrap;gap:var(--foodex-space-2);align-items:center;margin-top:var(--foodex-space-5)}
    .pagination a,.pagination span,.pager a,.pager span{min-width:var(--foodex-touch-target);min-height:var(--foodex-touch-target);display:inline-grid;place-items:center;border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);color:var(--foodex-ink);text-decoration:none;padding:0 var(--foodex-space-2)}
    .pagination [aria-current="page"],.pager [aria-current="page"]{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff}
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
        .foodex-page-header{margin-bottom:28px}
        .foodex-premium-auto-form{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
    }
    @media(min-width:1800px){
        :root{--foodex-sidebar-width:260px}
        .foodex-admin-page,.foodex-admin-layout>.foodex-admin-main{padding-inline:48px}
        .foodex-premium-auto-form{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
    }
    @media(max-width:1023px){
        .foodex-admin-page{padding:var(--foodex-space-6)}
        .foodex-page-header{gap:var(--foodex-space-3)}
        .table-wrap,.module-table-wrap,.foodex-table-wrap{border-radius:var(--foodex-radius-md)}
    }
    @media(max-width:767px){
        .foodex-admin-page{padding:var(--foodex-space-4)}
        .foodex-page-header{width:100%;align-items:stretch}
        .foodex-page-header>*{min-width:0}
        .foodex-header-actions{justify-content:flex-start}
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
        .foodex-page-header{flex-direction:column}
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
    .foodex-ops-menu .danger{color:var(--foodex-red)!important;background:transparent!important}
    .foodex-ops-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--foodex-space-4)}
    .foodex-ops-state{min-height:140px;display:grid;place-items:center;text-align:center;padding:var(--foodex-space-6);border:1px dashed var(--foodex-border);border-radius:var(--foodex-radius-md);background:#fbfcfd;color:var(--foodex-muted)}
    @media(max-width:1023px){.foodex-ops-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:767px){.foodex-ops-toolbar,.foodex-ops-detail-grid{grid-template-columns:minmax(0,1fr)}.foodex-ops-grid .foodex-ops-hide-mobile{display:none}}

</style>
<script id="foodex-ops-popover-runtime">
(() => {
    const positionMenu = (details) => {
        if (!(details instanceof HTMLDetailsElement) || !details.open) return;
        const summary = details.querySelector(':scope > summary');
        const menu = details.querySelector(':scope > .foodex-ops-menu');
        if (!(summary instanceof HTMLElement) || !(menu instanceof HTMLElement)) return;

        const trigger = summary.getBoundingClientRect();
        const margin = 8;
        menu.style.visibility = 'hidden';
        menu.style.inset = 'auto';
        menu.style.top = '0px';
        menu.style.left = '0px';

        const menuRect = menu.getBoundingClientRect();
        const rtl = getComputedStyle(details).direction === 'rtl';
        let left = rtl ? trigger.left : trigger.right - menuRect.width;
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

    const repositionOpenMenus = () => {
        document.querySelectorAll('.foodex-ops-actions[open]').forEach(positionMenu);
    };

    document.addEventListener('toggle', (event) => {
        const details = event.target;
        if (!(details instanceof HTMLDetailsElement) || !details.classList.contains('foodex-ops-actions')) return;
        if (details.open) {
            document.querySelectorAll('.foodex-ops-actions[open]').forEach((other) => {
                if (other !== details) other.open = false;
            });
            requestAnimationFrame(() => positionMenu(details));
        }
    }, true);

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.foodex-ops-actions[open]').forEach((details) => {
            if (!details.contains(event.target)) details.open = false;
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
    .foodex-required-mark{color:var(--foodex-red);font-family:var(--foodex-font-en)}
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
    .foodex-step-tab[aria-selected="true"]{background:var(--foodex-green);border-color:var(--foodex-green);color:#fff}
    .foodex-step-panel[hidden]{display:none!important}
    .foodex-step-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:var(--foodex-space-4)}
    .foodex-feedback-modal{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:20px;background:rgba(17,24,39,.48);backdrop-filter:blur(2px)}
    .foodex-feedback-modal .modal-dialog{width:min(100%,520px)}
    .foodex-feedback-modal .modal-content{overflow:hidden;background:#fff;border:1px solid var(--foodex-border);border-radius:18px;box-shadow:0 28px 72px rgba(16,24,40,.28)}
    .foodex-feedback-modal .modal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 20px;border-bottom:1px solid var(--foodex-border)}
    .foodex-feedback-modal .modal-title{margin:0;font-size:1.08rem;font-weight:800}
    .foodex-feedback-modal .modal-body{padding:20px;line-height:1.7}
    .foodex-feedback-modal .modal-body ul{margin:0;padding-inline-start:20px}
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
</style>

@php
    $foodexUiStatus = session('status');
    $foodexUiErrors = isset($errors) && $errors->any() ? $errors->all() : [];
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
                    if (owner && !owner.querySelector('.foodex-file-help')) {
                        const hint = document.createElement('span');
                        hint.className = 'foodex-file-help';
                        hint.textContent = isArabic ? 'راجع المعاينة قبل الحفظ. JPG / PNG / WebP حسب الحقل.' : 'Review the preview before saving. JPG / PNG / WebP as allowed by this field.';
                        owner.appendChild(hint);
                    }
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
        const clean = (Array.isArray(messages) ? messages : [messages]).filter(Boolean);
        if (!clean.length) return;
        document.querySelector('.foodex-feedback-modal')?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'foodex-feedback-modal modal fade show '+(kind === 'success' ? 'is-success' : 'is-error');
        overlay.setAttribute('role','dialog');
        overlay.setAttribute('aria-modal','true');
        overlay.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-header"><div class="foodex-feedback-title-wrap"><span class="foodex-feedback-icon">'+(kind === 'success' ? '✓' : '!')+'</span><h2 class="modal-title"></h2></div><button class="btn-close" type="button" aria-label="Close">×</button></div><div class="modal-body"></div></div></div>';
        overlay.querySelector('.modal-title').textContent = kind === 'success'
            ? (isArabic ? 'تمت العملية بنجاح' : 'Saved successfully')
            : (isArabic ? 'تعذر إكمال العملية' : 'Action could not be completed');
        const body = overlay.querySelector('.modal-body');
        if (clean.length === 1) body.textContent = clean[0];
        else {
            const ul = document.createElement('ul');
            clean.forEach(message => { const li=document.createElement('li'); li.textContent=message; ul.appendChild(li); });
            body.appendChild(ul);
        }
        const close = () => overlay.remove();
        overlay.querySelector('.btn-close').addEventListener('click', close);
        overlay.addEventListener('click', (event) => { if (event.target === overlay) close(); });
        document.addEventListener('keydown', function esc(event){ if(event.key==='Escape'){close();document.removeEventListener('keydown',esc);} });
        document.body.appendChild(overlay);
        if (kind === 'success') window.setTimeout(() => overlay.isConnected && close(), 5500);
    };

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
                    try {
                        const clone = response.clone();
                        const contentType = clone.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            const payload = await clone.json();
                            responseMessage = payload?.message || payload?.error || '';
                            if (!responseMessage && payload?.errors) {
                                responseMessage = Object.values(payload.errors).flat().filter(Boolean).join('\n');
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
                        showFeedback('error', responseMessage || (isArabic
                            ? 'تعذر تنفيذ العملية ('+response.status+'). راجع البيانات وحاول مرة أخرى.'
                            : 'The action could not be completed ('+response.status+'). Review the data and try again.'));
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
        if (feedback.errors?.length) showFeedback('error', feedback.errors);
        else if (feedback.status) showFeedback('success', feedback.status);
    });
})();
</script>
