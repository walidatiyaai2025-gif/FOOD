# FOODEX Visualization Contract

Status: **Authoritative shared analytics UI contract**  
Mission: #1112 · Implementation unit: #1118

## 1. Purpose

FOODEX uses one semantic visualization language across Dashboard Web, Customer, Driver and Van. Charts exist only when they improve a decision: trend, comparison, composition, distribution, relationship, range or forecast. A KPI remains a KPI when a chart adds no decision value.

## 2. Canonical primitives

| Business question | Web primitive | Flutter primitive |
|---|---|---|
| How is a value changing? | `.foodex-viz-line` / `.foodex-viz-area` | `FoodexTrendChart` |
| Compact trend beside KPI | `.foodex-viz-sparkline` | `FoodexSparkline` |
| Compare categories / stacked contribution | `.foodex-viz-bars` | `FoodexBarChart` |
| Composition | `.foodex-viz-donut` | `FoodexDonutChart` |
| Days-cover / threshold / range | `.foodex-viz-progress` | `FoodexProgressRange` |
| Density / time×category matrix | `.foodex-viz-heatmap` | `FoodexHeatmap` |
| Human-readable series identity | `.foodex-viz-legend` | `FoodexChartLegend` |

Flutter source: `packages/foodex_visualization`.  
Dashboard source: `backend/public/assets/admin/foodex-visualization.css`, loaded by the canonical admin brand shell.

## 3. Semantic palette

Use the existing FOODEX palette only:

- primary/healthy: FOODEX green;
- information/comparison: FOODEX blue;
- warning/reorder-soon: FOODEX orange;
- danger/critical: FOODEX red;
- neutral/unknown: FOODEX muted/border.

Do not introduce rainbow categorical palettes. Do not encode business state using color alone.

## 4. Accessibility contract

Every chart must have an adjacent text title and business-readable summary. Flutter chart primitives require a non-empty `semanticLabel`. Web chart markup must include an accessible name through `aria-label`, `aria-labelledby`, or visible text associated with the chart region. Legends must include text labels, not swatches alone.

Interactive drill-down controls must be real links/buttons and must open the exact filtered business context represented by the chart.

## 5. RTL/LTR

- Container, header and legend follow document direction.
- Flutter trend/bar primitives mirror logical x-position in RTL by default.
- Callers may disable mirroring only when the x-axis has a domain convention that must remain fixed.
- Numeric values remain locale-formatted by the owning surface; chart primitives do not hard-code locale strings.

## 6. Data and truthfulness

A chart never computes authoritative finance, stock, pricing, credit, routing or replenishment facts. It renders values supplied by the owning deterministic service.

Every dynamic chart must explicitly support:
- loading;
- empty / insufficient-data;
- current/live;
- stale/cached, when applicable;
- error/offline, when applicable.

Do not render fabricated zeroes when the source state is unknown.

## 7. Density and responsive behavior

Charts are data-dense operational components, not decorative hero panels. Default Dashboard chart canvas is compact and responsive; mobile primitives must remain usable on narrow phones without horizontal overflow.

Use sparklines for compact KPI trend context. Use full trend/bar/donut/heatmap components only when the user must compare or diagnose.

## 8. Acceptance for downstream W6/W8

W6 and W8 must reuse these primitives instead of adding new chart libraries or per-screen chart palettes. Any new visualization family requires an explicit extension to this contract and shared primitives first.

## 9. Shared Module Overview contract

Issue **#1968** extends this visualization system into the canonical Module Overview contract used by existing FOODEX modules. This is an **Extend / Compose** decision: modules reuse the current Dashboard shell, page header, tabs, filters, drawers, status vocabulary and visualization primitives. It does not create a second design system, a new navigation group, or a new source-of-truth ledger.

Canonical Dashboard primitives:

