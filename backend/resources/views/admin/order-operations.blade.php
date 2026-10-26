<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isAr?'rtl':'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('order_operations.title') }} · FOODEX</title>
@include('admin._brand-components')
<style>
body{margin:0}.shell{display:grid;grid-template-columns:minmax(0,1fr) 240px;min-height:100vh}.main{padding:28px}.sidebar{padding:18px;border-inline-start:1px solid var(--foodex-border)}
.order-kpi-card{position:relative;display:grid;grid-template-columns:repeat(10,minmax(132px,1fr));min-width:0;margin:6px 0;overflow-x:auto;overflow-y:hidden;border:1px solid var(--foodex-border);border-radius:18px;background:var(--foodex-surface);box-shadow:0 8px 26px rgba(15,23,42,.055);scrollbar-width:thin}
.order-kpi-card::before{content:"";position:absolute;inset-inline:0;top:0;height:4px;background:linear-gradient(90deg,var(--foodex-palette-158a3a) 0%,var(--foodex-palette-2563eb) 18%,var(--foodex-palette-7c3aed) 34%,var(--foodex-palette-ea580c) 50%,var(--foodex-palette-dc2626) 66%,var(--foodex-palette-0891b2) 82%,var(--foodex-palette-be123c) 100%);background-size:180% 100%;animation:orderKpiEdge 7.5s linear infinite}
.order-kpi-item{--order-kpi-accent:var(--foodex-palette-158a3a);--order-kpi-soft:var(--foodex-palette-eaf7ef);position:relative;min-width:132px;min-height:112px;display:grid;grid-template-rows:auto 1fr;gap:7px;padding:16px 12px 12px;text-decoration:none;color:inherit;border-inline-end:1px solid var(--foodex-border);transition:background .16s ease,transform .16s ease}
.order-kpi-item:last-child{border-inline-end:0}.order-kpi-item:hover{background:color-mix(in srgb,var(--order-kpi-soft) 58%,#fff);transform:translateY(-1px)}
.order-kpi-head{display:flex;align-items:center;gap:8px;min-width:0}.order-kpi-icon{width:32px;height:32px;flex:0 0 32px;display:grid;place-items:center;border-radius:10px;background:var(--order-kpi-soft);color:var(--order-kpi-accent)}.order-kpi-icon .foodex-svg-icon{width:17px;height:17px}
.order-kpi-label{min-width:0;color:var(--foodex-muted);font-size:.63rem;font-weight:800;line-height:1.35}.order-kpi-value{align-self:center;display:block;width:100%;max-width:100%;font-size:1.22rem;line-height:1.12;color:var(--foodex-ink);font-variant-numeric:tabular-nums;white-space:nowrap;overflow:visible;text-overflow:clip}
.order-kpi-state{position:absolute;inset-inline-end:10px;bottom:10px;width:7px;height:7px;border-radius:999px;background:var(--foodex-border)}.order-kpi-state[data-state="success"]{background:var(--foodex-palette-16a34a)}.order-kpi-state[data-state="warning"]{background:var(--foodex-palette-ea580c)}.order-kpi-state[data-state="danger"],.order-kpi-state[data-state="error"]{background:var(--foodex-palette-dc2626)}
.order-kpi-item[data-tone="green"]{--order-kpi-accent:var(--foodex-palette-158a3a);--order-kpi-soft:var(--foodex-palette-eaf7ef)}.order-kpi-item[data-tone="blue"]{--order-kpi-accent:var(--foodex-palette-2563eb);--order-kpi-soft:var(--foodex-palette-eff6ff)}.order-kpi-item[data-tone="violet"]{--order-kpi-accent:var(--foodex-palette-7c3aed);--order-kpi-soft:var(--foodex-palette-f5f3ff)}.order-kpi-item[data-tone="orange"]{--order-kpi-accent:var(--foodex-palette-ea580c);--order-kpi-soft:var(--foodex-palette-fff7ed)}.order-kpi-item[data-tone="red"]{--order-kpi-accent:var(--foodex-palette-dc2626);--order-kpi-soft:var(--foodex-palette-fef2f2)}.order-kpi-item[data-tone="amber"]{--order-kpi-accent:var(--foodex-palette-b45309);--order-kpi-soft:var(--foodex-palette-fffbeb)}.order-kpi-item[data-tone="cyan"]{--order-kpi-accent:var(--foodex-palette-0e7490);--order-kpi-soft:var(--foodex-palette-ecfeff)}.order-kpi-item[data-tone="rose"]{--order-kpi-accent:var(--foodex-palette-be123c);--order-kpi-soft:var(--foodex-palette-fff1f2)}
@keyframes orderKpiEdge{from{background-position:0 0}to{background-position:180% 0}}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;padding:8px;margin-bottom:6px}.filters label{display:grid;gap:5px;font-weight:700;font-size:.8rem}
.status-tabs{display:flex;gap:6px;overflow:auto;padding:2px 0 6px;margin-bottom:0}.status-tab{display:inline-flex;align-items:center;gap:7px;white-space:nowrap;padding:9px 12px;border:1px solid var(--foodex-border);border-radius:999px;text-decoration:none;color:inherit;background:var(--foodex-surface,#fff);font-weight:800}.status-tab[aria-current="page"]{outline:2px solid currentColor}.status-tab-count{display:inline-flex;min-width:24px;height:24px;align-items:center;justify-content:center;border-radius:999px;background:rgba(0,0,0,.06);font-size:.78rem}
.order-workspace-bar{display:flex;align-items:center;justify-content:space-between;gap:6px;min-width:0;margin:0 0 6px}
.order-workspace-tabs{display:flex;align-items:center;justify-content:flex-start;gap:6px;min-width:0;margin:0;overflow-x:auto;overflow-y:hidden;padding:2px;scrollbar-width:thin;flex:0 1 auto}
.order-workspace-filter-slot{display:flex;align-items:center;justify-content:flex-end;min-width:0;margin-inline-start:auto;flex:0 1 auto}
.order-workspace-tab{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 18px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.78rem;font-weight:900;white-space:nowrap;cursor:pointer;box-shadow:0 4px 14px rgba(15,23,42,.035);transition:background .16s ease,color .16s ease,border-color .16s ease,transform .16s ease}
.order-workspace-tab:hover{transform:translateY(-1px);border-color:var(--foodex-palette-b8d9c2);color:var(--foodex-green-dark)}
.order-workspace-tab[aria-selected="true"]{border-color:var(--foodex-action-green);background:var(--foodex-action-green);color:#fff;box-shadow:0 8px 22px rgba(21,138,58,.16)}
.order-workspace-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:var(--foodex-palette-dbeafe);color:var(--foodex-palette-1d4ed8);font-size:.68rem;font-weight:900;font-variant-numeric:tabular-nums}
.order-workspace-tab[aria-selected="true"] .order-workspace-tab-count{background:rgba(255,255,255,.2);color:#fff}
.order-tab-panel{min-width:0;width:100%}.order-tab-panel[hidden]{display:none!important}
.foodex-module-overview{display:grid;gap:6px}
.order-tab-panel[data-order-panel="analytics"]{display:grid;gap:6px}
.order-analytics-tabs{display:flex;align-items:center;gap:5px;overflow-x:auto;padding:0 0 2px;margin:0;scrollbar-width:thin}
.order-analytics-subtab{min-height:34px;padding:0 13px;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-muted);font-size:.7rem;font-weight:900;white-space:nowrap;cursor:pointer;transition:background .22s ease,color .22s ease,border-color .22s ease,transform .22s ease}
.order-analytics-subtab:hover{transform:translateY(-1px);border-color:var(--foodex-palette-bfd5c6);color:var(--foodex-green-dark)}
.order-analytics-subtab[aria-selected="true"]{border-color:var(--foodex-palette-146c36);background:var(--foodex-palette-eaf7ef);color:var(--foodex-palette-146c36);box-shadow:inset 0 0 0 1px rgba(20,108,54,.08)}
[data-analytics-group][hidden]{display:none!important}
.foodex-module-panels,.sales-ops-chart-grid{gap:6px!important;margin:0!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-viz-card{padding:10px!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-viz-header{margin-bottom:5px!important}
.order-tab-panel[data-order-panel="analytics"] .foodex-module-chart-summary{margin:5px 0!important}
.table-wrap{overflow:visible;border:1px solid var(--foodex-border);border-radius:16px;background:var(--foodex-surface);box-shadow:0 8px 22px rgba(15,23,42,.045)}
.ops-table{width:100%;min-width:0;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:.74rem}
.ops-table thead th{position:sticky;top:0;z-index:3;padding:9px 10px;background:var(--foodex-palette-f8fafc);color:var(--foodex-palette-64748b);font-size:.64rem;font-weight:900;border-bottom:1px solid var(--foodex-border);white-space:nowrap}
.ops-table tbody td{padding:10px;vertical-align:middle;line-height:1.32;border-bottom:1px solid var(--foodex-palette-edf1f4);background:var(--foodex-surface);transition:background .16s ease}
.ops-table tbody tr:last-child td{border-bottom:0}
.ops-table tbody tr:hover td{background:var(--foodex-palette-f9fbfa)}
.ops-table th:nth-child(1){width:7.5%}.ops-table th:nth-child(2){width:12.75%}.ops-table th:nth-child(3){width:7.5%}.ops-table th:nth-child(4){width:14.5%}.ops-table th:nth-child(5){width:27%}.ops-table th:nth-child(6){width:23.75%}.ops-table th:nth-child(7){width:7%}.ops-table th:nth-child(3),.ops-table td:nth-child(3){padding-inline:5px}
.order-grid-row{--order-row-accent:var(--foodex-palette-cbd5e1)}
.order-grid-row>td:first-child{border-inline-start:3px solid var(--order-row-accent)}
.order-grid-row.is-action-required{--order-row-accent:var(--foodex-palette-f59e0b)}
.order-grid-row.is-action-required td{background:linear-gradient(90deg,rgba(245,158,11,.045),rgba(255,255,255,0) 38%)}
.order-grid-row.is-action-required:hover td{background:linear-gradient(90deg,rgba(245,158,11,.085),rgba(249,251,250,.92) 42%)}
.order-grid-primary{display:grid;gap:4px;min-width:0}.order-grid-primary>a{color:var(--foodex-green-dark);font-size:.82rem;font-weight:900;text-decoration:none}.order-grid-primary>a:hover{text-decoration:underline}
.order-grid-title{font-weight:900;color:var(--foodex-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.order-grid-meta{display:flex;align-items:center;gap:5px;flex-wrap:wrap;color:var(--foodex-muted);font-size:.63rem}
.order-mini-chip{display:inline-flex;align-items:center;min-height:20px;padding:0 7px;border-radius:999px;background:var(--foodex-palette-f1f5f9);color:var(--foodex-palette-475569);font-size:.59rem;font-weight:800;white-space:nowrap}
.order-status-chip{--status-bg:var(--foodex-palette-f1f5f9);--status-fg:var(--foodex-palette-475569);--status-border:var(--foodex-palette-cbd5e1);display:inline-flex;align-items:center;justify-content:center;min-height:27px;padding:0 9px;border:1px solid var(--status-border);border-radius:999px;background:var(--status-bg);color:var(--status-fg);font-size:.63rem;font-weight:900;white-space:nowrap}
.order-status-chip[data-state="pending"],.order-status-chip[data-state="waiting"],.order-status-chip[data-state="awaiting_dispatch"]{--status-bg:var(--foodex-palette-fff7ed);--status-fg:var(--foodex-palette-c2410c);--status-border:var(--foodex-palette-fdba74)}
.order-status-chip[data-state="confirmed"]{--status-bg:var(--foodex-palette-eff6ff);--status-fg:var(--foodex-palette-1d4ed8);--status-border:var(--foodex-palette-93c5fd)}
.order-status-chip[data-state="preparing"],.order-status-chip[data-state="processing"]{--status-bg:var(--foodex-palette-f5f3ff);--status-fg:var(--foodex-palette-6d28d9);--status-border:var(--foodex-palette-c4b5fd)}
.order-status-chip[data-state="ready"]{--status-bg:var(--foodex-palette-ecfeff);--status-fg:var(--foodex-palette-0e7490);--status-border:var(--foodex-palette-67e8f9)}
.order-status-chip[data-state="out_for_delivery"],.order-status-chip[data-state="on_the_way"],.order-status-chip[data-state="assigned"]{--status-bg:var(--foodex-palette-eef2ff);--status-fg:var(--foodex-palette-4338ca);--status-border:var(--foodex-palette-a5b4fc)}
.order-status-chip[data-state="b2b_out_for_delivery"]{--status-bg:var(--foodex-palette-ecfdf5);--status-fg:var(--foodex-palette-15803d);--status-border:var(--foodex-palette-86efac)}
.order-status-chip[data-state="driver_delivery"]{--status-bg:var(--foodex-palette-ecfdf5);--status-fg:var(--foodex-palette-15803d);--status-border:var(--foodex-palette-86efac)}
.order-ready-dispatch-alert{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:0 9px;border:1px solid var(--foodex-palette-fda4af);border-radius:999px;background:var(--foodex-palette-fff1f2);color:var(--foodex-palette-be123c);font-size:.6rem;font-weight:900;white-space:nowrap;animation:orderReadyDispatchPulse 1s ease-in-out infinite}
@keyframes orderReadyDispatchPulse{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(190,18,60,.12)}50%{opacity:.38;box-shadow:0 0 0 4px rgba(190,18,60,.08)}}
.order-status-chip[data-state="delivered"],.order-status-chip[data-state="completed"],.order-status-chip[data-state="paid"]{--status-bg:var(--foodex-palette-ecfdf5);--status-fg:var(--foodex-palette-15803d);--status-border:var(--foodex-palette-86efac)}
.order-status-chip[data-state="cancelled"],.order-status-chip[data-state="failed"],.order-status-chip[data-state="delivery_failed"]{--status-bg:var(--foodex-palette-fff1f2);--status-fg:var(--foodex-palette-be123c);--status-border:var(--foodex-palette-fda4af)}
.order-attention-chip{display:inline-flex;align-items:center;gap:5px;min-height:24px;padding:0 8px;border:1px solid var(--foodex-palette-fdba74);border-radius:999px;background:var(--foodex-palette-fff7ed);color:var(--foodex-palette-c2410c);font-size:.61rem;font-weight:900;white-space:nowrap;box-shadow:0 3px 10px rgba(234,88,12,.08)}
.order-attention-chip::before{content:"";width:6px;height:6px;border-radius:999px;background:var(--foodex-palette-f97316);box-shadow:0 0 0 3px rgba(249,115,22,.12)}
.order-execution{display:grid;gap:5px;min-width:0}.ops-table tbody td.order-dispatch-cell-locked{background:var(--foodex-palette-f8fafc)!important;cursor:not-allowed}.order-execution.is-dispatch-locked{opacity:.42;filter:grayscale(.28)}.order-dispatch-lock-hint{font-size:.58rem;font-weight:800;color:var(--foodex-muted)}.order-execution strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.order-execution small{color:var(--foodex-muted);font-size:.62rem}
.order-owner{display:grid;gap:3px}.order-owner.is-complete{opacity:.38;filter:grayscale(.35);pointer-events:none;user-select:none}.ops-table tbody td.order-owner-cell.is-complete{background:var(--foodex-palette-f8fafc)!important}.order-owner strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.order-owner small{color:var(--foodex-muted);font-size:.61rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.order-financial{display:grid;gap:3px;min-width:0}.order-financial>strong{font-size:.69rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.order-financial>strong.is-paid{color:var(--foodex-palette-15803d)}.order-financial>strong.is-unpaid{color:var(--foodex-palette-be123c)}.order-financial small{color:var(--foodex-muted);font-size:.56rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.order-financial-actions{display:flex;align-items:center;gap:2px;margin-top:1px;flex-wrap:nowrap}.order-financial-action{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;min-width:19px;padding:0;border:1px solid var(--foodex-border);border-radius:999px;background:var(--foodex-surface);color:var(--foodex-green-dark);text-decoration:none;cursor:pointer;transition:transform .14s ease,border-color .14s ease,background .14s ease}.order-financial-action:hover{transform:translateY(-1px);border-color:var(--foodex-action-green);background:var(--foodex-palette-eaf7ef)}.order-financial-action.is-timeline{border-color:var(--foodex-palette-fda4af);background:var(--foodex-palette-fff1f2);color:var(--foodex-palette-be123c)}.order-financial-action.is-timeline:hover{border-color:var(--foodex-palette-be123c);background:var(--foodex-palette-ffe4e6)}.order-financial-action .foodex-svg-icon{width:11px;height:11px}
.order-popup-dialog{width:min(920px,94vw);max-height:88vh;padding:0;border:0;border-radius:18px;background:var(--foodex-surface);color:var(--foodex-ink);box-shadow:0 24px 70px rgba(15,23,42,.26)}.order-popup-dialog::backdrop{background:rgba(15,23,42,.48);backdrop-filter:blur(2px)}.order-popup-shell{display:grid;grid-template-rows:auto minmax(0,1fr);max-height:88vh}.order-popup-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-bottom:1px solid var(--foodex-border);background:var(--foodex-palette-f8fafc)}.order-popup-head strong{font-size:.9rem}.order-popup-close{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--foodex-border);border-radius:999px;background:#fff;color:var(--foodex-ink);font-size:1.1rem;cursor:pointer}.order-popup-body{overflow:auto;padding:14px}.order-popup-loading{padding:30px;text-align:center;color:var(--foodex-muted);font-weight:800}.order-popup-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}.order-popup-summary>div,.order-popup-facts>div{display:grid;gap:3px;padding:9px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-palette-f8fafc)}.order-popup-summary small,.order-popup-facts span{font-size:.62rem;color:var(--foodex-muted)}.order-popup-summary strong.is-paid,.order-popup-facts strong.is-paid{color:var(--foodex-palette-15803d)}.order-popup-summary strong.is-unpaid,.order-popup-facts strong.is-unpaid{color:var(--foodex-palette-be123c)}.order-popup-section{margin-top:10px;padding:12px;border:1px solid var(--foodex-border);border-radius:14px}.order-popup-section h3{margin:0 0 10px;font-size:.82rem}.order-popup-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.order-popup-table-wrap{overflow:auto}.order-popup-table{width:100%;border-collapse:collapse;font-size:.7rem}.order-popup-table th,.order-popup-table td{padding:7px;border-bottom:1px solid var(--foodex-border);text-align:start}.order-popup-toolbar{display:flex;justify-content:flex-end;margin-bottom:8px}.order-print-header,.order-print-footer{display:none}.order-popup-timeline{display:grid;gap:0}.order-popup-timeline-item{position:relative;display:grid;grid-template-columns:18px minmax(0,1fr);gap:8px;padding:0 0 14px}.order-popup-timeline-item:not(:last-child)::before{content:"";position:absolute;top:12px;bottom:-1px;inset-inline-start:6px;width:2px;background:var(--foodex-palette-e2e8f0)}.order-popup-timeline-dot{position:relative;z-index:1;width:12px;height:12px;margin-top:3px;border:3px solid var(--foodex-palette-fff1f2);border-radius:999px;background:var(--foodex-palette-be123c)}.order-popup-timeline-item>div{display:grid;gap:3px}.order-popup-timeline-item strong{font-size:.72rem}.order-popup-timeline-item small{color:var(--foodex-muted)}.order-popup-timeline-item footer{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--foodex-muted);font-size:.59rem}
@media(max-width:760px){.order-popup-summary,.order-popup-facts{grid-template-columns:1fr 1fr}.order-popup-dialog{width:96vw}.order-popup-body{padding:10px}}
@media print{@page{margin:18mm 14mm 22mm}body *{visibility:hidden!important}.order-popup-dialog[open],.order-popup-dialog[open] *{visibility:visible!important}.order-popup-dialog[open]{position:absolute!important;inset:0!important;width:100%!important;max-width:none!important;max-height:none!important;margin:0!important;border-radius:0!important;box-shadow:none!important}.order-popup-head,[data-no-print]{display:none!important}.order-popup-body{overflow:visible!important;padding:0!important}.order-print-header{display:grid!important;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px;direction:ltr;padding:0 0 10px;margin-bottom:12px;border-bottom:2px solid var(--foodex-green)}.order-print-brand{grid-column:3;justify-self:end;display:flex!important;align-items:center;gap:8px;direction:ltr}.order-print-brand img{width:76px;height:52px;object-fit:contain}.order-print-brand strong{font-size:16px;color:var(--foodex-green-dark)}.order-print-type{grid-column:2;text-align:center}.order-print-type small{display:block;color:var(--foodex-muted);font-size:10px}.order-print-type strong{display:block;font-size:16px;margin-top:2px}.order-print-footer{display:flex!important;position:fixed;left:14mm;right:14mm;bottom:7mm;align-items:center;justify-content:center;gap:8px;padding-top:6px;border-top:1px solid var(--foodex-border);color:var(--foodex-muted);font-size:9px;background:#fff}.order-popup-section{break-inside:avoid}.order-popup-timeline-item{break-inside:avoid}}
.order-inline-unassign{margin-top:3px}.order-inline-unassign button{min-height:25px!important;height:25px!important;padding:0 9px!important;border:1px solid var(--foodex-palette-fda4af)!important;border-radius:999px!important;background:var(--foodex-palette-fff1f2)!important;color:var(--foodex-palette-be123c)!important;font-size:.6rem!important;font-weight:900!important;cursor:pointer}
.order-status-merged{text-align:center!important;vertical-align:middle!important}.order-status-merged .order-grid-primary{min-height:42px;align-content:center;justify-items:center;text-align:center}.order-status-merged .order-grid-primary>div:first-child{justify-content:center;width:100%}.order-delivered-merged{background:rgba(22,163,74,.035)}.order-cancelled-merged{background:rgba(190,18,60,.025)}.order-delivered-time{display:block;margin-top:4px;color:var(--foodex-muted);font-size:.6rem;font-weight:800;text-align:center}
.order-grid-actions{text-align:center}
.order-inline-status{display:grid;grid-template-columns:minmax(0,1fr) 27px;align-items:center;gap:5px;margin-top:3px;width:100%}.order-inline-status select{--status-bg:var(--foodex-palette-f1f5f9);--status-fg:var(--foodex-palette-475569);--status-border:var(--foodex-palette-cbd5e1);appearance:none;-webkit-appearance:none;min-width:0;width:100%;max-width:none;min-height:27px!important;height:27px!important;margin:0!important;padding:0 26px 0 9px!important;border:1px solid var(--status-border)!important;border-radius:999px!important;background-color:var(--status-bg)!important;color:var(--status-fg)!important;font-size:.63rem!important;font-weight:900!important;line-height:25px!important;box-sizing:border-box!important;box-shadow:none!important;cursor:pointer;background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%)!important;background-position:calc(100% - 13px) 11px,calc(100% - 9px) 11px!important;background-size:4px 4px,4px 4px!important;background-repeat:no-repeat!important}.order-inline-status select[data-state="pending"],.order-inline-status select[data-state="waiting"],.order-inline-status select[data-state="awaiting_dispatch"]{--status-bg:var(--foodex-palette-fff7ed);--status-fg:var(--foodex-palette-c2410c);--status-border:var(--foodex-palette-fdba74)}.order-inline-status select[data-state="confirmed"]{--status-bg:var(--foodex-palette-eff6ff);--status-fg:var(--foodex-palette-1d4ed8);--status-border:var(--foodex-palette-93c5fd)}.order-inline-status select[data-state="preparing"],.order-inline-status select[data-state="processing"]{--status-bg:var(--foodex-palette-f5f3ff);--status-fg:var(--foodex-palette-6d28d9);--status-border:var(--foodex-palette-c4b5fd)}.order-inline-status select[data-state="ready"]{--status-bg:var(--foodex-palette-ecfeff);--status-fg:var(--foodex-palette-0e7490);--status-border:var(--foodex-palette-67e8f9)}.order-inline-status select[data-state="out_for_delivery"],.order-inline-status select[data-state="on_the_way"],.order-inline-status select[data-state="assigned"]{--status-bg:var(--foodex-palette-eef2ff);--status-fg:var(--foodex-palette-4338ca);--status-border:var(--foodex-palette-a5b4fc)}.order-inline-status select[data-state="delivered"],.order-inline-status select[data-state="completed"],.order-inline-status select[data-state="paid"]{--status-bg:var(--foodex-palette-ecfdf5);--status-fg:var(--foodex-palette-15803d);--status-border:var(--foodex-palette-86efac)}.order-inline-status select[data-state="cancelled"],.order-inline-status select[data-state="failed"],.order-inline-status select[data-state="delivery_failed"]{--status-bg:var(--foodex-palette-fff1f2);--status-fg:var(--foodex-palette-be123c);--status-border:var(--foodex-palette-fda4af)}.order-inline-status-submit{width:27px!important;height:27px!important;min-width:27px!important;min-height:27px!important;margin:0!important;padding:0!important;border:1px solid var(--foodex-action-green)!important;border-radius:999px!important;background:var(--foodex-action-green)!important;color:#fff!important;font-size:.72rem!important;font-weight:900!important;line-height:25px!important;box-shadow:none!important;cursor:pointer}.order-inline-status-note{grid-column:1/-1;min-width:0;width:100%;box-sizing:border-box;min-height:27px!important;height:27px!important;margin:0!important;padding:0 9px!important;border:1px solid var(--foodex-border)!important;border-radius:999px!important;font-size:.61rem!important;line-height:25px!important}.order-inline-status-note[hidden]{display:none!important}.order-inline-picker{display:grid;grid-template-columns:minmax(0,1fr) 27px;align-items:center;gap:5px;margin-top:3px;width:100%}.order-inline-picker select{appearance:none;-webkit-appearance:none;min-width:0;width:100%;min-height:27px!important;height:27px!important;margin:0!important;padding:0 26px 0 9px!important;border:1px solid var(--foodex-palette-cbd5e1)!important;border-radius:999px!important;background-color:var(--foodex-palette-f1f5f9)!important;color:var(--foodex-palette-475569)!important;font-size:.63rem!important;font-weight:900!important;line-height:25px!important;box-sizing:border-box!important;box-shadow:none!important;cursor:pointer;background-image:linear-gradient(45deg,transparent 50%,currentColor 50%),linear-gradient(135deg,currentColor 50%,transparent 50%)!important;background-position:calc(100% - 13px) 11px,calc(100% - 9px) 11px!important;background-size:4px 4px,4px 4px!important;background-repeat:no-repeat!important}.order-inline-picker button{width:27px!important;height:27px!important;min-width:27px!important;min-height:27px!important;margin:0!important;padding:0!important;border:1px solid var(--foodex-action-green)!important;border-radius:999px!important;background:var(--foodex-action-green)!important;color:#fff!important;font-size:.72rem!important;font-weight:900!important;line-height:25px!important;box-shadow:none!important;cursor:pointer}.order-inline-picker button:disabled{opacity:.45;cursor:not-allowed}.order-inline-picker-reason{grid-column:1/-1;min-width:0;width:100%;box-sizing:border-box;min-height:27px!important;height:27px!important;margin:0!important;padding:0 9px!important;border:1px solid var(--foodex-border)!important;border-radius:999px!important;font-size:.61rem!important;line-height:25px!important}.order-inline-picker-reason[hidden]{display:none!important}
.status-tab{--status-tab-bg:#fff;--status-tab-fg:var(--foodex-ink);--status-tab-border:var(--foodex-border);border-color:var(--status-tab-border)!important;background:var(--status-tab-bg)!important;color:var(--status-tab-fg)!important}
.status-tab[data-order-action-tab]{--status-tab-bg:var(--foodex-palette-fff7ed);--status-tab-fg:var(--foodex-palette-c2410c);--status-tab-border:var(--foodex-palette-fdba74)}
.status-tab[data-order-status-tab="pending"]{--status-tab-bg:var(--foodex-palette-fff7ed);--status-tab-fg:var(--foodex-palette-c2410c);--status-tab-border:var(--foodex-palette-fed7aa)}
.status-tab[data-order-status-tab="confirmed"]{--status-tab-bg:var(--foodex-palette-eff6ff);--status-tab-fg:var(--foodex-palette-1d4ed8);--status-tab-border:var(--foodex-palette-bfdbfe)}
.status-tab[data-order-status-tab="preparing"]{--status-tab-bg:var(--foodex-palette-f5f3ff);--status-tab-fg:var(--foodex-palette-6d28d9);--status-tab-border:var(--foodex-palette-ddd6fe)}
.status-tab[data-order-status-tab="ready"]{--status-tab-bg:var(--foodex-palette-ecfeff);--status-tab-fg:var(--foodex-palette-0e7490);--status-tab-border:var(--foodex-palette-a5f3fc)}
.status-tab[data-order-status-tab="out_for_delivery"]{--status-tab-bg:var(--foodex-palette-eef2ff);--status-tab-fg:var(--foodex-palette-4338ca);--status-tab-border:var(--foodex-palette-c7d2fe)}
.status-tab[data-order-status-tab="delivered"]{--status-tab-bg:var(--foodex-palette-ecfdf5);--status-tab-fg:var(--foodex-palette-15803d);--status-tab-border:var(--foodex-palette-bbf7d0)}
.status-tab[data-order-status-tab="cancelled"],.status-tab[data-order-status-tab="failed"]{--status-tab-bg:var(--foodex-palette-fff1f2);--status-tab-fg:var(--foodex-palette-be123c);--status-tab-border:var(--foodex-palette-fecdd3)}
.status-tab[aria-current="page"]{outline:2px solid color-mix(in srgb,var(--status-tab-fg) 38%,transparent);outline-offset:1px}
.row-actions{position:relative;display:inline-block}.row-actions summary{list-style:none;width:34px;height:34px;border:1px solid var(--foodex-green,var(--foodex-palette-179c52));border-radius:50%;display:grid;place-items:center;background:var(--foodex-green,var(--foodex-palette-179c52));color:#fff;cursor:pointer;font-size:20px;line-height:1}.row-actions summary::-webkit-details-marker{display:none}.row-actions[open] summary{box-shadow:0 0 0 3px rgba(23,156,82,.14)}.row-action-menu{position:absolute;z-index:40;inset-inline-end:0;top:40px;width:310px;background:#fff;border:1px solid var(--foodex-border);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.14);padding:8px}.row-action-menu>a{display:block;text-decoration:none;color:var(--foodex-ink);font-weight:800;padding:9px 10px;border-radius:8px}.row-action-menu>a:hover{background:var(--foodex-palette-f6f8fa)}.row-action-menu form{display:grid;gap:8px;margin:8px 0 0;padding-top:8px;border-top:1px solid var(--foodex-border)}.row-action-menu select,.row-action-menu input{min-width:0;width:100%;box-sizing:border-box}.row-action-menu button{width:100%}.dispatch-cell{display:grid;gap:4px;min-width:0}.dispatch-cell strong,.dispatch-cell span{overflow-wrap:anywhere}.dispatch-warning{padding:7px 9px;border-radius:9px;background:var(--foodex-palette-fff7e6);border:1px solid var(--foodex-palette-f3c36b);font-weight:800}.dispatch-meta{color:var(--foodex-muted);font-size:.78rem}.timeline{display:grid;gap:8px}.timeline-item{padding:10px;border:1px solid var(--foodex-border);border-radius:10px}.sales-ops-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
.sales-ops-bars{height:180px;align-items:flex-end}
.sales-ops-bar-link{--order-chart-color:var(--foodex-palette-158a3a);--order-chart-color-2:var(--foodex-palette-38bdf8);min-height:8px;text-decoration:none;background:linear-gradient(180deg,var(--order-chart-color-2) 0%,var(--order-chart-color) 72%,color-mix(in srgb,var(--order-chart-color) 80%,var(--foodex-palette-0f172a)) 100%)!important;border-radius:8px 8px 3px 3px;box-shadow:0 5px 14px color-mix(in srgb,var(--order-chart-color) 20%,transparent);transform-origin:bottom center;transform:scaleY(0);opacity:.72}
.sales-ops-bar-link:nth-child(8n+1){--order-chart-color:var(--foodex-palette-158a3a);--order-chart-color-2:var(--foodex-palette-22c55e)}.sales-ops-bar-link:nth-child(8n+2){--order-chart-color:var(--foodex-palette-2563eb);--order-chart-color-2:var(--foodex-palette-38bdf8)}.sales-ops-bar-link:nth-child(8n+3){--order-chart-color:var(--foodex-palette-7c3aed);--order-chart-color-2:var(--foodex-palette-c084fc)}.sales-ops-bar-link:nth-child(8n+4){--order-chart-color:var(--foodex-palette-ea580c);--order-chart-color-2:var(--foodex-palette-facc15)}.sales-ops-bar-link:nth-child(8n+5){--order-chart-color:var(--foodex-palette-dc2626);--order-chart-color-2:var(--foodex-palette-fb7185)}.sales-ops-bar-link:nth-child(8n+6){--order-chart-color:var(--foodex-palette-0891b2);--order-chart-color-2:var(--foodex-palette-2dd4bf)}.sales-ops-bar-link:nth-child(8n+7){--order-chart-color:var(--foodex-palette-be123c);--order-chart-color-2:var(--foodex-palette-f472b6)}.sales-ops-bar-link:nth-child(8n+8){--order-chart-color:var(--foodex-palette-ca8a04);--order-chart-color-2:var(--foodex-palette-fdba74)}
.order-tab-panel.charts-animate .sales-ops-bar-link{animation:orderChartGrow 1.65s cubic-bezier(.16,.84,.22,1) forwards}
.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(2){animation-delay:.08s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(3){animation-delay:.16s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(4){animation-delay:.24s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(5){animation-delay:.32s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(6){animation-delay:.40s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(7){animation-delay:.48s}.order-tab-panel.charts-animate .sales-ops-bar-link:nth-child(8){animation-delay:.56s}
.sales-ops-exceptions,.sales-ops-simple-list,.sales-ops-ranked-bars{display:grid;gap:5px}
.sales-ops-exceptions a,.sales-ops-simple-list a,.sales-ops-simple-list>div,.sales-ops-ranked-bars a{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center;padding:7px 9px;border:1px solid var(--foodex-border);border-radius:9px;color:inherit;text-decoration:none}
.sales-ops-ranked-bars a{grid-template-columns:minmax(90px,.8fr) minmax(100px,2fr) auto;--order-rank-color:var(--foodex-palette-158a3a);--order-rank-color-2:var(--foodex-palette-38bdf8)}
.sales-ops-ranked-bars a:nth-child(8n+1){--order-rank-color:var(--foodex-palette-158a3a);--order-rank-color-2:var(--foodex-palette-22c55e)}.sales-ops-ranked-bars a:nth-child(8n+2){--order-rank-color:var(--foodex-palette-2563eb);--order-rank-color-2:var(--foodex-palette-38bdf8)}.sales-ops-ranked-bars a:nth-child(8n+3){--order-rank-color:var(--foodex-palette-7c3aed);--order-rank-color-2:var(--foodex-palette-c084fc)}.sales-ops-ranked-bars a:nth-child(8n+4){--order-rank-color:var(--foodex-palette-ea580c);--order-rank-color-2:var(--foodex-palette-facc15)}.sales-ops-ranked-bars a:nth-child(8n+5){--order-rank-color:var(--foodex-palette-dc2626);--order-rank-color-2:var(--foodex-palette-fb7185)}.sales-ops-ranked-bars a:nth-child(8n+6){--order-rank-color:var(--foodex-palette-0891b2);--order-rank-color-2:var(--foodex-palette-2dd4bf)}.sales-ops-ranked-bars a:nth-child(8n+7){--order-rank-color:var(--foodex-palette-be123c);--order-rank-color-2:var(--foodex-palette-f472b6)}.sales-ops-ranked-bars a:nth-child(8n+8){--order-rank-color:var(--foodex-palette-ca8a04);--order-rank-color-2:var(--foodex-palette-fdba74)}
.sales-ops-ranked-bars i{display:block;height:9px;border-radius:999px;background:linear-gradient(90deg,var(--foodex-palette-f8fafc),var(--foodex-palette-e2e8f0));overflow:hidden}
.sales-ops-ranked-bars b{display:block;height:100%;background:linear-gradient(90deg,var(--order-rank-color),var(--order-rank-color-2));border-radius:inherit;transform:scaleX(0)}
html[dir="rtl"] .sales-ops-ranked-bars b{transform-origin:right center}html[dir="ltr"] .sales-ops-ranked-bars b{transform-origin:left center}
.order-tab-panel.charts-animate .sales-ops-ranked-bars b{animation:orderRankGrow 1.85s cubic-bezier(.16,.84,.22,1) forwards}
.order-tab-panel.charts-animate [data-foodex-module-chart]{animation:orderChartCardIn .85s ease-out both}
.order-tab-panel.charts-animate [data-foodex-module-chart]:nth-child(2){animation-delay:.12s}.order-tab-panel.charts-animate [data-foodex-module-chart]:nth-child(3){animation-delay:.24s}
@keyframes orderChartGrow{0%{transform:scaleY(0);opacity:.2}55%{opacity:.86}100%{transform:scaleY(1);opacity:1}}
@keyframes orderRankGrow{0%{transform:scaleX(0);filter:saturate(.75)}100%{transform:scaleX(1);filter:saturate(1)}}
@keyframes orderChartCardIn{0%{opacity:.35;transform:translateY(5px)}100%{opacity:1;transform:translateY(0)}}
@media(max-width:1500px) and (min-width:1001px){.shell{grid-template-columns:minmax(0,1fr) 210px}.main{padding:16px 14px}.sidebar{padding:14px}.filters{grid-template-columns:repeat(3,minmax(0,1fr));padding:12px;gap:8px}.ops-table{font-size:.72rem}.ops-table th,.ops-table td{padding:7px 4px}.dispatch-warning{padding:5px 6px}.dispatch-meta{font-size:.7rem}.row-actions summary{width:30px;height:30px;font-size:18px}.row-action-menu{width:280px}}
@media(max-width:1180px){.order-workspace-bar{align-items:center}.order-workspace-tabs{flex:1 1 auto}.order-workspace-filter-slot{flex:0 0 auto}}
@media(max-width:1000px){.shell{grid-template-columns:1fr}.sidebar{grid-row:1}.main{grid-row:2;padding:16px}.filters{grid-template-columns:1fr 1fr}.table-wrap{overflow-x:auto}.ops-table{min-width:980px;table-layout:auto}.sales-ops-chart-grid{grid-template-columns:1fr}}
@media(max-width:700px){.order-workspace-bar{gap:5px}.order-workspace-tab{min-height:36px;padding-inline:11px}.order-workspace-tabs,.order-analytics-tabs{gap:4px}.order-analytics-subtab{min-height:32px;padding-inline:10px}}
@media(max-width:600px){.filters{grid-template-columns:1fr}.sales-ops-ranked-bars a{grid-template-columns:1fr auto}.sales-ops-ranked-bars i{grid-column:1/-1}}
@media(prefers-reduced-motion:reduce){.order-kpi-card::before,.order-tab-panel.charts-animate .sales-ops-bar-link,.order-tab-panel.charts-animate .sales-ops-ranked-bars b,.order-tab-panel.charts-animate [data-foodex-module-chart]{animation:none}.order-kpi-item,.order-workspace-tab,.order-analytics-subtab{transition:none}.order-kpi-item:hover,.order-workspace-tab:hover,.order-analytics-subtab:hover{transform:none}.sales-ops-bar-link{transform:none;opacity:1}.sales-ops-ranked-bars b{transform:none}}
</style>
</head>
<body>
@php
$businessLabel = static function ($value): string {
    $key = strtolower(trim((string) ($value ?? '')));
    if ($key === '') {
        return '—';
    }

    $translated = __('order_operations.business_labels.'.$key);

    return $translated !== 'order_operations.business_labels.'.$key
        ? $translated
        : ucwords(str_replace(['_', '-'], ' ', $key));
};
@endphp
<div class="shell foodex-admin-layout">
<main class="main foodex-admin-main foodex-admin-page">
@include('admin._global-header')
@if(session('status'))<div class="foodex-state" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="foodex-state" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@include('admin._order-new-wizard')

<?php
$dashboardKpis = $dashboardKpis ?? [];
$dashboardBaseQuery = $dashboardBaseQuery ?? [];
$dashboardMoney = $dashboardMoney ?? static fn ($value): string => '—';
$dailyMax = isset($dailyMax) ? max(1, (int) $dailyMax) : 1;
$statusMax = isset($statusMax) ? max(1, (int) $statusMax) : 1;
$dashboardVisible = (bool) ($dashboardVisible ?? false);
?>
<?php if ($dashboardVisible && is_array($dashboard ?? null)): ?>
<section class="foodex-module-overview" data-sales-operations-dashboard data-state="current" aria-label="{{ __('order_operations.dashboard.title') }}">
    <div class="order-kpi-card" data-order-kpi-card aria-label="{{ __('order_operations.dashboard.title') }}">
        <?php foreach ($dashboardKpis as $metric): ?>
            <a class="order-kpi-item"
               data-order-kpi="{{ $metric['key'] }}"
               data-tone="{{ $metric['tone'] }}"
               href="{{ $metric['drilldown_url'] }}">
                <span class="order-kpi-head">
                    <span class="order-kpi-icon" aria-hidden="true">@include('admin._premium-icon',['name'=>$metric['icon']])</span>
                    <span class="order-kpi-label">{{ $metric['label'] }}</span>
                </span>
                <strong class="order-kpi-value foodex-number"
                        data-order-counter
                        data-counter-target="{{ $metric['value'] }}"
                        <?php if (is_numeric($metric['raw'] ?? null)): ?> data-counter-raw="{{ $metric['raw'] }}" data-counter-decimals="{{ $metric['decimals'] ?? 0 }}" <?php endif; ?>>{{ $metric['value'] }}</strong>
                <?php if (is_array($metric['status'] ?? null)): ?>
                    <span class="order-kpi-state"
                          data-state="{{ data_get($metric,'status.state','current') }}"
                          title="{{ data_get($metric,'status.label','') }}"
                          aria-label="{{ data_get($metric,'status.label','') }}"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="order-workspace-bar" data-order-workspace-bar>
        <nav class="order-workspace-tabs"
             role="tablist"
             aria-label="{{ $isAr ? 'أقسام إدارة الطلبات' : 'Order management sections' }}"
             data-order-tabs
             data-default-tab="orders">
            <button class="order-workspace-tab"
                    type="button"
                    role="tab"
                    aria-selected="false"
                    data-order-tab="analytics">{{ $isAr ? 'التحليلات' : 'Analytics' }}</button>
            <button class="order-workspace-tab"
                    type="button"
                    role="tab"
                    aria-selected="true"
                    data-order-tab="orders">{{ $isAr ? 'الطلبات' : 'Orders' }} <span class="order-workspace-tab-count">{{ number_format((int)$statusTotal) }}</span></button>
        </nav>
        <div class="order-workspace-filter-slot" data-order-workspace-filter>
            <div data-order-filter-mode="orders">
                @include('admin._order-operations-order-filters')
            </div>
            <div data-order-filter-mode="analytics" hidden>
                @include('admin._order-operations-header-filters')
            </div>
        </div>
    </div>

    <div class="order-tab-panel" role="tabpanel" data-order-panel="analytics" hidden>
    <nav class="order-analytics-tabs"
         role="tablist"
         aria-label="{{ $isAr ? 'أقسام التحليلات' : 'Analytics sections' }}"
         data-order-analytics-tabs
         data-default-analytics-tab="performance">
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="true" data-analytics-tab="performance">{{ $isAr ? 'الأداء' : 'Performance' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="operations">{{ $isAr ? 'التشغيل' : 'Operations' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="channels">{{ $isAr ? 'القنوات والدفع' : 'Channels & Payment' }}</button>
        <button class="order-analytics-subtab" type="button" role="tab" aria-selected="false" data-analytics-tab="commerce">{{ $isAr ? 'العملاء والمنتجات' : 'Customers & Products' }}</button>
    </nav>
    <div class="foodex-module-panels">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="performance" aria-labelledby="sales-trend-title">
            <div class="foodex-viz-header">
                <div><h3 class="foodex-viz-title" id="sales-trend-title">{{ __('order_operations.dashboard.charts.trend') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.trend_help') }}</p></div>
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.reports.index', array_merge($dashboardBaseQuery, ['report' => 'orders'])) }}">{{ __('order_operations.dashboard.open_report') }}</a>
            </div>
            <?php if (($dashboard['daily'] ?? []) === []): ?>
                <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
            <?php else: ?>
                <div class="foodex-viz-bars sales-ops-bars" role="img" aria-label="{{ __('order_operations.dashboard.charts.trend') }}">
                    <?php foreach (($dashboard['daily'] ?? []) as $point): ?>
                        <a class="foodex-viz-bar sales-ops-bar-link" style="height:{{ max(8, round(((int)$point['orders'] / $dailyMax) * 100)) }}%" href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['from'=>$point['day'],'to'=>$point['day']])) }}" aria-label="{{ $point['day'] }} · {{ $point['orders'] }} {{ __('order_operations.dashboard.columns.orders') }}"></a>
                    <?php endforeach; ?>
                </div>
                <p class="foodex-module-chart-summary">{{ __('order_operations.dashboard.period') }}: {{ $dashboard['period']['from'] }} → {{ $dashboard['period']['to'] }}</p>
                <details><summary>{{ __('order_operations.dashboard.details') }}</summary><div class="foodex-module-chart-table-wrap"><table class="foodex-module-chart-table" data-pagination-exempt="bounded-sales-daily-summary"><thead><tr><th>{{ __('order_operations.dashboard.columns.day') }}</th><th>{{ __('order_operations.dashboard.columns.orders') }}</th><th>{{ __('order_operations.dashboard.columns.revenue') }}</th></tr></thead><tbody><?php foreach (($dashboard['daily'] ?? []) as $point): ?><tr><td>{{ $point['day'] }}</td><td>{{ number_format((int)$point['orders']) }}</td><td>{{ $point['revenue'] === null ? __('order_operations.dashboard.multiple_currencies') : $dashboardMoney($point['revenue']) }}</td></tr><?php endforeach; ?></tbody></table></div></details>
            <?php endif; ?>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="performance" aria-labelledby="sales-exception-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-exception-title">{{ __('order_operations.dashboard.charts.exceptions') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.exceptions_help') }}</p></div></div>
            <div class="sales-ops-exceptions">
                <?php foreach (($dashboard['exceptions'] ?? []) as $exception): ?>
                    <a data-sales-exception="{{ $exception['key'] }}" href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['exception'=>$exception['key']])) }}"><span>{{ __('order_operations.dashboard.exceptions.'.$exception['key']) }}</span><strong>{{ number_format((int)$exception['count']) }}</strong></a>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <div class="sales-ops-chart-grid">
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" hidden aria-labelledby="sales-status-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-status-title">{{ __('order_operations.dashboard.charts.status') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.status_help') }}</p></div></div>
            <?php if (($dashboard['status_breakdown'] ?? []) === []): ?>
                <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
            <?php else: ?>
                <div class="sales-ops-ranked-bars">
                    <?php foreach (($dashboard['status_breakdown'] ?? []) as $point): ?>
                        <a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['status' => (string)$point['status']])) }}"><span>{{ $businessLabel((string)$point['status']) }}</span><i><b style="width:{{ round(((int)$point['orders'] / $statusMax) * 100) }}%"></b></i><strong>{{ number_format((int)$point['orders']) }}</strong></a> {{-- localization-gate: allow — status token inside route query is technical; visible status uses $businessLabel. --}}
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" hidden aria-labelledby="sales-channel-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-channel-title">{{ __('order_operations.dashboard.charts.channels') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.channels_help') }}</p></div></div>
            <div class="sales-ops-simple-list">
                <?php if (($dashboard['channel_breakdown'] ?? []) === []): ?>
                    <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
                <?php else: ?>
                    <?php foreach (($dashboard['channel_breakdown'] ?? []) as $point): ?>
                        <a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['channel' => (string)$point['channel']])) }}"><span>{{ $businessLabel((string)$point['channel']) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a> {{-- localization-gate: allow — channel token inside route query is technical; visible channel uses $businessLabel. --}}
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" hidden aria-labelledby="sales-payment-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-payment-title">{{ __('order_operations.dashboard.charts.payments') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.payments_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['payment_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['payment_breakdown'] ?? []) as $point): ?><div><span>{{ $point['provider'] }} · {{ $businessLabel($point['status']) }}</span><strong>{{ number_format((int)$point['payments']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" hidden aria-labelledby="sales-products-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-products-title">{{ __('order_operations.dashboard.charts.products') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.products_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['top_products'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['top_products'] ?? []) as $point): ?><a href="{{ route('admin.reports.index', array_merge($dashboardBaseQuery, ['report'=>'products','product_id'=>$point['product_id']])) }}"><span>{{ $point['sku'] }} · {{ $point['name'] }}</span><strong>{{ \App\Support\DisplayNumber::quantity((float)$point['quantity']) }}</strong></a><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" hidden aria-labelledby="sales-stores-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-stores-title">{{ __('order_operations.dashboard.charts.stores') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.stores_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['store_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['store_breakdown'] ?? []) as $point): ?><a href="{{ route('admin.operations.orders.index', array_merge($dashboardBaseQuery, ['store_id'=>$point['store_id']])) }}"><span>{{ $point['store'] }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="commerce" hidden aria-labelledby="sales-customers-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-customers-title">{{ __('order_operations.dashboard.charts.customers') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.customers_help') }}</p></div></div>
            <div class="sales-ops-simple-list">
                <?php if (($dashboard['top_customers'] ?? []) === []): ?>
                    <div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div>
                <?php else: ?>
                    <?php foreach (($dashboard['top_customers'] ?? []) as $point): ?>
                        <?php
                            $customerChannelCode = (string) $point['channel'];
                            $customerReportParams = array_merge(
                                $dashboardBaseQuery,
                                ['report' => 'customers', 'channel' => $customerChannelCode],
                                $point['customer_id'] ? ['customer_id' => $point['customer_id']] : [],
                            );
                        ?>
                        <a href="{{ route('admin.reports.index', $customerReportParams) }}"><span>{{ $point['customer'] }} · {{ $businessLabel($customerChannelCode) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" hidden aria-labelledby="sales-cancel-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-cancel-title">{{ __('order_operations.dashboard.charts.cancellations') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.cancellations_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['cancellation_reasons'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['cancellation_reasons'] ?? []) as $point): ?><div><span>{{ $point['reason']==='unspecified' ? __('order_operations.dashboard.unspecified_reason') : $businessLabel($point['reason']) }}</span><strong>{{ number_format((int)$point['count']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>

        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="operations" hidden aria-labelledby="sales-fulfillment-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-fulfillment-title">{{ __('order_operations.dashboard.charts.fulfillment') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.fulfillment_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php foreach (($dashboard['fulfillment_breakdown'] ?? []) as $point): ?><div><span>{{ __('order_operations.dashboard.fulfillment_buckets.'.$point['bucket']) }}</span><strong>{{ number_format((int)$point['count']) }}</strong></div><?php endforeach; ?></div>
        </article>

        <?php if (is_array($dashboard['source_breakdown'] ?? null)): ?>
        <article class="foodex-viz-card foodex-module-chart" data-foodex-module-chart data-analytics-group="channels" hidden aria-labelledby="sales-source-title">
            <div class="foodex-viz-header"><div><h3 class="foodex-viz-title" id="sales-source-title">{{ __('order_operations.dashboard.charts.sources') }}</h3><p class="foodex-viz-subtitle">{{ __('order_operations.dashboard.charts.sources_help') }}</p></div></div>
            <div class="sales-ops-simple-list"><?php if (($dashboard['source_breakdown'] ?? []) === []): ?><div class="foodex-viz-empty">{{ __('order_operations.dashboard.empty_chart') }}</div><?php else: ?><?php foreach (($dashboard['source_breakdown'] ?? []) as $point): ?><div><span>{{ $businessLabel($point['source']) }}</span><strong>{{ number_format((int)$point['orders']) }}</strong></div><?php endforeach; ?><?php endif; ?></div>
        </article>
        <?php endif; ?>
    </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$dashboardVisible): ?>
<div class="order-workspace-bar" data-order-workspace-bar>
    <nav class="order-workspace-tabs"
         role="tablist"
         aria-label="{{ $isAr ? 'أقسام إدارة الطلبات' : 'Order management sections' }}"
         data-order-tabs
         data-default-tab="orders">
        <button class="order-workspace-tab" type="button" role="tab" aria-selected="true" data-order-tab="orders">{{ $isAr ? 'الطلبات' : 'Orders' }} <span class="order-workspace-tab-count">{{ number_format((int)$orders->total()) }}</span></button>
    </nav>
    <div class="order-workspace-filter-slot" data-order-workspace-filter>
        <div data-order-filter-mode="orders">
            @include('admin._order-operations-order-filters')
        </div>
    </div>
</div>
<?php endif; ?>

<div class="order-tab-panel" role="tabpanel" data-order-panel="orders">
@php($channelQuery=request()->except(['channel','store_id','driver_id','dispatch_status','exception','page','order']))
@php($channelCodes=collect($availableChannels ?? [])->pluck('code'))
@php($orderGridResetQuery=['channel'=>$selectedChannel] + ($selectedStoreId !== null ? ['store_id'=>$selectedStoreId] : []))
@if(count($availableChannels ?? []) > 1)
<nav class="status-tabs" aria-label="{{ $isAr ? 'قنوات الطلبات' : 'Order channels' }}" data-order-channel-tabs data-order-channel-selected="{{ $selectedChannel }}">
@foreach($availableChannels as $channelOption)
<a class="status-tab" data-order-channel-tab="{{ $channelOption['code'] }}" href="{{ route('admin.operations.orders.index',array_merge($channelQuery,['channel'=>$channelOption['code']])) }}" @if($selectedChannel===$channelOption['code']) aria-current="page" @endif><span>{{ $channelOption['label'] }}</span></a>
@endforeach
</nav>
@endif

@php($tabQuery=request()->except(['status','page','order','edit','action_required']))
@php($actionQuery=request()->except(['status','page','order','edit','action_required','dispatch_status','exception']))
<nav class="status-tabs" aria-label="{{ __('order_operations.statuses') }}" data-order-status-tabs data-order-status-selected="{{ $selectedStatus ?? 'all' }}">
<a class="status-tab"
   data-order-action-tab
   href="{{ route('admin.operations.orders.index',array_merge($actionQuery,['action_required'=>1])) }}#order-tab-orders"
   @if($selectedActionRequired) aria-current="page" @endif>
<span>{{ $isAr ? 'تحتاج إجراء' : 'Action required' }}</span><span class="status-tab-count">{{ $actionRequiredCount }}</span>
</a>
<a class="status-tab"
   data-order-status-tab="all"
   href="{{ route('admin.operations.orders.index',array_merge($tabQuery,['action_required'=>0])) }}#order-tab-orders"
   @if($selectedStatus===null && !$selectedActionRequired) aria-current="page" @endif>
<span>{{ __('order_operations.all') }}</span><span class="status-tab-count">{{ $statusTotal }}</span>
</a>
@foreach($statusTabs as $tab)
<a class="status-tab"
   data-order-status-tab="{{ $tab['code'] }}"
   href="{{ route('admin.operations.orders.index',array_merge($tabQuery,['status'=>$tab['code'],'action_required'=>0])) }}#order-tab-orders"
   @if($selectedStatus===$tab['code'] && !$selectedActionRequired) aria-current="page" @endif>
<span>{{ $tab['label'] }}</span><span class="status-tab-count">{{ $tab['count'] }}</span>
</a>
@endforeach
</nav>

<section class="foodex-card panel table-wrap">
<table class="foodex-table ops-table"><thead><tr>
<th>{{ $isAr ? 'الطلب' : 'Order' }}</th>
<th>{{ $isAr ? 'العميل' : 'Customer' }}</th>
<th>{{ $isAr ? 'الدفع والإجمالي' : 'Payment & total' }}</th>
<th>{{ __('order_operations.columns.status') }}</th>
<th>{{ $isAr ? 'التنفيذ والتوجيه' : 'Execution & routing' }}</th>
<th>{{ __('order_operations.customer_service_owner.column') }}</th>
<th>{{ __('order_operations.columns.actions') }}</th>
</tr></thead><tbody>
@forelse($rows as $row)
@php($rowStatusState = strtolower((string) $row['status']))
@php($rowDelivered = in_array($rowStatusState, ['delivered','completed'], true))
@php($rowMergedStatus = $rowDelivered || $rowStatusState === 'cancelled')
@php($rowActionRequired = !$rowMergedStatus && ($row['dispatch_status'] ?? '') === 'awaiting_dispatch')
@php($rowExecutor = $row['dispatch_assignee'] ?? $row['driver'] ?? null)
@php($rowWithDriver = !empty($row['has_active_driver']))
@php($rowCurrentDriverId = (int)($row['driver_id'] ?? 0))
@php($rowNeedsB2bDispatch = $row['channel']==='b2b' && $rowStatusState==='ready' && empty($row['dispatch_assignee_id']))
@php($rowStatusVisualState = $row['status_visual_state'] ?? $rowStatusState)
<tr class="order-grid-row {{ $rowActionRequired ? 'is-action-required' : '' }}" data-order-row="{{ $row['id'] }}" data-action-required="{{ $rowActionRequired ? '1' : '0' }}">
<td>
    <div class="order-grid-primary">
        <a href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id']])) }}"><strong>{{ $row['number'] }}</strong></a>
        <div class="order-grid-meta">
            <span class="order-mini-chip">{{ $businessLabel($row['source']) }}</span>
            <span>{{ optional($row['created_at'])->timezone('Asia/Kuwait')?->format('m-d H:i') ?? '—' }}</span>
        </div>
    </div>
</td>
<td>
    <div class="order-grid-primary">
        <span class="order-grid-title">{{ $row['customer'] }}</span>
        <div class="order-grid-meta">
            <span>{{ $row['store'] }}</span>
            <span class="order-mini-chip">{{ $businessLabel((string)$row['channel']) }}</span>
        </div>
    </div>
</td>
<td>
    @php($rowPaid = strtolower((string)($row['payment_status'] ?? '')) === 'paid')
    <div class="order-financial">
        <strong class="{{ $rowPaid ? 'is-paid' : 'is-unpaid' }}">{{ number_format($row['total'],2) }} {{ $row['currency'] }}</strong>
        <small>{{ $businessLabel($row['payment_status']) }}@if($row['payment_provider']) · {{ strtoupper(str_replace(['_','-'],' ',(string)$row['payment_provider'])) }}@endif</small>
        <div class="order-financial-actions" data-order-financial-actions>
            <button class="order-financial-action" type="button" title="{{ $isAr ? 'تفاصيل الطلب' : 'Order details' }}" aria-label="{{ $isAr ? 'تفاصيل الطلب' : 'Order details' }}" data-order-details-icon data-order-popup data-order-popup-title="{{ $isAr ? 'تفاصيل الطلب' : 'Order details' }}" data-order-popup-url="{{ route('admin.operations.orders.popup',['order'=>$row['id'],'kind'=>'details']) }}">@include('admin._premium-icon',['name'=>'details'])</button>
            @if($row['invoice_id'] && $row['can_view_invoice'])
                <button class="order-financial-action" type="button" title="{{ $isAr ? 'الفاتورة' : 'Invoice' }}" aria-label="{{ $isAr ? 'الفاتورة' : 'Invoice' }}" data-order-invoice-icon data-order-popup data-order-popup-title="{{ $isAr ? 'الفاتورة' : 'Invoice' }}" data-order-popup-url="{{ route('admin.operations.orders.popup',['order'=>$row['id'],'kind'=>'invoice']) }}">@include('admin._premium-icon',['name'=>'invoice'])</button>
            @endif
            <button class="order-financial-action is-timeline" type="button" title="{{ $isAr ? 'تايم لاين الطلب' : 'Order timeline' }}" aria-label="{{ $isAr ? 'تايم لاين الطلب' : 'Order timeline' }}" data-order-timeline-icon data-order-popup data-order-popup-title="{{ $isAr ? 'تايم لاين الطلب' : 'Order timeline' }}" data-order-popup-url="{{ route('admin.operations.orders.popup',['order'=>$row['id'],'kind'=>'timeline']) }}">@include('admin._premium-icon',['name'=>'timeline'])</button>
        </div>
    </div>
</td>
<td @if($rowMergedStatus) colspan="2" class="order-status-merged {{ $rowDelivered ? 'order-delivered-merged' : 'order-cancelled-merged' }}" data-order-status-merged @endif>
    <div class="order-grid-primary">
        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">
            <span class="order-status-chip" data-state="{{ $rowStatusVisualState }}">{{ $row['status_label'] }}</span>
            @if($rowNeedsB2bDispatch)
                <span class="order-ready-dispatch-alert" data-b2b-ready-dispatch-alert>{{ $isAr ? 'برجاء تعيين سائق' : 'Please assign a driver' }}</span>
            @endif
        </div>
        @if($rowDelivered)
            <small class="order-delivered-time" data-order-delivered-time>
                {{ $isAr ? 'وقت التسليم' : 'Delivered at' }}:
                {{ $row['delivered_at'] ? \Illuminate\Support\Carbon::parse((string)$row['delivered_at'])->timezone('Asia/Kuwait')->format('Y-m-d H:i:s') : '—' }}
            </small>
        @endif
        @if($row['can_manage'] && count($row['available_statuses']))
            <form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}" class="order-inline-status" data-order-inline-status>
                @csrf
                <select name="status" required aria-label="{{ __('order_operations.next_status') }}" data-order-inline-status-select data-state="{{ $rowStatusState }}">
                    <option value="">{{ $rowWithDriver ? ($isAr ? 'إلغاء الطلب' : 'Cancel order') : __('order_operations.choose_next_status') }}</option>
                    @foreach($row['available_statuses'] as $status)
                        <option value="{{ $status['code'] }}">{{ $status['label'] }}</option>
                    @endforeach
                </select>
                <button class="order-inline-status-submit" type="submit" aria-label="{{ __('order_operations.update_status') }}" title="{{ __('order_operations.update_status') }}">✓</button>
                @if($row['channel']==='b2b' && $row['status']==='pending')
                    <input class="order-inline-status-note" name="note" maxlength="1000" data-order-inline-rejection-note hidden placeholder="{{ $isAr ? 'سبب الرفض (إلزامي عند الرفض)' : 'Rejection reason (required when rejecting)' }}">
                @endif
            </form>
        @endif
        @if(!$rowMergedStatus && $rowActionRequired && !$rowNeedsB2bDispatch)
            <span class="order-attention-chip">{{ $isAr ? 'يحتاج إجراء' : 'Action required' }}</span>
        @endif
    </div>
</td>
@if(!$rowMergedStatus)
<td class="{{ ($row['dispatch_locked_until_confirmation'] ?? false) ? 'order-dispatch-cell-locked' : '' }}" @if($row['dispatch_locked_until_confirmation'] ?? false) title="{{ $isAr ? 'التوجيه متاح بعد تأكيد الطلب' : 'Dispatch is available after order confirmation' }}" @endif>
    <div class="order-execution {{ ($row['dispatch_locked_until_confirmation'] ?? false) ? 'is-dispatch-locked' : '' }}" @if($row['dispatch_locked_until_confirmation'] ?? false) data-order-dispatch-locked aria-disabled="true" @endif>
        @if($rowActionRequired)
            <span class="order-status-chip" data-state="awaiting_dispatch">{{ __('order_operations.dispatch.awaiting_queue') }}</span>
        @elseif($rowExecutor)
            <strong>{{ $rowExecutor }}</strong>
            <small>{{ $businessLabel($row['dispatch_assignee_type'] ?: ($row['assignment_status'] ? 'driver' : 'unassigned')) }}</small>
        @else
            <span class="order-mini-chip">{{ __('order_operations.unassigned') }}</span>
        @endif
        @if($row['dispatch_territory'])
            <small>{{ $row['dispatch_territory'] }}</small>
        @endif
        @if($row['can_dispatch_authorized'] ?? false)
            @php($dispatchInitial = $rowWithDriver ? '' : ($row['dispatch_assignee_id'] ? (($row['dispatch_assignee_type'] ?: 'driver').':'.$row['dispatch_assignee_id']) : ''))
            <form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" class="order-inline-picker" data-order-inline-dispatch @if(!$row['can_dispatch']) aria-disabled="true" @endif>
                @csrf @method('PATCH')
                <input type="hidden" name="assignee_type" value="{{ $rowWithDriver ? 'driver' : ($row['dispatch_assignee_type'] ?: ($row['channel']==='b2b' ? '' : 'driver')) }}" data-order-inline-assignee-type>
                <input type="hidden" name="assignee_id" value="{{ $rowWithDriver ? '' : (string)($row['dispatch_assignee_id'] ?? '') }}" data-order-inline-assignee-id>
                <select name="dispatch_target" required data-order-inline-picker-select data-initial-value="{{ $dispatchInitial }}" aria-label="{{ $rowWithDriver ? ($isAr ? 'سحب وتحويل الطلب لسائق آخر' : 'Withdraw and transfer order to another driver') : ($row['channel']==='b2b' ? ($isAr ? 'اختر سائق أو فان' : 'Choose Driver or Van') : __('order_operations.dispatch.choose_driver')) }}" @disabled(!$row['can_dispatch'])>
                    <option value="">{{ $rowWithDriver ? ($isAr ? 'سحب وتحويل الطلب لسائق آخر' : 'Withdraw and transfer order to another driver') : ($row['channel']==='b2b' ? ($isAr ? 'اختر سائق أو فان' : 'Choose Driver or Van') : __('order_operations.dispatch.choose_driver')) }}</option>
                    @foreach($drivers as $driver)
                        @if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'] && (!$rowWithDriver || (int)$driver->id!==$rowCurrentDriverId))
                            <option value="driver:{{ $driver->id }}" @selected(!$rowWithDriver && $dispatchInitial===('driver:'.$driver->id))>{{ $isAr ? 'سائق' : 'Driver' }} · {{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
                        @endif
                    @endforeach
                    @if($row['channel']==='b2b' && !$rowWithDriver)
                        @foreach($vans as $van)
                            <option value="van:{{ $van->id }}" @selected($dispatchInitial===('van:'.$van->id))>{{ $isAr ? 'فان' : 'Van' }} · {{ $van->code }}{{ $van->plate_number ? ' · '.$van->plate_number : '' }}</option>
                        @endforeach
                    @endif
                </select>
                <button type="submit" data-order-inline-picker-submit aria-label="{{ $rowWithDriver ? ($isAr ? 'سحب وتحويل الطلب' : 'Withdraw and transfer order') : ($isAr ? 'حفظ التوجيه' : 'Save dispatch') }}" title="{{ $rowWithDriver ? ($isAr ? 'سحب وتحويل الطلب' : 'Withdraw and transfer order') : ($isAr ? 'حفظ التوجيه' : 'Save dispatch') }}" disabled>✓</button>
                <input class="order-inline-picker-reason" name="reason" maxlength="500" data-order-inline-picker-reason hidden placeholder="{{ __('order_operations.dispatch.reason_required') }}">
            </form>
            @if($row['dispatch_locked_until_confirmation'] ?? false)
                <small class="order-dispatch-lock-hint">{{ $isAr ? 'متاح بعد تأكيد الطلب' : 'Available after order confirmation' }}</small>
            @endif
            @if(($row['dispatch_status'] ?? '')==='assigned' && !empty($row['dispatch_assignee_id']) && ($row['can_dispatch'] ?? false))
                <form method="post" action="{{ route('admin.operations.orders.dispatch.clear',$row['id']) }}" class="order-inline-unassign" data-order-inline-unassign>
                    @csrf @method('DELETE')
                    <input type="hidden" name="reason" value="grid_manual_unassign">
                    <button type="submit">{{ $isAr ? (($row['dispatch_assignee_type'] ?? '')==='van' ? 'سحب من الفان' : 'سحب من السائق') : (($row['dispatch_assignee_type'] ?? '')==='van' ? 'Pull from Van' : 'Pull from driver') }}</button>
                </form>
            @endif
        @endif
    </div>
</td>
@endif
<td class="order-owner-cell {{ $rowDelivered ? 'is-complete' : '' }}" @if($rowDelivered) aria-disabled="true" data-order-customer-service-dimmed @endif>
    <div class="order-owner {{ $rowDelivered ? 'is-complete' : '' }}">
        @if($row['customer_service_owner'])
            <strong>{{ $row['customer_service_owner'] }}</strong>
            @if($row['customer_service_assignment_reason'])<small>{{ $businessLabel($row['customer_service_assignment_reason']) }}</small>@endif
        @else
            <span class="order-mini-chip">{{ __('order_operations.unassigned') }}</span>
        @endif
        @if(!$rowDelivered && $canAssignCustomerService && $row['can_manage'])
            <form method="post" action="{{ route('admin.operations.orders.customer-service-owner',$row['id']) }}" class="order-inline-picker" data-order-inline-customer-service-owner>
                @csrf @method('PATCH')
                <select name="assigned_user_id" data-order-inline-picker-select data-initial-value="{{ (string)($row['customer_service_owner_id'] ?? '') }}" aria-label="{{ __('order_operations.customer_service_owner.assign_follow_up') }}">
                    <option value="">{{ __('order_operations.customer_service_owner.remove_owner') }}</option>
                    @foreach($customerServiceAgents as $agent)
                        <option value="{{ $agent->id }}" @selected((int)($row['customer_service_owner_id'] ?? 0)===(int)$agent->id)>{{ $agent->name }}</option>
                    @endforeach
                </select>
                <button type="submit" data-order-inline-picker-submit aria-label="{{ __('order_operations.customer_service_owner.save') }}" title="{{ __('order_operations.customer_service_owner.save') }}" disabled>✓</button>
            </form>
        @endif
    </div>
</td>
<td class="order-grid-actions">
<details class="row-actions" data-order-row-actions>
<summary aria-label="{{ __('order_operations.order_actions') }}">⋮</summary>
<div class="row-action-menu">
@if($row['can_edit'])
<a data-order-edit href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$row['id'],'edit'=>1])) }}">{{ $isAr ? 'تعديل الطلب' : 'Edit order' }}</a>
@endif
@if($row['channel']==='b2c' && $row['driver_id'] && $row['can_manage'])
<form method="post" action="{{ route('admin.operations.orders.remind',$row['id']) }}" data-order-remind-driver>@csrf
<button type="submit">{{ $isAr ? 'تذكير السائق' : 'Remind driver' }}</button>
</form>
@endif

@if(!$rowDelivered && $canAssignCustomerService && $row['can_manage'])
<form method="post" action="{{ route('admin.operations.orders.customer-service-owner',$row['id']) }}" data-order-customer-service-owner>@csrf @method('PATCH')
<label style="display:grid;gap:4px">
<span>{{ __('order_operations.customer_service_owner.assign_follow_up') }}</span>
<select name="assigned_user_id">
<option value="">{{ __('order_operations.customer_service_owner.remove_owner') }}</option>
@foreach($customerServiceAgents as $agent)
<option value="{{ $agent->id }}" @selected((int)($row['customer_service_owner_id'] ?? 0)===(int)$agent->id)>{{ $agent->name }} · {{ $agent->roles->first()?->code==='CUSTOMER_SERVICE_SUPERVISOR' ? __('order_operations.customer_service_owner.supervisor') : __('order_operations.customer_service_owner.agent') }}</option>
@endforeach
</select>
</label>
<input name="reason" maxlength="500" placeholder="{{ __('order_operations.customer_service_owner.reason_placeholder') }}">
<button class="foodex-primary" type="submit">{{ __('order_operations.customer_service_owner.save') }}</button>
</form>
@endif

@if($row['channel']==='b2b' && $row['status']==='pending' && $row['can_manage'])
<div data-b2b-customer-service-approval style="display:grid;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'قرار خدمة العملاء' : 'Customer Service approval' }}</strong>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="confirmed">
<input type="hidden" name="note" value="customer_service_approved">
<button class="foodex-primary" type="submit">{{ $isAr ? 'اعتماد الطلب' : 'Approve order' }}</button>
</form>
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<input type="hidden" name="status" value="cancelled">
<input name="note" maxlength="1000" required placeholder="{{ $isAr ? 'سبب الرفض (إلزامي)' : 'Rejection reason (required)' }}">
<button class="danger btn" type="submit">{{ $isAr ? 'رفض الطلب' : 'Reject order' }}</button>
</form>
</div>
@endif

@if($row['channel']==='b2b' && $row['settlement'])
@php($settlement=$row['settlement'])
<div data-b2b-settlement-review style="display:grid;gap:5px;margin-top:8px;padding:9px;border-top:1px solid var(--foodex-border)">
<strong>{{ $isAr ? 'مراجعة التسوية المالية' : 'Financial settlement review' }}</strong>
<div>{{ $isAr ? 'رصيد العميل المتاح' : 'Customer credit balance' }}: <strong>{{ number_format((float)$settlement['customer_credit_balance'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'إجمالي المديونية الحالية' : 'Aggregate outstanding' }}: <strong>{{ number_format((float)$settlement['aggregate_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'حد الائتمان' : 'Credit limit' }}: <strong>{{ number_format((float)$settlement['credit_limit'],3) }} {{ $settlement['currency'] }}</strong></div>
<div>{{ $isAr ? 'الحد المتاح' : 'Available credit line' }}: <strong>{{ number_format((float)$settlement['available_credit_line'],3) }} {{ $settlement['currency'] }}</strong></div>
@if($settlement['invoice_outstanding']!==null)
<div>{{ $isAr ? 'المتبقي على الفاتورة' : 'Invoice outstanding' }}: <strong>{{ number_format((float)$settlement['invoice_outstanding'],3) }} {{ $settlement['currency'] }}</strong></div>
@endif
</div>
@endif

@if($row['can_dispatch'])
<div data-order-dispatch-workspace>
@if($row['dispatch_status']==='awaiting_dispatch')
<div class="dispatch-warning" data-order-dispatch-status>{{ __('order_operations.dispatch.warning') }}</div>
@endif
@if($rowWithDriver)
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-driver data-order-driver-transfer>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="driver">
<select name="assignee_id" required aria-label="{{ $isAr ? 'سحب وتحويل الطلب لسائق آخر' : 'Withdraw and transfer order to another driver' }}">
<option value="">{{ $isAr ? 'سحب وتحويل الطلب لسائق آخر' : 'Withdraw and transfer order to another driver' }}</option>
@foreach($drivers as $driver)
@if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'] && (int)$driver->id!==$rowCurrentDriverId)
<option value="{{ $driver->id }}">{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
@endif
@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $isAr ? 'سحب وتحويل الطلب لسائق آخر' : 'Withdraw & transfer to another driver' }}</button>
</form>
@elseif($row['channel']==='b2c')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-driver>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="driver">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_driver') }}">
<option value="">{{ __('order_operations.dispatch.choose_driver') }}</option>
@foreach($drivers as $driver)
@if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)===$row['channel'])
<option value="{{ $driver->id }}">{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
@endif
@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.driver') }}</button>
</form>
@elseif($row['channel']==='b2b')
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-driver>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="driver">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_driver') }}">
<option value="">{{ __('order_operations.dispatch.choose_driver') }}</option>
@foreach($drivers as $driver)
@if((int)$driver->store_id===(int)$row['store_id'] && strtolower((string)$driver->driver_type)==='b2b')
<option value="{{ $driver->id }}">{{ $driver->name ?: __('order_operations.filters.unnamed_driver') }}</option>
@endif
@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.driver') }}</button>
</form>
<form method="post" action="{{ route('admin.operations.orders.dispatch',$row['id']) }}" data-order-dispatch-van>@csrf @method('PATCH')
<input type="hidden" name="assignee_type" value="van">
<select name="assignee_id" required aria-label="{{ __('order_operations.dispatch.choose_van') }}">
<option value="">{{ __('order_operations.dispatch.choose_van') }}</option>
@foreach($vans as $van)<option value="{{ $van->id }}">{{ $van->code }}{{ $van->plate_number ? ' · '.$van->plate_number : '' }}</option>@endforeach
</select>
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button class="foodex-primary">{{ $row['dispatch_status']==='assigned' ? __('order_operations.dispatch.reassign') : __('order_operations.dispatch.assign') }} · {{ __('order_operations.dispatch.van') }}</button>
</form>
@endif
@if($row['dispatch_status']==='assigned' && !$rowWithDriver)
<form method="post" action="{{ route('admin.operations.orders.dispatch.clear',$row['id']) }}" data-order-dispatch-clear>@csrf @method('DELETE')
<input name="reason" required maxlength="500" placeholder="{{ __('order_operations.dispatch.reason_required') }}">
<button type="submit">{{ __('order_operations.dispatch.unassign') }}</button>
</form>
@endif
@endif
</div>
@if($row['can_manage'] && !($row['channel']==='b2b' && $row['status']==='pending'))
@if(count($row['available_statuses']))
<form method="post" action="{{ route('admin.operations.orders.transition',$row['id']) }}">@csrf
<select name="status" required aria-label="{{ __('order_operations.next_status') }}"><option value="">{{ $rowWithDriver ? ($isAr ? 'إلغاء الطلب' : 'Cancel order') : __('order_operations.choose_next_status') }}</option>@foreach($row['available_statuses'] as $status)<option value="{{ $status['code'] }}">{{ $status['label'] }}</option>@endforeach</select>
<button class="foodex-primary">{{ __('order_operations.update_status') }}</button>
</form>
@else
<span class="badge foodex-status-badge {{ $row['status'] }}" data-state="{{ strtolower((string) $row['status']) }}">{{ __('order_operations.terminal') }}</span>
@endif
@endif
</div>
</details>
</td>
</tr>
@empty<tr><td colspan="7"><div class="foodex-empty-state">{{ __('order_operations.empty') }}</div></td></tr>@endforelse
</tbody></table>
</section>
{{ $orders->links() }}
</div>

@if($detail)
@php($detailCloseQuery=request()->except(['order','edit']))
<dialog class="order-detail-drawer foodex-record-drawer" open aria-modal="true" aria-label="{{ __('order_operations.view_order') }}">
<div class="order-detail-drawer-shell foodex-record-drawer-shell">
<div class="order-detail-drawer-head foodex-record-drawer-header"><div><strong>{{ __('order_operations.view_order') }}</strong><div>{{ $detail['number'] }}</div></div><a class="order-detail-close foodex-record-drawer-close" href="{{ route('admin.operations.orders.index',$detailCloseQuery) }}" aria-label="{{ __('order_operations.detail.close') }}">×</a></div>
<section class="detail-grid foodex-record-grid" data-order-actionable-detail>
@php($detailHasMapAction = !empty($detail['delivery_address']['has_coordinates']))
@php($detailHasInvoiceAction = !empty($detail['invoice_id']) && !empty($detail['can_view_invoice']))
@php($detailHasDispatchAction = ($detail['dispatch_status'] ?? '')==='assigned' && !empty($detail['dispatch_assignee_id']) && !empty($detail['can_dispatch']))
@php($detailHasEditAction = !empty($detail['can_edit']) && !empty($detail['edit_options']))
@php($detailDriverProofs = collect($detail['delivery_evidence'] ?? [])->flatMap(fn($assignment) => $assignment['timeline'] ?? [])->filter(fn($event) => !empty($event['proof'])))
@php($detailVanProofs = collect($detail['van_delivery_evidence'] ?? [])->flatMap(fn($assignment) => $assignment['timeline'] ?? [])->filter(fn($event) => !empty($event['proof'])))
@php($detailVanExecutionProofs = collect($detail['van_events'] ?? [])->filter(fn($event) => !empty($event['proof_path'])))
@php($detailHasAnyAction = $detailHasMapAction || $detailHasInvoiceAction || $detailHasDispatchAction || $detailHasEditAction || $detailDriverProofs->isNotEmpty() || $detailVanProofs->isNotEmpty() || $detailVanExecutionProofs->isNotEmpty())

@if($detailHasMapAction)
<div class="foodex-card panel" data-order-detail-action-section="map">
<h2>{{ $isAr ? 'عنوان التوصيل' : 'Delivery address' }}</h2>
@php($delivery=$detail['delivery_address'])
<div class="timeline-item">
<strong>{{ $delivery['formatted'] ?: __('order_operations.detail.saved_address') }}</strong>
@if(!empty($delivery['recipient_name']))<div>{{ __('order_operations.detail.recipient') }}: {{ $delivery['recipient_name'] }}</div>@endif
@if(!empty($delivery['delivery_phone']))<div>{{ __('order_operations.detail.phone') }}: {{ $delivery['delivery_phone'] }}</div>@endif
@if(!empty($delivery['landmark']))<div>{{ __('order_operations.detail.landmark') }}: {{ $delivery['landmark'] }}</div>@endif
@if(!empty($delivery['delivery_notes']))<div>{{ __('order_operations.detail.delivery_notes') }}: {{ $delivery['delivery_notes'] }}</div>@endif
<small>{{ number_format((float)$delivery['latitude'],7,'.','') }}, {{ number_format((float)$delivery['longitude'],7,'.','') }}</small>
<div style="margin-top:8px"><a class="foodex-primary" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)$delivery['latitude'].','.(string)$delivery['longitude']) }}">{{ __('order_operations.detail.open_map') }}</a></div>
</div>
</div>
@endif

@if($detailHasInvoiceAction)
<div class="foodex-card panel" data-order-financials data-order-detail-action-section="invoice">
<h2>{{ $isAr ? 'الفاتورة والتحصيل' : 'Invoice & settlement' }}</h2>
<div class="timeline-item">
<strong>{{ $detail['invoice_number'] ?: '#'.$detail['invoice_id'] }}</strong>
@if($detail['invoice_total'] !== null)<div>{{ number_format((float)$detail['invoice_total'],3) }} {{ $detail['invoice_currency'] ?: $detail['currency'] }}</div>@endif
<div style="margin-top:8px"><a class="foodex-primary" href="{{ route('admin.invoices.show',$detail['invoice_id']) }}">{{ $isAr ? 'فتح الفاتورة والتحصيل' : 'Open invoice & settlement' }}</a></div>
</div>
</div>
@endif

@if($detailHasDispatchAction)
<div class="foodex-card panel" data-order-dispatch-detail data-order-detail-action-section="dispatch">
<h2>{{ $isAr ? 'التنفيذ والتوجيه' : 'Execution & routing' }}</h2>
<div class="timeline-item">
<strong>{{ $detail['dispatch_assignee'] }}</strong>
<div>{{ $businessLabel($detail['dispatch_assignee_type']) }}</div>
</div>
<form method="post" action="{{ route('admin.operations.orders.dispatch.clear',$detail['id']) }}" style="margin-top:10px" data-order-detail-unassign>
@csrf @method('DELETE')
<input type="hidden" name="reason" value="detail_manual_unassign">
<button type="submit" class="danger btn">{{ $isAr ? (($detail['dispatch_assignee_type'] ?? '')==='van' ? 'سحب الطلب من الفان' : 'سحب الطلب من السائق') : (($detail['dispatch_assignee_type'] ?? '')==='van' ? 'Pull order from Van' : 'Pull order from driver') }}</button>
</form>
</div>
@endif

@if($detailHasEditAction && !request('edit'))
<div class="foodex-card panel" data-order-detail-action-section="edit">
<h2>{{ $isAr ? 'تعديل الطلب' : 'Edit order' }}</h2>
<a class="foodex-primary" href="{{ route('admin.operations.orders.index',array_merge(request()->query(),['order'=>$detail['id'],'edit'=>1])) }}">{{ $isAr ? 'فتح تعديل الطلب' : 'Edit order' }}</a>
</div>
@endif

@if(request('edit') && $detail['can_edit'] && $detail['edit_options'])
@php($editOptions=$detail['edit_options'])
<div class="foodex-card panel" data-order-edit-panel>
<h2>{{ $isAr ? 'تعديل الطلب' : 'Edit order' }} · {{ $detail['number'] }}</h2>
<p>{{ $isAr ? 'يتم إعادة التسعير والتحقق من المخزون عند الحفظ. التعديل متاح للطلبات المعلقة غير المقفلة فقط.' : 'Saving re-prices and re-validates stock. Editing is limited to unlocked pending orders.' }}</p>
<form method="post" action="{{ route('admin.operations.orders.update',$detail['id']) }}" data-order-edit-form data-lookup-url="{{ route('admin.operations.orders.lookups',['type'=>'__TYPE__'],false) }}" data-edit-channel="{{ $detail['channel'] }}" data-edit-store="{{ $detail['store_id'] }}"> {{-- localization-gate: allow channel/store values are machine-only scoped lookup attributes, not user-visible labels --}}
@csrf @method('PATCH')
<div class="new-order-grid">
<label class="new-order-field"><span>{{ $isAr ? 'العميل' : 'Customer' }}</span>
<input type="search" data-edit-customer-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_customer') }}">
<select name="customer_id" data-edit-customer required>
@if($editOptions['selected_customer'])
<option value="{{ $editOptions['selected_customer']['id'] }}" selected>{{ $editOptions['selected_customer']['label'] }}@if($editOptions['selected_customer']['meta']) · {{ $editOptions['selected_customer']['meta'] }}@endif</option>
@else
<option value="{{ $detail['customer_id'] }}" selected>#{{ $detail['customer_id'] }} · {{ __('order_operations.lookup.selected_unavailable') }}</option>
@endif
</select>
<small data-edit-customer-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-customer-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
@if($detail['channel']==='b2b')
<label class="new-order-field"><span>{{ $isAr ? 'مخزن الصرف' : 'Source warehouse' }}</span><select name="warehouse_id" required>
@foreach($editOptions['warehouses'] as $warehouse)<option value="{{ $warehouse['id'] }}" @selected((int)$detail['warehouse_id']===(int)$warehouse['id'])>{{ $warehouse['code'] }} · {{ $warehouse['name'] }}</option>@endforeach
</select></label>
@endif
<label class="new-order-field"><span>{{ $isAr ? 'العنوان' : 'Address' }}</span><select name="address_id" data-edit-address>
<option value="">{{ $isAr ? 'بدون عنوان / استلام' : 'No address / pickup' }}</option>
@if($editOptions['selected_address'])
<option value="{{ $editOptions['selected_address']['id'] }}" selected>{{ $editOptions['selected_address']['label'] }}@if(($editOptions['selected_address']['active'] ?? true) === false) · {{ __('order_operations.lookup.selected_unavailable') }}@endif</option>
@endif
</select><small data-edit-address-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-address-retry hidden>{{ __('order_operations.lookup.retry') }}</button></label>
<label class="new-order-field"><span>{{ $isAr ? 'طريقة الدفع' : 'Payment method' }}</span><select name="payment_method" required>
@foreach($newOrderWizard['payment_methods'] as $method)<option value="{{ $method['code'] }}" @selected((string)$detail['payment_method']===(string)$method['code'])>{{ $method['label'] }}</option>@endforeach
</select></label>
<label class="new-order-field"><span>{{ $isAr ? 'كود الكوبون' : 'Coupon code' }}</span><input name="coupon_code" maxlength="80" value="{{ $detail['coupon_code'] }}"></label>
</div>
<label class="new-order-field" style="margin-top:14px"><span>{{ $isAr ? 'ملاحظة الطلب' : 'Order note' }}</span><textarea name="customer_note" maxlength="1000" rows="3">{{ $detail['customer_note'] }}</textarea></label>
<h3>{{ $isAr ? 'الأصناف والكميات' : 'Items & quantities' }}</h3>
<div class="new-order-lines" data-edit-order-lines>
@foreach($detail['items'] as $index=>$item)
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span>
<input type="search" data-edit-product-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_product') }}">
<select name="items[{{ $index }}][product_id]" data-edit-product required>
@php($selectedProduct=$editOptions['selected_products'][(int)$item['product_id']] ?? null)
@if($selectedProduct)
<option value="{{ $selectedProduct['id'] }}" selected>{{ $selectedProduct['meta'] }} · {{ $selectedProduct['label'] }}@if(($selectedProduct['active'] ?? true) === false) · {{ __('order_operations.lookup.selected_unavailable') }}@endif</option>
@else
<option value="{{ $item['product_id'] }}" selected>#{{ $item['product_id'] }} · {{ __('order_operations.lookup.selected_unavailable') }}</option>
@endif
</select>
<small data-edit-product-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-product-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" name="items[{{ $index }}][quantity]" value="{{ $item['quantity'] }}" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
@endforeach
</div>
<template data-edit-line-template>
<div class="new-order-line" data-edit-order-line>
<label class="new-order-field"><span>{{ $isAr ? 'المنتج' : 'Product' }}</span>
<input type="search" data-edit-product-search autocomplete="off" placeholder="{{ __('order_operations.lookup.search_product') }}">
<select data-edit-product required disabled><option value="">{{ $isAr ? 'اختر المنتج' : 'Select product' }}</option></select>
<small data-edit-product-status role="status" aria-live="polite">{{ __('order_operations.lookup.results_help') }}</small>
<button type="button" class="btn secondary" data-edit-product-more hidden>{{ __('order_operations.lookup.load_more') }}</button>
</label>
<label class="new-order-field"><span>{{ $isAr ? 'الكمية' : 'Quantity' }}</span><input type="number" min="0.001" step="0.001" value="1" required></label>
<button type="button" class="btn secondary" data-edit-remove-line>{{ $isAr ? 'حذف' : 'Remove' }}</button>
</div>
</template>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
<button type="button" class="btn secondary" data-edit-add-line>+ {{ $isAr ? 'إضافة صنف' : 'Add item' }}</button>
<button type="submit" class="foodex-primary">{{ $isAr ? 'حفظ وإعادة التسعير' : 'Save & re-price' }}</button>
<a class="btn secondary" href="{{ route('admin.operations.orders.index',array_merge(request()->except('edit'),['order'=>$detail['id']])) }}">{{ $isAr ? 'إلغاء التعديل' : 'Cancel edit' }}</a>
</div>
</form>
</div>
@endif


@if($detailDriverProofs->isNotEmpty())
<div class="foodex-card panel" data-delivery-evidence data-order-detail-action-section="driver-proofs">
<h2>{{ $isAr ? 'إثباتات السائق' : 'Driver proofs' }}</h2>
<div class="timeline">
@foreach($detailDriverProofs as $event)
<div class="timeline-item">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
<a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a>
</div>
@endforeach
</div>
</div>
@endif

@if($detailVanProofs->isNotEmpty() || $detailVanExecutionProofs->isNotEmpty())
<div class="foodex-card panel" data-van-delivery-evidence data-order-detail-action-section="van-proofs">
<h2>{{ $isAr ? 'إثباتات الفان' : 'Van proofs' }}</h2>
<div class="timeline">
@foreach($detailVanProofs as $event)
<div class="timeline-item">
<strong>{{ $businessLabel($event['from_status']) }} → {{ $businessLabel($event['to_status']) }}</strong>
<a class="foodex-primary" href="{{ $event['proof']['url'] }}" target="_blank" rel="noopener" data-van-delivery-proof-link>{{ __('order_operations.detail.view_proof') }}</a>
</div>
@endforeach
@foreach($detailVanExecutionProofs as $event)
<div class="timeline-item">
<strong>{{ $businessLabel($event['action']) }}</strong>
<a class="foodex-primary" href="{{ asset('storage/'.ltrim((string)$event['proof_path'],'/')) }}" target="_blank" rel="noopener" data-van-proof-link>{{ __('order_operations.detail.van_proof') }}</a>
</div>
@endforeach
</div>
</div>
@endif

@if(!$detailHasAnyAction)
<div class="foodex-empty-state" data-order-detail-no-actions>{{ $isAr ? 'لا توجد إجراءات متاحة على هذا الطلب حالياً.' : 'No actions are currently available for this order.' }}</div>
@endif
</section>
</div>
</dialog>
@endif
<dialog class="order-popup-dialog" data-order-popup-dialog aria-modal="true">
    <div class="order-popup-shell">
        <div class="order-popup-head" data-no-print>
            <strong data-order-popup-title>{{ $isAr ? 'تفاصيل الطلب' : 'Order details' }}</strong>
            <button type="button" class="order-popup-close" data-order-popup-close aria-label="{{ $isAr ? 'إغلاق' : 'Close' }}">×</button>
        </div>
        <div class="order-popup-body" data-order-popup-body><div class="order-popup-loading">{{ $isAr ? 'جاري التحميل…' : 'Loading…' }}</div></div>
    </div>
</dialog>
</main>
<aside class="sidebar">@include('admin._sidebar')</aside>
</div>
<script>
(() => {
    const tabs = document.querySelector('[data-order-tabs]');
    if (!tabs) return;

    const buttons = [...tabs.querySelectorAll('[data-order-tab]')];
    const panels = [...document.querySelectorAll('[data-order-panel]')];
    if (!buttons.length || !panels.length) return;

    const available = new Set(buttons.map((button) => button.dataset.orderTab));
    const defaultTab = available.has(tabs.dataset.defaultTab) ? tabs.dataset.defaultTab : buttons[0].dataset.orderTab;
    const hashTab = window.location.hash.startsWith('#order-tab-')
        ? window.location.hash.slice('#order-tab-'.length)
        : null;

    const activate = (name, updateHistory = true) => {
        if (!available.has(name)) name = defaultTab;

        buttons.forEach((button) => {
            const active = button.dataset.orderTab === name;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.orderPanel !== name;
        });
        document.querySelectorAll('[data-order-filter-mode]').forEach((filter) => {
            filter.hidden = filter.dataset.orderFilterMode !== name;
        });

        const analyticsPanel = panels.find((panel) => panel.dataset.orderPanel === 'analytics');
        if (name === 'analytics' && analyticsPanel) {
            analyticsPanel.classList.remove('charts-animate');
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                void analyticsPanel.offsetWidth;
                analyticsPanel.classList.add('charts-animate');
            }
        }

        if (updateHistory) {
            history.replaceState(null, '', '#order-tab-' + name);
        }
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button.dataset.orderTab));
    });

    activate(hashTab && available.has(hashTab) ? hashTab : defaultTab, false);
})();

(() => {
    const tabs = document.querySelector('[data-order-analytics-tabs]');
    const panel = document.querySelector('[data-order-panel="analytics"]');
    if (!tabs || !panel) return;

    const buttons = [...tabs.querySelectorAll('[data-analytics-tab]')];
    const cards = [...panel.querySelectorAll('[data-analytics-group]')];
    if (!buttons.length || !cards.length) return;

    const available = new Set(buttons.map((button) => button.dataset.analyticsTab));
    const defaultTab = available.has(tabs.dataset.defaultAnalyticsTab)
        ? tabs.dataset.defaultAnalyticsTab
        : buttons[0].dataset.analyticsTab;

    const animateVisible = () => {
        panel.classList.remove('charts-animate');
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        void panel.offsetWidth;
        panel.classList.add('charts-animate');
    };

    const activate = (name) => {
        if (!available.has(name)) name = defaultTab;

        buttons.forEach((button) => {
            const active = button.dataset.analyticsTab === name;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });

        cards.forEach((card) => {
            card.hidden = card.dataset.analyticsGroup !== name;
        });

        animateVisible();
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button.dataset.analyticsTab));
    });

    activate(defaultTab);
})();

(() => {
    const values = [...document.querySelectorAll('[data-order-counter]')];
    if (!values.length) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const formatLikeTarget = (element, value) => {
        const target = element.dataset.counterTarget || String(value);
        const decimals = Number.parseInt(element.dataset.counterDecimals || '0', 10);
        const formatted = new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(value);
        return target.replace(/[-+]?\d[\d,]*(?:\.\d+)?/, formatted);
    };

    const fit = (element) => {
        element.style.fontSize = '';
        let size = Number.parseFloat(getComputedStyle(element).fontSize);
        const minimum = 11;
        let guard = 0;
        while (element.scrollWidth > element.clientWidth && size > minimum && guard < 24) {
            size -= .5;
            element.style.fontSize = size + 'px';
            guard += 1;
        }
    };

    const fitAll = () => values.forEach(fit);
    const duration = 1200;

    values.forEach((element) => {
        const raw = Number.parseFloat(element.dataset.counterRaw ?? '');
        if (!Number.isFinite(raw) || reducedMotion) {
            fit(element);
            return;
        }

        const started = performance.now();
        const tick = (now) => {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = formatLikeTarget(element, raw * eased);
            fit(element);
            if (progress < 1) {
                window.requestAnimationFrame(tick);
            } else {
                element.textContent = element.dataset.counterTarget || element.textContent;
                fit(element);
            }
        };

        element.textContent = formatLikeTarget(element, 0);
        window.requestAnimationFrame(tick);
    });

    let resizeFrame = 0;
    window.addEventListener('resize', () => {
        window.cancelAnimationFrame(resizeFrame);
        resizeFrame = window.requestAnimationFrame(fitAll);
    }, {passive:true});
})();
</script>
<script>
document.querySelectorAll('[data-order-inline-status]').forEach(function(form){
    var select=form.querySelector('[data-order-inline-status-select]');
    var note=form.querySelector('[data-order-inline-rejection-note]');
    if(!select||!note){return;}
    var sync=function(){
        var rejecting=select.value==='cancelled';
        note.hidden=!rejecting;
        note.required=rejecting;
        if(!rejecting){note.value='';}
    };
    select.addEventListener('change',sync);
    sync();
});

document.querySelectorAll('.order-inline-picker').forEach(function(form){
    var select=form.querySelector('[data-order-inline-picker-select]');
    var submit=form.querySelector('[data-order-inline-picker-submit]');
    var reason=form.querySelector('[data-order-inline-picker-reason]');
    if(!select||!submit){return;}
    var initial=String(select.dataset.initialValue||'');
    var sync=function(){
        var value=String(select.value||'');
        var changed=value!==initial;
        if(form.hasAttribute('data-order-inline-dispatch')){
            var typeInput=form.querySelector('[data-order-inline-assignee-type]');
            var idInput=form.querySelector('[data-order-inline-assignee-id]');
            var parts=value.split(':');
            if(typeInput){typeInput.value=parts.length===2?parts[0]:'';}
            if(idInput){idInput.value=parts.length===2?parts[1]:'';}
            changed=changed&&parts.length===2&&parts[0]!==''&&parts[1]!=='';
        }
        submit.disabled=!changed;
        if(reason){
            reason.hidden=!changed;
            reason.required=changed;
            if(!changed){reason.value='';}
        }
    };
    select.addEventListener('change',sync);
    sync();
});
</script>
<script>
(() => {
    const dialog = document.querySelector('[data-order-popup-dialog]');
    const body = dialog?.querySelector('[data-order-popup-body]');
    const title = dialog?.querySelector('[data-order-popup-title]');
    const close = dialog?.querySelector('[data-order-popup-close]');
    if (!(dialog instanceof HTMLDialogElement) || !(body instanceof HTMLElement)) return;

    let controller = null;
    const loading = @json(__('order_operations.popup.loading'));
    const failed = @json(__('order_operations.popup.failed'));

    const dismiss = () => {
        controller?.abort();
        controller = null;
        dialog.close();
    };

    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-order-popup]');
        if (trigger) {
            event.preventDefault();
            controller?.abort();
            controller = new AbortController();
            if (title) title.textContent = trigger.dataset.orderPopupTitle || '';
            body.innerHTML = '<div class="order-popup-loading">' + loading + '</div>';
            if (!dialog.open) dialog.showModal();

            try {
                const response = await fetch(trigger.dataset.orderPopupUrl, {
                    headers: {'Accept':'text/html','X-Requested-With':'XMLHttpRequest'},
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('popup-http-' + response.status);
                body.innerHTML = await response.text();
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    body.innerHTML = '<div class="foodex-empty-state">' + failed + '</div>';
                }
            }
            return;
        }

        if (event.target.closest('[data-order-popup-close]')) {
            dismiss();
            return;
        }

        if (event.target.closest('[data-order-popup-print]')) {
            const locale = document.documentElement.lang || 'en';
            const value = new Intl.DateTimeFormat(locale, {
                dateStyle: 'full',
                timeStyle: 'medium',
                timeZone: 'Asia/Kuwait',
            }).format(new Date());
            dialog.querySelectorAll('[data-order-print-time]').forEach((node) => {
                node.textContent = value;
            });
            window.print();
        }
    });

    close?.addEventListener('click', dismiss);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dismiss();
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        dismiss();
    });
})();
</script>
</body></html>
@if($detail)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const drawer = document.querySelector('.order-detail-drawer');
    const closeLink = drawer?.querySelector('.order-detail-close');
    if (!(drawer instanceof HTMLElement) || !window.FoodexModalA11y) return;

    let modalSession = null;
    const leaveDrawer = () => {
        modalSession?.deactivate({ restoreFocus: false });
        modalSession = null;
    };
    const dismissDrawer = () => {
        leaveDrawer();
        if (closeLink instanceof HTMLAnchorElement) window.location.assign(closeLink.href);
    };

    modalSession = window.FoodexModalA11y.activate({
        root: drawer,
        dialog: drawer,
        initialFocus: closeLink instanceof HTMLElement ? closeLink : drawer,
        onEscape: dismissDrawer,
    });
    closeLink?.addEventListener('click', leaveDrawer, { once: true });
    window.addEventListener('pagehide', leaveDrawer, { once: true });
});
</script>
@endif
@if($detail && request('edit') && $detail['can_edit'])
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.querySelector('[data-order-edit-form]');
 const box=document.querySelector('[data-edit-order-lines]');
 const tpl=document.querySelector('[data-edit-line-template]');
 const add=document.querySelector('[data-edit-add-line]');
 if(!form||!box||!tpl||!add)return;
 const channel=form.dataset.editChannel;
 const storeId=form.dataset.editStore;
 const labels={
   chooseProduct:@json($isAr ? 'اختر المنتج' : 'Select product'),
   pickup:@json($isAr ? 'بدون عنوان / استلام' : 'No address / pickup'),
   searching:@json(__('order_operations.lookup.searching')),
   failed:@json(__('order_operations.lookup.failed')),
   empty:@json(__('order_operations.lookup.no_results')),
   help:@json(__('order_operations.lookup.results_help')),
   unavailable:@json(__('order_operations.lookup.selected_unavailable')),
 };
 const endpoint=(type)=>String(form.dataset.lookupUrl||'').replace('__TYPE__',encodeURIComponent(type));
 const fetchLookup=async(type,params,signal)=>{
   const url=new URL(endpoint(type),window.location.origin);
   Object.entries({channel,store_id:storeId,...params}).forEach(([key,value])=>{
     if(value!==''&&value!==null&&value!==undefined)url.searchParams.set(key,String(value));
   });
   const response=await fetch(url,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},signal});
   const payload=await response.json().catch(()=>({}));
   if(!response.ok)throw new Error(payload?.message||labels.failed);
   return payload.data||{items:[],page:1,has_more:false};
 };
 const itemText=(item)=>{
   const base=[item.meta,item.label].filter(Boolean).join(' · ');
   return item.active===false?base+' · '+labels.unavailable:base;
 };
 const applyOptions=(select,items,{append=false,selectedId=null,placeholder=labels.chooseProduct}={})=>{
   const keep=selectedId?String(selectedId):select.value;
   const previous=keep?[...select.options].find(o=>o.value===keep)?.cloneNode(true):null;
   if(!append){select.innerHTML='';select.append(new Option(placeholder,''));}
   const existing=new Set([...select.options].map(o=>o.value));
   (items||[]).forEach(item=>{
     const value=String(item.id); if(existing.has(value))return;
     select.append(new Option(itemText(item),value));existing.add(value);
   });
   if(keep&&!existing.has(keep)&&previous)select.append(previous);
   if(keep&&[...select.options].some(o=>o.value===keep))select.value=keep;
 };
 const bindProduct=(row,selectedId=null)=>{
   const search=row.querySelector('[data-edit-product-search]');
   const select=row.querySelector('[data-edit-product]');
   const status=row.querySelector('[data-edit-product-status]');
   const more=row.querySelector('[data-edit-product-more]');
   if(!search||!select||!status||!more)return;
   let timer=null,page=1,query='',controller=null,sequence=0;
   const load=async(nextQuery=query,nextPage=1,keepId=null)=>{
     controller?.abort();controller=new AbortController();const mine=++sequence;
     status.textContent=labels.searching;more.dataset.retry='0';more.textContent=@json(__('order_operations.lookup.load_more'));select.disabled=true;
     try{
       const result=await fetchLookup('product',{q:nextQuery,page:nextPage,selected_id:keepId},controller.signal);
       if(mine!==sequence)return;
       query=nextQuery;page=nextPage;
       applyOptions(select,result.items,{append:nextPage>1,selectedId:keepId});
       select.disabled=false;status.textContent=result.items.length?labels.help:labels.empty;more.dataset.retry='0';more.textContent=@json(__('order_operations.lookup.load_more'));more.hidden=!result.has_more;
       if(keepId)select.value=String(keepId);
     }catch(error){
       if(error.name==='AbortError')return;
       select.disabled=false;status.textContent=labels.failed;more.dataset.retry='1';more.textContent=@json(__('order_operations.lookup.retry'));more.hidden=false;
     }
   };
   search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>load(search.value.trim(),1),250);});
   more.addEventListener('click',()=>load(query,more.dataset.retry==='1'?page:page+1));
   if(select.options.length<=1||selectedId!==null)load('',1,selectedId);
 };
 const reindex=()=>box.querySelectorAll('[data-edit-order-line]').forEach((row,index)=>{
   const select=row.querySelector('select[data-edit-product]');
   const qty=row.querySelector('input[type="number"]');
   if(select)select.name='items['+index+'][product_id]';
   if(qty)qty.name='items['+index+'][quantity]';
 });
 const bind=(row)=>{
   bindProduct(row,row.querySelector('[data-edit-product]')?.value||null);
   row.querySelector('[data-edit-remove-line]')?.addEventListener('click',()=>{
     if(box.querySelectorAll('[data-edit-order-line]').length<=1)return;
     row.remove();reindex();
   });
 };
 box.querySelectorAll('[data-edit-order-line]').forEach(bind);
 add.addEventListener('click',()=>{
   const fragment=tpl.content.cloneNode(true);const row=fragment.querySelector('[data-edit-order-line]');
   box.appendChild(fragment);bind(row);reindex();
 });
 const customerSearch=form.querySelector('[data-edit-customer-search]');
 const customer=form.querySelector('[data-edit-customer]');
 const customerStatus=form.querySelector('[data-edit-customer-status]');
 const customerMore=form.querySelector('[data-edit-customer-more]');
 const address=form.querySelector('[data-edit-address]');
 const addressStatus=form.querySelector('[data-edit-address-status]');
 const addressRetry=form.querySelector('[data-edit-address-retry]');
 let customerTimer=null,customerPage=1,customerQuery='',customerController=null,customerSequence=0,addressController=null;
 const loadCustomers=async(query=customerQuery,page=1)=>{
   customerController?.abort();customerController=new AbortController();const mine=++customerSequence;
   customerStatus.textContent=labels.searching;customerMore.dataset.retry='0';customerMore.textContent=@json(__('order_operations.lookup.load_more'));customer.disabled=true;
   try{
     const result=await fetchLookup('customer',{q,page,selected_id:customer.value||null},customerController.signal);
     if(mine!==customerSequence)return;
     customerQuery=query;customerPage=page;applyOptions(customer,result.items,{append:page>1,selectedId:customer.value||null,placeholder:''});
     customer.disabled=false;customerStatus.textContent=result.items.length?labels.help:labels.empty;customerMore.dataset.retry='0';customerMore.textContent=@json(__('order_operations.lookup.load_more'));customerMore.hidden=!result.has_more;
   }catch(error){
     if(error.name==='AbortError')return;
     customer.disabled=false;customerStatus.textContent=labels.failed;customerMore.dataset.retry='1';customerMore.textContent=@json(__('order_operations.lookup.retry'));customerMore.hidden=false;
   }
 };
 const loadAddresses=async()=>{
   addressController?.abort();addressController=new AbortController();const customerId=customer.value;
   address.innerHTML='';address.append(new Option(labels.pickup,''));
   if(!customerId){address.disabled=true;return;}
   address.disabled=true;addressRetry.hidden=true;addressStatus.textContent=labels.searching;
   try{
     const result=await fetchLookup('address',{customer_id:customerId},addressController.signal);
     applyOptions(address,result.items,{placeholder:labels.pickup});
     address.disabled=false;addressRetry.hidden=true;addressStatus.textContent=result.items.length?labels.help:labels.empty;
   }catch(error){
     if(error.name==='AbortError')return;
     address.disabled=false;addressStatus.textContent=labels.failed;addressRetry.hidden=false;
   }
 };
 customerSearch?.addEventListener('input',()=>{clearTimeout(customerTimer);customerTimer=setTimeout(()=>loadCustomers(customerSearch.value.trim(),1),250);});
 customerMore?.addEventListener('click',()=>loadCustomers(customerQuery,customerMore.dataset.retry==='1'?customerPage:customerPage+1));
 addressRetry?.addEventListener('click',loadAddresses);
 customer?.addEventListener('change',loadAddresses);
 reindex();
});
</script>
@endif
