@include('admin._brand')
<style id="foodex-admin-component-tokens">
    html,body{background:var(--foodex-background)!important;color:var(--foodex-ink)!important}
    body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif!important}
    a{color:var(--foodex-green-dark)}
    aside.sidebar,.sidebar{background:var(--foodex-surface)!important;color:var(--foodex-ink)!important;border-color:var(--foodex-border)!important}
    .panel,.card,.filters,.back{background:var(--foodex-surface)!important;border-color:var(--foodex-border)!important;box-shadow:var(--foodex-shadow)}
    input,select,textarea{background:var(--foodex-surface)!important;color:var(--foodex-ink)!important;border-color:var(--foodex-border)!important}
    input:focus,select:focus,textarea:focus{outline:none;border-color:var(--foodex-green)!important;box-shadow:0 0 0 3px rgba(21,138,58,.11)}
    .primary,.button,.btn.primary,button.foodex-primary{background:var(--foodex-green)!important;color:#fff!important}
    .primary:hover,.button:hover,.btn.primary:hover,button.foodex-primary:hover{background:var(--foodex-green-dark)!important}
    .secondary,.button.secondary,.btn.secondary{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}
    .danger,.btn.danger{background:#fff0f0!important;color:var(--foodex-red)!important}
    .success,.notice,.flash{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important;border-color:#b7dfc4!important}
    .warning,.error,.errors{background:var(--foodex-orange-soft)!important;color:#9a4b0b!important;border-color:#ffd0a6!important}
    .muted,.empty{color:var(--foodex-muted)!important}
    .pill.active{background:var(--foodex-green)!important;color:#fff!important;border-color:var(--foodex-green)!important}
    .back{color:var(--foodex-green-dark)!important;box-shadow:none}
    .badge.active,.active.badge{background:var(--foodex-green-soft)!important;color:var(--foodex-green-dark)!important}
    .foodex-admin-page{max-width:1500px;margin:0 auto;padding:28px}
    .foodex-form{display:grid;gap:12px;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:16px;padding:18px;box-shadow:var(--foodex-shadow);margin-bottom:20px}
    .foodex-form label{display:grid;gap:6px;font-weight:650;color:var(--foodex-ink)}
    .foodex-form button{justify-self:start;border:0;border-radius:10px;padding:10px 15px;font-weight:750;cursor:pointer;background:var(--foodex-green);color:#fff}
    .foodex-table{width:100%;border-collapse:collapse;background:var(--foodex-surface);border:1px solid var(--foodex-border);border-radius:14px;overflow:hidden}
    .foodex-table th,.foodex-table td{padding:10px 12px;text-align:start;border-bottom:1px solid var(--foodex-border)}
    @media(max-width:760px){.foodex-admin-page{padding:16px}}
</style>
