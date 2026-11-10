# V2 P157 — Sidebar effective scope and store DENY authority

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P157. Parent #2503. Issue #2869. **Only integration target: Foodex_V2**.

## Decision / placement

**Domain:** Dashboard navigation authorization, **entity:** store-scoped page, **persona:** authenticated retail staff with one/multiple assigned stores, **user goal:** reach only pages permitted in the selected store. **Entry point:** existing business-domain Sidebar; `customer_360` shared link and `b2c_customers` workspace route. **Reuse → Extend:** the existing `AdminNavigation::hasPermissionInScope` projection; **no new page, endpoint, permission, menu item, lookup or UI control.** The canonical server-authorized route is `admin.b2c.module` for `module=customers`; the same principle is reused on other scoped links.

**Finding:** raw store-role `permission_role` membership inside `hasPermissionInScope` ignored an explicit user-level `deny` recorded for `global` or `store:<id>`. Consequently a shared Sidebar link could appear even if *every* assigned Retail store was denied, creating a misleading dead HTTP destination. A role assignment is only a candidate grant, never effective authorization.

**Fix:** after global permission check, reuse `OperationalTenantScope::allowedStoreIds($user, $permission, 'b2c')` to decide whether **at least one currently authorized Retail store** exists. That canonical service already applies active-account, valid store, real role/store permission, channel and one bulk query for global/per-store DENY. Do not implement a second, divergent authorizer or one per-store permission SQL round trip. `AdminSidebarManager` continues restricting the canonical projection by role visibility, personal layout, group and route integrity; it cannot grant access. Real HTTP handlers continue to authorize the selected store independently; a navigation item is never the final gate.

## Acceptance coverage

`backend/tests/Feature/V2SidebarStoreDenyAuthorityTest.php` covers:

1. Authorized staff assigned to two Retail stores can see the existing Customer 360 link; after a DENY on one store, the link remains only because another authorized store still exists. Allowed Retail HTTP returns 200 (AR RTL and EN LTR); denied store HTTP 403/404, Wholesale HTTP 403.
2. DENY on **both** assigned stores eliminates `customer_360` from the navigation and `V2SidebarMetadata` without mutating the underlying shared role or the permission-role pivot; both direct Retail HTTP URLs are still denied in Arabic and English.
3. A **global** DENY cannot be bypassed by an assigned store role; actual route denial and persisted override effect are asserted.

Existing V2 regression suites covering module readiness, per-store entitlements, owner diagnostics, cross-channel roles, disabled account, forbidden direct HTTP and Sidebar layout remain authoritative. These tests are regression evidence, not a claim that a hidden route itself enforces permission. No existing DB rows are deleted; test data are created in isolated temporary test DBs.

## Impact matrix

| Surface | Status | Reason |
| --- | --- | --- |
| Laravel Backend | **Affected** (read-only navigation projection) | Scoped Sidebar link now obeys canonical effective permission and store DENY |
| Dashboard | **Affected** (existing link visibility only) | Links requiring store-scoped permission disappear when **no** authorized store remains; no changed HTML, CSS, event handler or route |
| Customer Flutter | **Not affected** | No Customer API, account, order payload, token, deep link or Dart contract changes |
| Driver Flutter | **Not affected** | No Driver API/dispatch/status/deep link or Dart contract changes |
| Van Flutter | **Not affected** | No Van-only dispatch/route/order collection API or Dart contract changes |

Existing responsive Desktop/Tablet/Mobile-web and AR/EN RTL/LTR components, focus order and accessibility are unchanged because this is a server-side read-model correction; runtime viewport/focus/screen-reader acceptance must be independently recorded rather than asserted from HTTP tests. No new mobile-specific issue is needed absent a changed app contract.

## CI/release boundary

Focused Feature: `php artisan test --filter=V2SidebarStoreDenyAuthorityTest`. Full Laravel Backend tests, PHP Syntax/Pint/PHPStan/Composer Audit/OpenAPI, localization quality, V2 Isolation, Repository Policy, exact-head Required CI and real MySQL/Redis fresh-install/upgrade/recovery must be **verified** before merge. Document exact workflow results on Issue #2869 and preserve issue OPEN until full owner acceptance, not simply the automated green gate.

No changes to `main`, production runtime, ERP release readiness, Setup/Update ZIP artifacts, tags or deployment.
