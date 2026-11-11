# V2 P310 — Customer Service Reports canonical route, real data and scope

Issue: #2640 | Parent: #2503 | Sidebar matrix key P310 | Actual DOCX paragraph P299 | Integration: `Foodex_V2` ONLY

## Placement and discoverability resolution

| Metadata | Decision |
| --- | --- |
| Domain / entity | Reports & BI / Customer service and customer activity |
| Business user goal | Inspect customer purchase activity, active/new/returning cohorts, order value and customer report filters |
| Authorized actors | Users with effective `reports.view` global permission; store-scoped B2C store admin for assigned store only |
| Workflow / frequency | Read-only recurring customer service monitoring; existing paginated reporting grid |
| Placement rule | **Reuse** existing canonical Analytics → Reports Center (do not create duplicate route or Sidebar node) |
| Canonical route | `admin.reports.index?report=customers` (global) or existing `admin.b2c.module?module=reports&store_id=<authorized>` (retail-store entry point) |
| Navigation | `SIDEBAR` Analytics `reports_center`; `DOMAIN_HUB` retail store Reports; customer family selected using `PAGE_TAB` |
| Renderer and source | `ReportController@index`, `admin.reports`, `ManagementReportService::customersReport`, `AdminNavigation` |
| Permission and scope | `reports.view` + `OperationalTenantScope`, active store role and B2B/B2C channel; separate `reports.export` for file export |
| Entitlement / readiness | This is an existing core report family; enterprise BI/ERP entitlement/readiness is separate and never implicitly enabled by the Customer report tab. No new flag, permission, role or module activation |
| Desktop / tablet / mobile web | Existing Reports Center toolbar, tabs, responsive filter grid, table horizontal-scroller and pagination; no new markup/surface |
| AR/EN and RTL/LTR | Existing `reports.families.customers` and report labels; page direction resolves from locale |
| States | Real customer metrics and records; empty results; invalid/foreign store denied; no-role 403; linked back to existing Analytics/report family tabs |

This mapping does not claim that a separate case-management or ERP customer-service analytics module has been commissioned or certified. It delivers the specified navigable **customer reporting** destination using existing actual customer metrics without introducing a dead link or a UI-only permission. Related P307 sales reporting #2637 and P204 supervisory reporting #2534 remain separately scoped.

## Data/security verification

The existing `customersReport` groups customer orders by channel/identity, derives first order from a per-customer query, reports active/new/returning counts and noncancelled customer value, and paginates current report rows. Store and channel filters are enforced server-side before any query. A Retail actor may not select a foreign store or B2B customer channel. `reports.export` is a distinct authority, not inferred from viewing a tab.

`backend/tests/Feature/V2CustomerServiceReportsAuthorityTest.php` exercises real seeded Laravel HTTP and DB behavior:

1. Super Admin can discover exactly one Analytics Reports Center and render a real `customers` family in Arabic and English with correct RTL/LTR. Synthetic delivered and cancelled orders yield two orders but only the delivered amount in recognized customer value; validates active/new/returning metrics and human-readable customer row.
2. Roleless actor cannot see the global/retail reporting entry nor access `customers` directly or export a PDF (HTTP 403), in both locales.
3. A B2C store admin sees only their assigned-store reports module, gets a scoped customer report with a real customer record and value, and cannot open foreign-store report data or the wholesale channel. Missing scope follows existing validation; other store is 403/404 as appropriate. No cross-store customer row/total leakage.

The fixture uses synthetic test customers and orders only, with no live identity, financial mutation, extra permission or new schema. The optional ERP BI route stays under its own entitlement/readiness policy; this issue does not bypass a disabled module.

## Cross-application impact matrix

| Surface | Assessment | Reason/evidence |
| --- | --- | --- |
| Backend/Dashboard | Additive security/functional regression for existing production routes | One new feature test; existing controller, service, navigation, Blade and roles unchanged |
| Customer app | Unaffected | No Flutter/API/customer price, auth, notification, order, account or identity contract changed |
| Driver app | Unaffected | No Flutter/API/driver dispatch, B2C assignment, collection or proof changed |
| Van app | Unaffected | No Flutter/API/B2B-only dispatch, Van trip, route, offline or stock changed |

No separate mobile issue is required for a test/documentation-only change. Existing B2B=VAN ONLY and B2C=DRIVER ONLY fulfillment invariants remain untouched.

## CI, integration and manual acceptance

Mandatory latest PR-HEAD checks: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate (including backend tests, fast-fail Pint/PHPStan/security/OpenAPI, AR/EN localization and MySQL/Redis install/upgrade/recovery as applicable). A successful push or PR by itself is **not** acceptance. Any failed test must be fixed on the same branch/PR.

Only a green, reviewed PR base `Foodex_V2` may be integrated. After verifying merge SHA, exact-head CI and covered scope, close the leaf per owner's 2026-11-10 #2503 decision with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No main merge, tags, release factory, cPanel production change or rollout is permitted.
