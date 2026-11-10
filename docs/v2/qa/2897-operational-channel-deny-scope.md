# V2 P376 — Shared operational channel scopes respect explicit DENY

Issue #2897 · Parent #2503 · Source `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / P376 · Integration **Foodex_V2 only**.

## Domain, placement and authority

**Domain:** Wholesale/Retail operational orders. **Entities:** Store, assigned role, user permission override, order. **Roles:** B2B_ADMIN + RETAIL_OPERATIONS (including mixed-role staff), and system Super Admin. **Goal:** a single Dashboard page can use common UI without mixing B2B/B2C tenancy or weakening explicit user-level permission DENY. **Canonical entry points:** `/admin/operations/orders`, `/admin/b2b/orders`, `/admin/b2c/orders`. **Navigation owner:** existing Orders / Operations links; **pattern:** Reuse → Extend the existing `OperationalTenantScope` read-side authorization. No new screen/route/sidebar entry, no new permission or direct-url exception. Both Arabic RTL and English LTR retain the existing Orders page renderer and server-side permission checking.

**Finding:** `OperationalTenantScope::allowedStoreIds` collected candidate store IDs from inherited role/store permissions and global channel roles but did not subtract an explicit DENY recorded in `user_permission_overrides`. As a result the same shared order view could still be scoped to a Retail store denied for that individual, despite `User::hasPermission('orders.view', store_id)` correctly returning false. The mismatch could affect list queries, dynamic filters and events, rather than merely Sidebar visibility.

**Fix:** deny scopes for the exact user and permission are fetched **once** in bulk after candidate collection. `global` DENY eliminates every candidate; `store:<id>` DENY eliminates only the corresponding store across the combined role/channel set. This follows the canonical User permission override precedence without running `hasPermission` separately for each store, preventing N+1 permission queries in large multi-store dashboards. Inactive actors are rejected before the Super Admin read-scope shortcut. A valid Super Admin keeps existing active-actor privileges; store ownership is unchanged.

## Security, negative paths and verifiable coverage

`backend/tests/Feature/V2OperationalChannelDenyScopeTest.php` tests:
- Mixed global B2B and two Retail store-role assignments; the individual Retail DENY removes only that Retail store while preserving the separate authorized Retail/Wholesale stores, matching canonical `hasPermission`
- Direct `/admin/b2c/orders` HTTP for the denied store returns 404, allowed Retail and B2B routes remain valid
- A global DENY wins over both role paths: channel-scoped and combined `allowedStoreIds` all become empty; direct B2B/B2C and shared Operations URLs return 403
- AR/EN parity; authorized Dashboard response retains RTL/LTR HTML
- Inactive Super Admin has zero operational scopes even with remaining role assignments
- No mutation, ledger entry, migration, customer/order data alteration, or change to the role/permission seed

Other existing V2 user/role/store/channel matrix, module entitlement, AR/EN and maker/checker test suites continue to apply. MySQL/Redis real install/upgrade/recovery and exact-head Required CI must pass before isolated integration. Runtime browser-based accessibility, keyboard/focus and visual breakpoints remain a separate acceptance requirement; do not claim them tested without evidence.

## Per-app impact assessment

| Surface | Impact | Why |
| --- | --- | --- |
| Laravel Backend | **Affected** | Shared operational tenant-scoping result respects individual DENY |
| Dashboard | **Affected** | Existing Orders list, filters and direct URL enforce stricter scope; no renderer or navigation change |
| Customer App | **Not affected** | No customer mobile endpoints, tokens, payloads, store identity or Flutter behavior changed |
| Driver App | **Not affected** | No driver delivery assignment/status/deep link/API contract altered; driver dispatch policies remain authoritative |
| Van App | **Not affected** | No van-only fulfillment/assignment payload, route or Flutter behavior altered |

The fix is in a Dashboard-focused internal operational scope service; if later code introduces an actual mobile-consumed behavioral change, open/link a distinct V2 MOBILE issue before integration. No production release, Setup/Update ZIP, tag, deployment or merge to `main`.
