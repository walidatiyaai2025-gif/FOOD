# V2 P204 — Supervisory reports, canonical route and authority

Issue: #2534 | Parent: #2503 | Source: V2 Sidebar matrix P204 (actual DOCX paragraph P193, Customer & Sales) | Isolated target: `Foodex_V2` only.

## Placement decision and discoverability

- Domain: Customer & Sales supervisory oversight; report entity: existing operations, sales exceptions/SLA and B2B field operations.
- Actors: global `reports.view` operator (including Super Admin for test fixture), and B2C store admin for *their own store*.
- Goal: review operations health, late/exception sales, channel fulfillment and performance with real filters/export when authorized.
- Placement: **Reuse**, not New Screen. The already functional Analytics → Reports Center (`analytics → reports_center`) is the canonical reporting workbench. Report-family page tabs select `operations`, `sales_exceptions` or `field_operations` at `admin.reports.index`. The B2C Store Reports module is the existing scoped entry point for store managers. No second route or Sidebar link copies the same data.
- Navigation type: `SIDEBAR` for global Reports Center, `DOMAIN_HUB`/store module for scoped Retail. Parent: Analytics or the authorized store workspace; fallback: existing Reports Center family tabs.
- Exact authority: existing `AdminNavigation::groupsFor` projects `reports.view`; `ReportController@index` delegates to `ManagementReportService` for report catalog, store assignment, channel and row scoping. The server route permission and service-level data restrictions are authoritative, not the menu. `reports.export` is separately required for export.
- Module and readiness: these are existing **core** management report families, not an uncertified ERP/BI module; `erp_bi` is an independent enterprise entitlement and is *not* automatically enabled by this P204 mapping. Disabled/unauthorized optional ERP remains fail-closed under its own route. No new entitlement, feature flag, role grant, permission, schema or readiness status is created.
- Client: existing `admin.reports` has localized page/family labels, selected-family tabs, real paginated table/empty state, report filters and RTL/LTR document direction. Responsive reports layout stays unchanged; no new UI/screen primitive.

## Actual functionality versus source request

Existing production-code paths already calculate the `operations` and `sales_exceptions` reports from scoped orders, statuses and operational exception sources. The `field_operations` report queries Van assignments/execution and explicitly restricts the channel to `b2b`. This mapping covers the supervisory-report destination without creating misleading empty navigation or a second business report implementation. It does **not** claim that a separate BI system, ERP certification or manual staging acceptance is complete.

## Automated acceptance coverage

New `backend/tests/Feature/V2SupervisoryReportsRouteContractTest.php` covers:

1. Global Super Admin: one authorized Reports Center entry (route + permission), three real existing report families, AR/EN and RTL/LTR authenticated HTTP view rendering.
2. No-role actors: absent menu entry, HTTP 403 on all three report-family URLs and HTTP 403 on report export, in both locales.
3. Store-scoped Retail admin: existing B2C Reports module reachable only for assigned store, global Reports Center not independently granted, missing-store validation, foreign-store 403/404, assigned-store operations report HTTP 200 and B2B field-operations denial.
4. No sales/finance or fleet data mutation, and no cross-channel B2B→Driver or B2C→Van fulfillment substitution.

Related earlier report coverage: #2637 / PR #3108, `V2SalesReportsAuthorizedNavigationTest`, plus `ManagementReportingSuiteTest`. This P204 test specifically adds supervisory operations and Van-channel negative regression rather than reimplementing P307 Sales Reports.

## Cross-platform impact

| Surface | Assessment | Evidence |
| --- | --- | --- |
| Backend/Dashboard | Real existing workbench/authorization reused; additive HTTP regression only | Only test and this QA note changed; no route/controller/view/runtime edit |
| Customer app | Not affected | No Customer API, identity, pricing, invoice, order or Flutter source touched |
| Driver app | Not affected | No Driver API/dispatch/assignment/status/Flutter source touched |
| Van app | Not affected | No Van API/visits/assignment/stock/Flutter source touched; existing B2B-only reporting behavior is regression-tested from Dashboard |

No app-specific V2 issue is necessary for this read-only coverage because app contract/runtime code is unchanged. The platform invariant remains B2B=VAN ONLY, B2C=DRIVER ONLY.

## Validation and release gate

Require exact-final-HEAD green Repository Policy, V2 Isolation Gate, FOODEX Required CI Gate, Laravel/backend full checks and AR/EN coverage before merging. Only merge PR into `Foodex_V2`. Once integration and required automated checks are verified, use owner decision #2503 to mark leaf completed with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No main merge/cherry-pick/tag/release/production deploy is authorized.
