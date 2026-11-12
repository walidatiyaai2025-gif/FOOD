# V2 P206 — Orders & Operations business group authority (#2536)

- **Source:** Unified RBAC/ERP/Sidebar DOCX P206; parent #2503; integration branch `Foodex_V2` only.
- **Placement:** reuse canonical `AdminNavigation::groupsFor()` group `operations`, not a new Sidebar node or endpoint. `backend/config/admin_sidebar.php` is the existing group metadata; `AdminSidebarManager` applies display preferences after server-derived permission and store/channel decisions.
- **Entity / goal:** role-authorized operations entry point for Orders/Driver workspaces. Group heading is a container without a route or permission; children inherit existing server guards.
- **Canonical routes:** `admin.operations.orders.index` (`orders.view`); `admin.b2c.module` (`module=orders`, selected authorized Retail store); existing `admin.driver-live-tracking.index`, `admin.b2b.module` and `admin.b2c.module` driver children where granted. No `incoming_orders` ghost child, no unready module opened.
- **Authorization boundary:** global B2B role sees only B2B Orders/driver workspaces; B2C Store Admin's Orders child carries its own authorized `store_id`. The HTTP controller remains the authority; a guessed foreign store is concealed and a B2B channel override is forbidden. Roleless/inactive accounts have no Orders group authority. Explicit per-user deny suppresses the Orders link and direct HTTP access.

## Executable acceptance

`backend/tests/Feature/V2OperationsGroupContainerAuthorityTest.php` verifies:
1. Group is non-clickable; all projected children name existing routes; Wholesale Orders real HTTP 200; AR/EN and RTL/LTR.
2. Retail selected-store navigation matches scoped HTTP access; foreign store 404; B2B 403.
3. Roleless/inactive account suppression and direct HTTP denial; explicit `orders.view` deny takes precedence over any role-derived Sidebar visibility.

Existing, independently implemented tests `V2OrdersSidebarAuthorizationContractTest`, `V2OperationsFleetSidebarPersonaContractTest`, `V2OrderOperationsSidebarMigrationTest` and `V2SidebarAuthorizedGroupCountsTest` cover adjacent P194/persona/menu mapping. This issue adds isolated P206 group-container evidence and does **not** duplicate their source changes.

## Surface and isolation assessment

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend | Regression-only | No controller, route, migration, API or permission modified |
| Dashboard | Existing group reused | No renderer, navigation configuration or CSS modified; existing responsive shell, localization and focus primitives remain source authority |
| Customer | No change | Public ordering contract and fulfillment untouched |
| Driver | No change | B2C dispatch/driver API and routes untouched |
| Van | No change | B2B Van assignment/visit/stock API untouched |

**Security/operations:** B2B = Van only, B2C = Driver only. No release, `main`, production, account/stock mutation or runtime feature-flag change.

**Verification:** This file is a test plan/source trace, not a claim of executed CI or manual browser validation. Exact-HEAD Laravel suite, formatting/static analysis, Required CI, Repository Policy and V2 Isolation must pass on the resulting PR before merge. Automated security/locale tests are required; manual device/browser accessibility and 320–1280px responsive review are deferred to final V2 program acceptance (#2503/#2750) only when the existing runtime is unchanged. Keep this issue open if any source-level acceptance remains unverified.
