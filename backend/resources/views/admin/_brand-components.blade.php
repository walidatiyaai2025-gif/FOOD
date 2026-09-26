@include('admin._brand')
<style id="foodex-admin-component-tokens">
    *,*::before,*::after{box-sizing:border-box}
    html,body{background:var(--foodex-background)!important;color:var(--foodex-ink)!important}
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
    .foodex-topbar{min-height:var(--foodex-header-height);background:var(--foodex-surface);border-bottom:1px solid var(--foodex-border)}
    .foodex-search{min-height:var(--foodex-control-height);border:1px solid var(--foodex-border);border-radius:var(--foodex-radius-control);background:var(--foodex-surface);box-shadow:var(--foodex-shadow-sm)}
    .foodex-subtitle{display:block;margin-top:2px;color:var(--foodex-muted);font-size:var(--foodex-text-xs);font-weight:var(--foodex-font-weight-regular)}
    .foodex-icon{inline-size:var(--foodex-icon-md);block-size:var(--foodex-icon-md);display:inline-grid;place-items:center;flex:0 0 auto}

    .primary,.button,.btn.primary,button.foodex-primary,.foodex-action-primary{min-height:var(--foodex-touch-target);border:0;border-radius:var(--foodex-radius-control);padding:0 var(--foodex-space-4);display:inline-flex;align-items:center;justify-content:center;gap:var(--foodex-space-2);font-weight:var(--foodex-font-weight-bold);background:var(--foodex-green)!important;color:#fff!important;text-decoration:none;cursor:pointer}
    .primary:hover,.button:hover,.btn.primary:hover,button.foodex-primary:hover,.foodex-action-primary:hover{background:var(--foodex-green-dark)!important}
    .secondary,.button.secondary,.btn.secondary,.foodex-action-secondary{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}
    .danger,.btn.danger{background:#fff0f0!important;color:var(--foodex-red)!important}
    .success,.notice,.flash{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important;border-color:#b7dfc4!important}
    .warning,.error,.errors{background:var(--foodex-orange-soft)!important;color:#9a4b0b!important;border-color:#ffd0a6!important}
    .pill.active{background:var(--foodex-green)!important;color:#fff!important;border-color:var(--foodex-green)!important}
    .back{color:var(--foodex-green-dark)!important;box-shadow:none}
    .badge.active,.active.badge{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}

    .foodex-admin-page{max-width:1500px;margin:0 auto;padding:var(--foodex-space-8)}
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
    .foodex-admin-layout>.foodex-admin-main{grid-column:1;grid-row:1;direction:rtl;min-width:0;padding:var(--foodex-space-8)}
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

    @media(max-width:767px){
        .foodex-admin-page{padding:var(--foodex-space-4)}
        .foodex-page-header{flex-direction:column}
        .foodex-admin-layout,html[dir=ltr] .foodex-admin-layout{grid-template-columns:1fr}
        .foodex-admin-layout>.sidebar,html[dir=ltr] .foodex-admin-layout>.sidebar{grid-column:1;grid-row:1;min-height:auto;border-inline:0;border-bottom:1px solid var(--foodex-border)}
        .foodex-admin-layout>.foodex-admin-main,html[dir=ltr] .foodex-admin-layout>.foodex-admin-main{grid-column:1;grid-row:2;padding:var(--foodex-space-4)}
    }
</style>
