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

    @media(max-width:767px){
        .foodex-admin-page{padding:var(--foodex-space-4)}
        .foodex-page-header{flex-direction:column}
    }
</style>
