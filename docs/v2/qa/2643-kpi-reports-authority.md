# V2 P313 — KPI / performance indicators navigation and authorization (#2643)

## Source and placement

- Source: FOODEX unified RBAC/ERP/Sidebar document, Sidebar P313 (legacy matrix key P313, Reports & BI).
- Parent: #2503. Merge target: `Foodex_V2` **only**.
- Domain: Reports & BI. Entity: server-calculated KPI summary; user goal: view current order/revenue indicators under the authorized channel and store.
- Decision: **Reuse** the real `admin.reports.index` Reports Center and its existing per-report KPI card row. Do not add a duplicative screen, an unowned top-level menu item or a KPI endpoint with different authorization.
- Navigation ownership: existing Analytics/Reports business group. Wholesale global reporting actors discover `reports_center` (route `admin.reports.index`); Retail Store Admin discovers its scoped `b2c_reports` workspace (route `admin.b2c.module`, module `reports`) and follows the visible Reports Center action with an explicit store/channel context. Back path: Reports Center → channel workspace or the existing sidebar group.
- Existing report families and KPIs come from `ManagementReportService::catalog()/run()`; `ReportController::index` renders `admin.reports`, which shows localized `$data['kpis']`. Reuse, not cosmetic KPI stubs.

## Route / permission / scope matrix

| Persona | Entry / real destination | Permission and scope | Result |
| --- | --- | --- | --- |
| B2B Admin | `reports_center` → `admin.reports.index?report=orders&channel=b2b` | Global `reports.view`, active Wholesale context | 200, live KPI values |
| B2C Store Admin | `b2c_reports` → scoped B2C workspace → Reports Center with `store_id` | Store `reports.view` assigned to that Retail store | 200, that store's KPI values |
| Same Retail actor, other store | Reports Center with foreign `store_id` | No store assignment or role grant | 403 |
| Same Retail actor, Wholesale channel | Reports Center with `channel=b2b` | Cross-channel access not permitted | 403 |
| Same Wholesale actor, Retail channel | Reports Center with `channel=b2c` | B2B-only effective role scope | 403 |
| No Dashboard/report role | Direct Reports Center URL | No `reports.view` | 403 |

The report is read-only; export remains a separate `reports.export` permission and is not granted by the KPI surface. `OperationalTenantScope` determines scope server-side; user-provided store and channel values are never grants. No finance-maker/checker changes or non-tenant-based override.

## Real test coverage

`backend/tests/Feature/V2KpiReportsAuthorityContractTest.php` verifies that a synthetic Retail actor can see exactly its own order total (one order/EGP 25) even while another store has an order/EGP 80, that the Reports Center is discoverable by the correct authorized entry, and that cross-store and cross-channel HTML requests return 403. It checks the actual rendered KPI cards and Arabic RTL / English LTR, plus a Wholesale authorized and roleless negative actor.

This does not authorize any extra BI module. The core Reports Center is available through the existing `reports.view` contract and requires no additional Enterprise BI entitlement or speculative module-enable flag; the separate `erp_bi` entry retains its own readiness/entitlement guard.

## UI / user acceptance

- **Reuse:** canonical Reports Center page, existing KPIs summary cards, tenant filter, AR/EN translation and responsive style. No second page/sidebar, no raw IDs required as a new UI input.
- **Desktop/tablet/mobile web:** existing responsive `.kpis` CSS grid and filter breakpoints are unchanged; route and rendering regression cover locale and data. No claim of manual device/browser UAT, which remains deferred under #2503.
- **A11y/keyboard:** existing anchors, form labels, KPI text cards and focus semantics are untouched; new tests cannot substitute for the final program-wide live UI audit #2750.
- **Data:** empty states and KPIs are produced by authoritative report service; no static mocked KPI values in Dashboard code.
- **Security:** deny cross-channel and foreign stores at HTTP boundary, not just by hiding Sidebar entries.

## Cross-application impact

- **Backend/Dashboard:** only dedicated regression and source-mapping documentation added; no live API endpoint or Dashboard view modified. Existing data scope and permission remain authoritative.
- **Customer Flutter:** no customer API, data schema, UI, checkout, pricing or push change.
- **Driver Flutter:** no driver API, assignment, GPS, proof-of-delivery or operational state change; B2C remains Driver-only.
- **Van Flutter:** no Van API, field trip, stock, assignment or fulfillment change; B2B remains Van-only.
- No mobile child Issue is warranted because there is no observable app contract change.

## Completion / CI

Before merging, require all exact-head Repository Policy, V2 Isolation, Laravel full suite, Security/Pint/PHPStan, Localization AR/EN, MySQL/Redis and terminal Required CI jobs to pass, and verify the actual P313 source-scope assertions. Manual UAT is `DEFERRED_TO_V2_FINAL`; do not mistake CI for production validation. No `main`, tags, releases or production changes.
