# FOODEX V2 Sidebar P311 — Analytics group authorization and route evidence

Issue: #2641 | Parent: #2503 | Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / `P311` (Reports & BI). Isolated integration target: **`Foodex_V2` only**.

## Purpose, route authority and placement

| Required decision | Resolved implementation |
| --- | --- |
| Business domain / entity | Reports & BI / Analytics business-domain navigation container |
| Personas | Global authorized Reports reader, scoped B2C Retail store manager, unprivileged actor; effective DENY overrides |
| User goal | Discover only report and analytics workflows actually authorized by the server |
| Workflow / cadence / density | Frequent read-only monitoring/reporting; moderate to high density, existing reports grid |
| Reuse / Extend / Compose / New Screen | **Reuse** the existing `AdminNavigation::groupsFor` `analytics` group, `ReportController` and store Reports workbench. No new route, Sidebar child, renderer, asset or model. |
| Navigation type | `SIDEBAR` business group; its children target existing `PAGE_TAB` report families / Retail `DOMAIN_HUB` module |
| Navigation owner / entry | Existing Analytics Sidebar group; Reports Center or assigned-store Reports module |
| Canonical routes / back-path | `admin.reports.index?report=orders` (global), `admin.b2c.module?module=reports&store_id=...` (authorized Retail store). Existing report family tabs preserve navigation context. |
| Exact server authority | `reports.view` for Reports Center, `OperationalTenantScope` plus active assigned store/role for B2C Reports module. `reports.export` remains a separate permission. Server route/store/channel checks are authoritative. |
| ERP readiness / entitlements | `erp_bi` is separately guarded by `enterprise_bi` operational readiness and effective module entitlements. It may not appear as an operational BI link solely due to a broad Reporting permission. The existing optional module remains fail-closed. |
| Empty/error/disabled states | An actor with no permitted children receives no `analytics` group; unauthorized direct Reports Center access 403; foreign-store requests 403/404 and explicit store DENY fails closed. No dead or enabled-but-unready links. |
| AR/EN + desktop/tablet/mobile web | Reuse existing localized `admin.nav_groups.analytics` and report labels, existing RTL/LTR direction and responsive Sidebar drawer; no new markup, layout or localized keys. |
| Fallback | Existing Analytics/Reports Center and report family tabs; no direct-URL-only or duplicate BI page. |

This is a **navigation-group acceptance** issue, distinct from already delivered leaves: #2637 Sales Reports, #2534 Supervisory Reports and #2640 Customer Service Reports. It does not activate or certify an ERP/BI module, replace BI operational evidence or grant roles/permissions.

## Runtime and negative regression evidence

The only additive code change is `backend/tests/Feature/V2AnalyticsGroupAuthorizationTest.php`, which exercises `AdminNavigation` and authenticated Laravel HTTP, not a mocked menu:

1. Roleless actor: zero Analytics group when it would be empty; direct Reports Center HTTP 403 in both locales.
2. Super Admin/global authorized actor: exactly one Analytics group and exactly one true `reports_center` child, valid route/permission, real Reports Center HTTP 200, AR/EN and RTL/LTR. Noncertified `erp_bi` not promoted as an operational destination.
3. B2C store administrator: permitted `b2c_reports` child retains its own `store_id` context, and does not manufacture a global Reports Center, B2B Reports or ERP BI grant; owned-store module/Reports HTTP 200; foreign-store HTTP 403/404; B2B channel denied, in both locales.
4. Explicit `reports.view` store DENY: effective navigation omits `b2c_reports` and direct Reports HTTP request is 403. This protects against confusing menu visibility with backend authorization.

All test stores/users/overrides are synthetic. No database migration, production role/permission assignment, report export, live ERP certification or financial mutation occurs.

## Cross-app and system impact

| Surface | Impact and evidence |
| --- | --- |
| Backend / Admin Dashboard | Issue-scoped additive Laravel feature tests only. Reuse existing navigation/route enforcement without touching shared implementation. |
| Customer (Flutter) | **Not affected**: no Customer app/API/identity/cart/order/invoice/notification or storefront contract changes. |
| Driver (Flutter) | **Not affected**: no Driver app/API/dispatch/assignment/proof/collection contract changes; B2C DRIVER ONLY remains enforced. |
| Van (Flutter) | **Not affected**: no Van app/API/territory/trip/stock/assignment changes; B2B VAN ONLY remains enforced. |

No mobile-specific V2 issue needed because this delta changes only a Dashboard regression test and its evidence note.

## Acceptance gates and release isolation

The PR must target `Foodex_V2` exclusively, with exact-latest-HEAD Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate **successful**, including Laravel full suite, Pint/PHPStan, security/OpenAPI, localization AR/EN and real MySQL/Redis installation/upgrade/recovery CI where applicable. Never assume CI succeeds because a commit or PR exists.

If all implementation scope and automated checks pass, record tested HEAD/merge SHA/CI links and close the leaf with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner #2503. The parent master and comprehensive UX/ERP gates remain open; no claim of manual staging/browser acceptance.

**No main changes, promotion, cherry-pick, tags, releases or production deployment are authorized.**
