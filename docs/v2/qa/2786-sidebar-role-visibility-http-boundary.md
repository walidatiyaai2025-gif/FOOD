# V2 #2786 / P035 — Sidebar role visibility vs server HTTP authorization

**Owner-locked integration:** `Foodex_V2` only, parent #2503.
**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P035.

## Decision and architecture

The existing `AdminNavigation`/server route guards decide **whether** the active actor may reach a route. `AdminSidebarManager::apply` may hide or present an **already-authorized** item according to an administrator's per-role visibility preference. The table `admin_sidebar_role_visibility` is display metadata, not an access-control list. Reuse this existing implementation. No new route, permission, menu, service, role, module, entitlement, layout, or alternate authorizer is introduced.

This is deliberately different from:
- P040 / #2792: global Sidebar item/group `is_enabled` preferences cannot grant/revoke HTTP permission.
- P375 / #2896: forged role layout does not grant privileged or cross-channel access.
- P157 / #2869: canonical explicit store DENY affects authorized route projection.
- P382 / #2902: current canonical Sidebar/metadata authorization parity, owned by another worker.

Here P035 covers the previously untested **per-role visibility preference** on real allowed and disallowed HTTP routes. The same role must retain its original permission assignments even when visibility changes.

## Server-side acceptance matrix

| Actor/condition | Sidebar result | Canonical HTTP result | Data protection |
| --- | --- | --- | --- |
| Active Wholesale B2B_ADMIN, hidden `catalog_management` by own role preference | Hidden | `admin.catalog.index`: 200 | Existing `catalog.view` preserved; no role-permission rewrites |
| Active Retail Inventory, own Store A, hidden `b2c_inventory` by role preference | Hidden | `admin.b2c.module` inventory / Store A: 200 | Scoped inventory read preserved |
| Same Retail Inventory actor, unassigned Store B | Hidden | Store B inventory: 403/404 | Never widens store scope |
| Same Retail Inventory actor, direct Wholesale inventory link | Hidden | `admin.b2b.module` inventory: 403 | B2B/B2C isolation |
| Wholesale B2B_ADMIN with saved role `is_visible=true`, explicit user global `catalog.view` DENY | Hidden by effective authorization | `admin.catalog.index`: 403 | DENY wins over inherited role grant and saved visibility |

The test executes both Arabic (`ar`/`rtl`) and English (`en`/`ltr`) real Dashboard HTTP responses, checks canonical role/user permissions and stores, asserts denial on forged URL parameters and verifies original role permission mappings remain unchanged. No financial mutation or approval transaction is introduced; Finance Maker/Checker SoD remains covered by separate canonical tests. These are authorization/HTML direction checks, **not** independent visual or accessibility certification.

## Access, UI and impact

- **Backend:** New `backend/tests/Feature/V2SidebarRoleVisibilityHttpBoundaryTest.php` only. Existing permission, store/channel scope and user DENY remain authoritative.
- **Dashboard:** Existing navigation and Catalog/Retail inventory routes are reused. No new page/control, no UI mutation, no independent Sidebar authorization. Existing navigation entry point remains the current catalog/retail Inventory item; hidden role preference is non-reachability from navigation by choice, not server denial.
- **Customer:** NOT AFFECTED. No public/mobile API, login, DTO, permission, customer navigation, deep-link or checkout changed.
- **Driver:** NOT AFFECTED. No Driver API, GPS, assignment, status or B2C Driver-only fulfillment changed.
- **Van:** NOT AFFECTED. No Van API, wholesale dispatch, visit, status or B2B Van-only fulfillment changed.

As all changes are one backend Feature test and this QA evidence, no separate `V2 | MOBILE ...` implementation issue is triggered. Mobile source/test jobs may be path-skipped; this is not proof of full end-to-end mobile runtime acceptance.

## CI and handoff

- Branch `test/2786-v2-sidebar-role-visibility-http-boundary`; exactly one PR based **only** on `Foodex_V2`.
- Required exact-head checks: Repository Policy, V2 Isolation, localization AR/EN, backend PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel full suite and MySQL/Redis clean install/upgrade/recovery `required-ci-gate`.
- Record tested SHA, workflow URLs and integration SHA in Issue #2786 only after genuine successful checks and merge.
- **Do not close Issue #2786 based solely on green CI.** Independently verify isolated V2 staging in desktop/mobile browsers, keyboard/focus/screen reader and responsive widths (320–1280 px), AR RTL/EN LTR for an authorized and forbidden account, plus role-visibility persistence and audit trail. Document evidence before final acceptance.
- No direct commits to `Foodex_V2`, no main PR/push/merge, production/cPanel installation, release/tag or store publication.
