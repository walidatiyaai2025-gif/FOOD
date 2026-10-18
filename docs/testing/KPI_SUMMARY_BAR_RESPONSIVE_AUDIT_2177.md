# KPI Summary Bar Responsive Audit — Issue #2177

## Scope

This audit standardizes the top-level KPI / metrics summary strips used across the Admin platform without changing KPI calculations, permissions, links, filters, or data sources.

## Inventory audited

The shared contract now covers the canonical `.foodex-module-kpis` surface plus the current legacy KPI grid variants found in Admin views:

- ERP Center (`.erp-kpis`)
- WhatsApp report (`.wa-report-kpis`)
- Smart Ordering growth (`.rg-kpis`)
- Security / governance (`.security-kpis`)
- B2B workspace (`.b2b-ref-kpis`)
- Smart Ordering analytics (`.so-a-kpis`)
- Restaurant integrations (`.ri-kpis`)
- Retail control center (`.rc-kpis`)
- Field operations (`.fieldops-kpis`)
- Reports and B2C workspace (`.kpis`)
- Customer 360 (`.c360-kpis`)
- Customer service workspace (`.cs-kpis`)
- Smart Ordering supply (`.si-kpis`)
- WhatsApp campaigns (`.wa-kpis`)
- Store/channel operations (`.store-ops-kpis`)
- Merchant intelligence (`.merchant-summary`)
- WhatsApp supervisor (`.wa-kpi-grid`)
- All module dashboards using `admin._module-dashboard-kpi`, including order operations, finance, treasury, system health, WMS, procurement, catalog completion, enterprise BI, Smart Ordering overview, and WhatsApp operations.

## Before

The platform mixed several fixed grid strategies such as `repeat(4,...)`, `repeat(5,...)`, `repeat(6,...)`, role-specific column variables, and page-specific mobile overrides. This produced inconsistent wrapping and made long Arabic labels, large money values, badges, and changing KPI counts prone to crowding or overlap.

Representative legacy behavior:

| Surface | Previous layout behavior |
| --- | --- |
| Canonical module dashboard | 4 columns desktop → 2 tablet → 1 mobile |
| ERP center | fixed 5 columns |
| WhatsApp report | fixed 6 columns |
| Retail control center | fixed 5 columns |
| B2B workspace | page-specific column variable |
| Customer service / Customer 360 / B2C | shared card-column variable |
| Field operations / Smart Ordering | independent mobile rules |

## After

All audited KPI grids inherit one responsive layout contract:

```css
repeat(auto-fit, minmax(min(100%, var(--foodex-kpi-summary-min)), 1fr))
```

The contract uses a minimum card width that intentionally changes by viewport:

| Viewport | Minimum KPI width | Expected behavior |
| --- | ---: | --- |
| 1440+ / wide desktop | 200 px | Dense equal-width row; wraps only when KPI count requires it |
| 1280 / desktop | 200 px | Stable multi-column row without collision |
| 1024 / laptop | 180 px | Reflows based on actual content width instead of a hard column count |
| 768 / tablet | 180 px | 3–4 cards per row when space permits; no forced 1-column stack |
| 375 / common mobile | 160 px | Usually 2 cards per row where space permits |
| 320 / narrow mobile | 100% below 340 px | Single readable column rather than clipped cards |

## Content safety

The shared contract also enforces:

- `min-width: 0` and `max-width: 100%` on grid children and KPI cards.
- `overflow-wrap: anywhere` for long translated labels and large values.
- normal wrapping for labels, values, comparison text, timestamps, and status badges.
- no hidden overflow on KPI cards.
- logical `text-align: start` behavior and explicit RTL/LTR direction inheritance.
- stretched card height within each grid row for consistent visual rhythm.
- no horizontal page overflow caused by KPI minimum widths.

## RTL / LTR

Both `[dir="rtl"]` and `[dir="ltr"]` are part of the shared selector contract. Arabic labels and English labels use the same responsive grid mechanics and logical alignment.

## Regression coverage

`ModuleDashboardContractTest::test_kpi_summary_bar_platform_wide_responsive_contract` locks:

- desktop/tablet/mobile minimum widths,
- auto-fit/minmax behavior,
- wrapping rules,
- RTL/LTR selectors,
- every currently audited KPI grid class.

## Business behavior preserved

This change is presentation-only. KPI values, calculations, URLs, drill-down behavior, permissions, filters, status semantics, and data sources are unchanged.
