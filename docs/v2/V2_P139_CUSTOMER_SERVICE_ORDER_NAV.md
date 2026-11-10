# V2 P139 — Customer Service order permission and navigation regression

Issue #2861, source line P139 of the FOODEX Unified RBAC / ERP / Sidebar discussion. Parent #2503. **Foodex_V2 ONLY**; never main, release, or production without explicit owner authorization.

## Canonical contract

The seeded global **CUSTOMER_SERVICE_MANAGER** role has orders.view and orders.manage; the related CUSTOMER_SERVICE_AGENT retains orders.view. The action under test is a Super Admin's real HTTP `PUT admin.security.users.permissions`, applying a **per-user global DENY for orders.view** without editing the underlying role's template or other users.

Navigation must derive visible items from effective RBAC, not a static role name:

- `AdminNavigation::groupsFor` / Operations / `order_operations` links to `admin.operations.orders.index` only while `orders.view` is effective.
- A manager retaining `orders.manage` must not keep the order listing visible or open the direct HTTP GET after `orders.view` is denied. Direct requests must return HTTP 403.
- `erp_customer_service` remains available through the separate `customers.view` entitlement; another service agent with no override remains authorized and sees Orders.
- Restoring the per-user baseline removes the DENY, restores the visible item and direct HTTP access.
- Unauthorized customer-service agents cannot grant/revoke a supervisor's order privileges.
- Verify the existing `user.permissions_changed` audit record; global template and unrelated user rows must not be modified.

## Test evidence

`backend/tests/Feature/V2CustomerServiceOrderNavigationTest.php` runs these cases with a fresh test database, CoreReferenceSeeder, canonical WholesalePrincipal fixture, and real HTTP Dashboard requests. The test checks both the navigation tree and server-side order controller enforcement, avoiding UI-only acceptance. All required exact-head Backend, AR/EN Localization, RBAC regression, MySQL/Redis installation/upgrade/recovery, Repository Policy, and V2 Isolation checks must pass before merge.

Scope: existing global Wholesale/B2B profile. No new module-entitlement state or finance/SoD privilege; underlying domain/store/channel rules remain governed by canonical OperationalTenantScope and enterprise entitlement tests. The test itself changes no services, routes, templates, translations, or API contracts.

Customer app: no contract change. Driver app: no contract change, Driver remains B2C only. Van app: no contract change, Van remains B2B only. AR/EN labels/layout are not modified; existing localization checks remain mandatory. This is a test-only integration; it cannot certify complete ERP operational readiness or a production deployment.

## Closure

Close #2861 only after PR tests and policy are green on the *last PR commit*, PR is merged and confirmed on `Foodex_V2`, and any discovered navigation/HTTP mismatch is resolved in the same issue. Parent #2503 remains open; no promotion to main.