- `.foodex-module-overview` — module dashboard composition root;
- `.foodex-module-toolbar` + `.foodex-module-scope` + `.foodex-module-date-range` — common filter composition;
- `.foodex-module-kpis` + `.foodex-module-kpi` — KPI strip;
- `.foodex-module-chart` + existing `.foodex-viz-*` — analytical panels;
- `.foodex-module-chart-summary` and `.foodex-module-chart-table` — text/table fallback;
- `.foodex-module-state` — loading/empty/error/stale/permission/disabled/current state;
- `.foodex-module-export` — approved export handoff action.

Reusable Blade includes are:

- `admin._module-dashboard-kpi`;
- `admin._module-dashboard-filters`;
- `admin._module-dashboard-state`.

## 10. KPI data contract

A KPI is presentation over a server-authoritative value. Its shared payload is:

- `label` — localized business label;
- `value` — already formatted display value; finance/currency precision stays with the authoritative formatter/service;
- optional `comparison.value`, `comparison.label`, `comparison.trend` (`up|down|neutral`);
- optional localized `status.label` and semantic `status.state`;
- optional `last_refresh`;
- optional authorized `drilldown_url`.

When a KPI represents records or an actionable exception, the owning module **must** supply an authorized drill-down into the canonical filtered list or exact Record 360. Omitting the URL is valid only for a genuinely non-navigable aggregate or when the actor is not authorized; a disabled/not-entitled KPI must not masquerade as a clickable zero.

## 11. Scope and date-range authority

Scope/date controls are query composition, not authorization. The server remains authoritative for tenant/company/store/warehouse/legal-entity scope and date validation.

- Callers pass only scope choices the actor may select.
- Submitted scope/date values are revalidated server-side.
- Invalid, cross-tenant or unauthorized scope values fail closed.
- Date comparisons use explicit owning-service period semantics.
- Previous-period comparisons never invent data client-side.
- Changing scope/date preserves only compatible authorized context.

The shared filter partial is GET-only and intentionally does not provide business mutation behavior.

## 12. Chart and drill-down contract

Every module chart:

1. uses an existing `.foodex-viz-*` primitive;
2. renders authoritative values from the owning service/read model;
3. has a visible localized title and business summary;
4. exposes an accessible name;
5. includes a human-readable text or table summary where the visual alone is insufficient;
6. uses real links/buttons for drill-down to the exact canonical filtered records;
7. preserves the active authorized scope and period in that drill-down when applicable.

No chart may introduce a duplicated business ledger, fabricate zeroes for unavailable data, or hide an error as an empty result.

## 13. Shared dashboard states

Dynamic module dashboards explicitly distinguish:

- `loading`;
- `empty`;
- `current`;
- `stale`;
- `error`;
- `permission`;
- `disabled`.

A stale/cached value must identify itself as stale. Permission/disabled states must not expose unauthorized totals. An unavailable module is not represented as a valid zero.

## 14. Export contract

The shared export action is only a handoff to the repository-approved reporting/export authority. Small exports may complete synchronously where the existing infrastructure permits it; a large export must use the shared queued/chunked export path and revalidate requester scope at execution time.

The dashboard itself must not create a second export job ledger or bypass report/privacy/redaction rules.

## 15. Responsive, RTL/LTR and accessibility acceptance

Module Overview acceptance is **320 / 360 / 390 / 412 / 430 / 768 / 1024 / 1280+** in Arabic RTL and English LTR.

- No page-level horizontal overflow.
- KPI grids collapse through the shared responsive primitives.
- Date range remains one compact logical control group on narrow screens.
- Primary controls keep the Dashboard 44 px interaction target and mobile text inputs remain readable.
- Charts keep text/legend meaning and provide a text or table summary.
- Interactive KPI/chart drill-down uses keyboard-focusable native links/buttons.
- State is never communicated by color alone.
- Reduced-motion behavior remains inherited from the shared visualization layer.

## 16. Child-module adoption rule

Child module Issues under #1964 must **reuse this contract**. A child may make a backward-compatible extension here only when the shared primitive cannot express a real requirement. Domain-specific services, permissions, KPIs, formulas, reports and routes stay in the owning module.

A child is not accepted merely because it displays KPI cards: scope, drill-down, states, authoritative values, export behavior, AR/EN and responsive runtime must all conform to this contract.

