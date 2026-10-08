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
