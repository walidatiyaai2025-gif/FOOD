# V2 #2718 / T356R1 — canonical My Work under Overview

## Source and placement
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Table 356 row 1 (`T356R1`), parent #2503. Scope is Sidebar migration of existing `enterprise_action_center` to **نظرة عامة / مركز الإجراءات**.
- Decision: **Reuse**, not a new route, renderer, API, permission or duplicate Sidebar key. Domain Overview → Enterprise My Work / approvals; entry point authorized Dashboard Sidebar; `overview` group, `enterprise_action_center` item, canonical `admin.action-center.index` (GET `/admin/action-center`), `EnterpriseActionCenterController@index`, `admin.enterprise-action-center` Blade; existing responsive RTL/LTR Dashboard shell. Back navigation remains the shared Dashboard. The existing ERP `erp_my_work` shortcut can point to the SAME canonical route, but is a distinct contextual link rather than a second endpoint or duplicate `enterprise_action_center` key.
- Current `AdminNavigation::groupsFor` places the item in Overview only if `canAccessMyWork` is true; `backend/config/admin_sidebar.php` registers its authoritative group as `overview` and protects it from cosmetic disable/reparent. `AdminSidebarManager::apply` cannot authorize a hidden link. No source mutation necessary because canonical placement is already correct on `Foodex_V2`; this scoped PR freezes it with direct HTTP regression tests instead of duplicating code.
- Permission model: B2B platform operator with authorized Dashboard role or a valid B2C `B2C_STORE_ADMIN` assignment to an active owned store. Not every operator with Dashboard access can see this workspace: customer-service-only, roleless and inactive actors fail direct HTTP authorization. The controller performs `canAccessMyWork` for GET/export/decision routes; real queue, finance approval and store-scoped workflows remain protected by Enterprise Action Center/My Work service, workflow `maker/checker`, role permissions and the current tenant/entitlement/readiness policies. A visible menu is **not** an approval grant.
- States: existing index supports localized empty queues, pagination, search, pending/overdue and errors. No new user-facing strings, no translation keys changed, and no visual primitive added. AR/EN presentation reuses `enterprise_action_center.nav_title` and the existing `dir="rtl"`/`dir="ltr"` Dashboard shell.

## Acceptance and tests
`backend/tests/Feature/V2MyWorkOverviewNavigationContractTest.php`:
1. Registered item belongs only to Overview, exactly one `admin.action-center.index` named route, exactly one `enterprise_action_center` key, B2B authorized actual GET 200 + Arabic RTL.
2. Retail B2C store-scoped administration finds the same Overview item, GET 200 + English LTR, with no platform-superadmin grant.
3. Customer Service operator, roleless and inactive actors cannot gain My Work access through direct GET/export/decision URL; index/route and Sidebar agree. No unapproved decision writes.
4. A saved cosmetic attempt to reparent/disable the protected Overview item cannot change its canonical effective group nor prevent its authorized HTTP destination.
Existing `EnterpriseActionCenterTest::test_store_scoped_action_does_not_leak_to_another_store_assignment` covers a **real cross-store approval queue** and forbidden foreign-store decision. Existing `AdminNavigationAuthorizationAuditTest::test_my_work_http_endpoints_enforce_the_same_platform_scope_as_sidebar` independently checks HTTP/sidebar parity; these tests must pass in the exact-head full Laravel suite.

## Impact classification
| Surface | Impact and compatibility evidence |
|---|---|
| Backend / API | Existing admin HTTP and workflow authorization only; new additive Laravel tests. No API endpoint/schema changes, finance posting, delegation, audit or storage migration. |
| Dashboard | Existing Overview item and canonical live My Work page reused; no duplicate renderer, route or visible UI change. Existing AR/EN, RTL/LTR responsive shell retained. |
| Customer | No changed Flutter code, storefront/credit/cart/notification API, token or deep link. No new Customer mobile issue needed. |
| Driver | No changed Flutter code, B2C dispatch/proof/wallet API, token or deep link. B2C→DRIVER only invariant unchanged. No new Driver mobile issue needed. |
| Van | No changed Flutter code, B2B territory/visits/consignment API, token or deep link. B2B→VAN only invariant unchanged. No new Van mobile issue needed. |

## CI/acceptance policy
Run scoped `php artisan test --filter=V2MyWorkOverviewNavigationContractTest` under `backend/` and all applicable GitHub-required checks (Repository Policy, FOODEX V2 Isolation, Pint/PHPStan/security/OpenAPI, AR/EN localization, full Laravel, MySQL/Redis fresh install/upgrade/recovery). The merge must be **Foodex_V2 ONLY** at the tested PR head; record SHA and CI links on #2718. Manual browser/device/staging/UAT remains deferred to final V2 gate by current owner decision #2503, not represented as performed. No `main` merge, tag, release or production deployment.
