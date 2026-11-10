# V2 P307 — Sales Reports authorization and navigation evidence

Issue: #2637 | Parent: #2503 | Integration target: `Foodex_V2` only

## Placement and canonical route

- Domain: Reports & Analytics; entity: existing Sales / Orders reporting.
- Roles: global user with `reports.view`; retail store admin with a store assignment.
- User goal: inspect recognized sales, orders and sales exceptions, then filter/export within authorized scope.
- Workflow: read-only, frequent analytics workbench, with paginated data.
- Reuse → Extend → Compose → New Screen: **Reuse** the established Reports Center and its report-family tabs. No additional business ledger, page or navigation tree is needed.
- Navigation type: `SIDEBAR` existing `analytics → reports_center`, with `admin.reports.index` and `report=orders` or `report=sales_exceptions`.
- Retail navigation: existing store-bound `b2c_reports` module (`admin.b2c.module`, `module=reports`, `store_id=<authorized store>`).
- Entry and back-path: the existing Analytics / Reports Center link and existing report tabs; the active family is encoded by the `report` query parameter.
- Authority: `AdminNavigation`, `ReportController`, `ManagementReportService`; server-side `reports.view` and store scope, never display-only visibility.
- User-facing AR/EN labels: `reports.title`, `reports.families.orders`, `reports.families.sales_exceptions`; page direction follows the selected locale.
- Desktop/tablet/mobile-web: unchanged existing responsive report grid, filters and tab controls; no new UI surface.
- States: existing filters and pagination, empty datasets, denied access, invalid/cross-store access; do not grant access on the basis of Sidebar visibility.

## Acceptance regression

`backend/tests/Feature/V2SalesReportsAuthorizedNavigationTest.php` verifies:

1. One permitted Reports Center destination in Analytics and both genuine sales-family tabs in Arabic/English.
2. Server-rendered real reports return HTTP 200 for the global authorized actor, preserve RTL/LTR and select the correct family.
3. Roleless actors cannot see the menu entry, fetch the report or export it (HTTP 403).
4. Retail store-scoped actors use the assigned-store Reports module only; another store is 404, and the unscoped Reports Center remains 403.

Related existing tests: `V2ReportsCenterSidebarMigrationTest` and `ManagementReportingSuiteTest`.

## Cross-surface impact matrix

| Surface | Assessment | Evidence |
| --- | --- | --- |
| Backend/Dashboard | Existing canonical navigation, renderer, reports and permissions reused; new regression test only | Only the new feature test and this issue note are changed |
| Customer | Not affected | No mobile, API, pricing, customer-order or payload code touched |
| Driver | Not affected | No dispatch, delivery, API or driver-app code touched |
| Van | Not affected | No Van fulfillment, fleet, API or van-app code touched |

No new mobile Issue is necessary because no mobile runtime/API behavior changes.

## CI and closure

Require exact-head Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate green before any merge. After integration, report exact PR number, final SHA and checks in the Issue. Manual acceptance is deferred by the latest owner decision in #2503; this note is not a claim that manual browser/device validation occurred. **Never merge into `main`, tag, release or deploy.**
