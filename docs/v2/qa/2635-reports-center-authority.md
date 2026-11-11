# V2 P305 — Reports Center: real routes, scoped lookup and independent export authority

- Issue: #2635 (Sidebar **التقارير**, P305, Reports & BI); parent #2503.
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P305; isolated merge target `Foodex_V2` ONLY.
- Claim: https://github.com/walidatiyaai2025-gif/FOOD/issues/2635#issuecomment-6788537223.

## Placement and discoverability

**Reuse → Extend → Compose → New Screen:** **REUSE** the working Reports Center `admin.reports.index` (`ReportController`, `ManagementReportService`, `admin.reports`). Do not add a duplicate page, top-level Sidebar destination, policy or fake route. P311 Analytics group source #2641/PR #3145 owns navigation-group projection. P305 verifies the **Reports Center operational surface**, family tabs, lookup/export authorization and data scope, distinct from the group smoke checks.

| Decision | Actual contract |
| --- | --- |
| Business domain / data | Reports & BI / six report families: orders, products, customers, operations, sales exceptions, field operations |
| Personas and scope | Platform Super Admin global reporting; Retail B2C Store Admin on an authorized active Retail store; unauthorized roleless actor; export-DENY store user |
| Entry point | Existing `analytics → reports_center` Sidebar child; within page, the family tabs and filters |
| Navigation type | `SIDEBAR` to Reports Center followed by `PAGE_TAB` per report family, with existing reset/back path |
| Permission and enforcement | `reports.view` for report data and scoped product/customer lookup; **`reports.export` independently** for downloads; `OperationalTenantScope` and current store-role assignment protect B2C store filters |
| Route endpoints | `admin.reports.index`, `admin.reports.lookups` (products, customers), `admin.reports.export` |
| Store/channel | B2C assignment scoped by store_id; disallow foreign store and B2B channel for a Retail-only actor; financial/ERP BI operational readiness remains separately enforced |
| UI and localization | Existing responsive `reports.blade.php` with localized report labels, AR/RTL and EN/LTR, filter form, accessible entity pickers, pagination and export affordances |
| Entitlements / readiness | No ERP BI module is promoted by granting `reports.view`. This PR does not enable any missing business module or change tenant entitlements |

## Test evidence

`backend/tests/Feature/V2ReportsCenterAuthorizedHttpContractTest.php` adds live Laravel HTTP + database regression checks:

1. Global Super Admin: the six canonical report families are present, real named Routes exist, actual report view loads in Arabic/RTL and English/LTR with family tabs, filter form, product/customer pickers. Analytics group still owns exactly one `reports_center` link; an uncertified `erp_bi` link is absent.
2. Synthetic Retail Store Admin: only the assigned B2C store is shown in report filter options, with a real HTTP 200 and correct locale direction; a second store and a B2B channel get HTTP 403. Direct products/customers lookup returns JSON only for owned store and HTTP 403 for the other.
3. Distinct export authority: a synthetic store-scoped `reports.export` explicit DENY retains `reports.view` and real report page HTTP 200, removes export links and returns HTTP 403 for crafted XLSX export. No `report.exported` audit event is created.
4. Roleless actor: no phantom Analytics menu entry and HTTP 403 on Reports Center, product lookup and export, without attempting any live export or database write.

Test fixtures are transient transaction-scoped data. No production admin or user role changes, report data migration, new routes, UI source edits, management policies or release updates are introduced.

## Backend / Dashboard / Customer / Driver / Van impact matrix

| Component | Classification | Regression / rationale |
| --- | --- | --- |
| Laravel Backend | **Only additive feature tests** | Controller/service/permission unchanged; real HTTP/data scope and negative export/lookup tests |
| Management Dashboard | **Existing UI reused, no visual change** | Tests exercise AR/EN, RTL/LTR, report family navigation, accessible mobile-web pickers, disabled export link and scoped selector |
| Customer Flutter | **Not affected** | No Customer API, invoices, profile, cart, price, authentication, notifications or routing contract change |
| Driver Flutter | **Not affected** | No B2C order assignment, delivery proof, wallet, session, notifications or API change |
| Van Flutter | **Not affected** | No B2B order, van visit, routing, stock/offline or mobile API change |

No linked mobile issue is needed because no app runtime, shared API, account, dispatch or entitlement contract changes. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

## Required verification and handoff

- Branch: `test/2635-v2-reports-center-http-scope-contract`; one PR **base=Foodex_V2**.
- Local targeted command: `cd backend && php artisan test --filter=V2ReportsCenterAuthorizedHttpContractTest`.
- Required green at **exact final PR head**: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate (`required-ci-gate`), backend PHP syntax/Pint/PHPStan/security, AR/EN localization, Laravel complete suite, MySQL/Redis install/upgrade/recovery gates. Any regression is fixed on **this** branch/PR after fresh worker-lease check.
- Existing concurrent PR #3153 owns `ReportController.php`; **do not modify it** from P305 without coordinating takeover. #3158/#3064 own `AdminNavigation.php`, #3065 owns ERP readiness files. Our PR changes no overlapping paths.
- Owner override #2503: completed leaf closes after green exact-head tests and verified isolated merge with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. Final end-to-end browser/UAT, staging and mobile device acceptance are tracked by parent #2503, not claimed here.
- Never push/merge to `main`, create tags, deploy, release or install on live cPanel without explicit owner authorization.
