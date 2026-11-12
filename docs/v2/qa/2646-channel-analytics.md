# V2 #2646 / P316 — Channel Analytics via existing Reports Center

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P316; parent #2503. Only merge base: `Foodex_V2`. No main, deployment, release, tag or production-data action.

## Reuse, placement and access

Reports & BI > existing `Reports Center` > dynamic `Channel Analytics` page tab; canonical route `admin.reports.index?report=channels`. Reuse instead of introducing another route/screen/Sidebar item. Existing Reports Center navigation is discoverable via Analytics group. Entry: `PAGE_TAB`; back: Reports Center; responsive existing shared Dashboard, AR/RTL and EN/LTR. Permission `reports.view` for read, `reports.export` for export, plus actual tenant/store/channel scope via `OperationalTenantScope` and existing server-side report controller/service; no visibility-only grant. No new unrestricted module/entitlement grant or database mutation.

Actual database orders are filtered before grouping; per channel/store detail includes order count, gross value, recognized revenue (excludes cancelled/refunded) and excluded-order count. KPIs and full group count are independent of detail pagination. Existing audited XLSX/DOCX/PDF export is reused; labels render in selected AR/EN locale. Stores of other tenants and cross-channel requests remain denied server-side.

## Tests and review

`backend/tests/Feature/V2ChannelAnalyticsReportTest.php` covers bilingual RTL/LTR real cross-channel/store totals, exclusion policy, pagination invariant, authorized discoverability, protected audited export, scoped Retail actor, cross-store/Wholesale 403, and roleless 403. Existing Reports Center, BI readiness and AR/EN checks must remain green. Mandatory source/CI verification must happen on exact PR head; green CI alone does not certify manual runtime screenshot/a11y acceptance.

## Mobile impact matrix

- **Customer:** no customer/admin API route contract modification; `/api/v1/admin/reports/{report}` remains limited to its existing four report families. No Flutter, auth, order, notification change.
- **Driver:** no Driver API, collection, dispatch, notifications or Flutter change.
- **Van:** no Van API, territory, visits, stock, dispatch or Flutter change.

B2B Van-only and B2C Driver-only invariants untouched. No app-specific issue needed for this Dashboard-only read-only report. Keep Issue OPEN until exact-head CI, review, and applicable runtime/accessibility acceptance are actually evidenced.
