# FOODEX V2 #2720 — T356R3 Customer 360 Accounts Sidebar migration

## Source, exact placement and ownership
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native table T356R3: remap `customer_360` to **العملاء / العملاء 360** without duplicate, stale key or privilege change.
- Master: #2503. One issue-scoped branch and PR to **Foodex_V2 only**; no main, tag, release, production or live cPanel.
- Domain: Customers / Accounts. Entity: canonical `PlatformCustomer`. Actor: authorized Wholesale accounts manager or scoped Retail store administrator. Goal: open one real, filtered Customer 360 view and its authorized record details. Workflow: regular CRM discovery/read, not an alternate mutation or financial grant.
- **Reuse** existing Accounts sidebar business group and existing `Customer360Controller::index` route. No separate page, duplicate route, new sidebar key, new database field, privilege, form, UI primitive or hard-coded label.
- Navigation type: **SIDEBAR**, `group=accounts`, `key=customer_360`; label key `admin.customer_360`; canonical named route `admin.customer-360.index`, index view `admin.customer-360-index`, `navContext=customer_360`; record drilldown `admin.customer-360.show`. Existing browser back and Accounts group are the fallback. Existing desktop/tablet/mobile web shell, AR/EN translations and RTL/LTR apply without markup changes.
- Existing registered metadata: `scope=both`, permissions `customers.view` OR `b2b.accounts.view`. Sidebar `AdminNavigation::groupsFor` projects eligible links; `AdminSidebarManager` only applies cosmetic layout. The controller's `access()` and `OperationalTenantScope` still enforce effective role/permission/channel/store on real requests.

## Reuse and differentiation from completed #2517
- #2517 / PR #3050 already corrected the B2B account-manager channel ceiling and proved the Customer 360 runtime route and store authorization. Do not reintroduce it or edit shared `backend/config/admin.php`, `AdminNavigation.php`, controller, route or mobile code.
- This *migration-line-specific* acceptance proves the `T356R3` target **group + key + label + route + exact once** (including nested legacy layout) rather than only demonstrating business endpoint availability. It keeps the same B2B/B2C HTTP security and display logic; no fake second CRM screen.
- Existing #2517 test `V2Customer360SidebarContractTest` remains the canonical deep business contract. New `V2Customer360AccountsSidebarMigrationTest` targets Sidebar migration integrity and regression at real HTTP boundary.

## Automated acceptance and failure handling
1. Verify the registered `customer_360` group is Accounts, the route is registered, label and permission metadata are unchanged. A B2B account manager sees **exactly one** Sidebar occurrence in Accounts and the real index responds HTTP 200, with actual wholesale-store authorization, Arabic RTL and English LTR.
2. A store-scoped Retail admin sees exactly one **same canonical** Customer 360 link in Accounts. The HTTP controller returns only authorized Retail store scope and rejects a foreign Retail store filter (404).
3. An unrelated roleless user cannot gain a link or route access from a saved `is_visible=true` preference for another role; HTTP returns 403, persisted preference remains unchanged.
4. Existing `DashboardCustomer360Test`, `V2Customer360SidebarContractTest`, `AdminNavigationAuthorizationAuditTest`, `V2SidebarAuthorityMatrixTest`, real MySQL/Redis install-upgrade/recovery and Repository Policy/Required CI/V2 Isolation gates remain mandatory.

No data, roles, custom grants, permissions, audit records or client contracts are migrated. No production customer data is inspected. Manual owner/browser/device/Staging UAT is **DEFERRED_TO_V2_FINAL** by #2503; exact-head automated CI is **not** deferred.

## Impact matrix
| Surface | Scope | Impact decision / evidence |
| --- | --- | --- |
| Backend/API | Real existing `Customer360Controller::index`, `OperationalTenantScope` | No changes to routes, API, schema or security; existing HTTP 200/403/404 tested |
| Dashboard | Existing `accounts / customer_360` and canonical page | Migration mapping secured with test for duplicate/stale placement, permissions, AR/EN/RTL/LTR |
| Customer Flutter | Not affected | No customer auth, order, storefront, credit, API payload, push, deep-link or app source change |
| Driver Flutter | Not affected | No driver auth, dispatch, proof, wallet, status or API change; B2C DRIVER ONLY unchanged |
| Van Flutter | Not affected | No van auth, routes, visits, assignments, offline, settlement or API change; B2B VAN ONLY unchanged |

No distinct mobile issue is required for this test/documentation-only Sidebar migration. No claim of manual acceptance or actual production runtime verification is made.

## Release/CI evidence
One issue-scoped PR with `base.ref=Foodex_V2`; merge and close leaf only after every required exact-final-head workflow completes GREEN (Laravel tests, PHP lint/Pint/PHPStan/security, AR/EN, isolation/policy, MySQL/Redis installer/upgrade/recovery). Add final tested SHA, CI run IDs and isolated merge SHA to issue handoff. `main` and production remain forbidden.
