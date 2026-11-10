# V2 #2830 / P093 — Sidebar group is a container, not a permission

Parent: #2503. Isolated integration: **Foodex_V2 ONLY**. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P093.

## Existing authoritative implementation (reused, not duplicated)

- `AdminNavigation::groupsFor` supplies page destinations only after real permission, tenant/channel, store, and module/entitlement checks. Its group header has no route or permission grant.
- `AdminSidebarManager::apply` prunes absent/unregistered route destinations, hidden items and empty groups; existing V2 #2797 has direct AR/EN regression coverage for this. Group membership cannot grant a forbidden route.
- `SidebarManagementController::updateRoleAccess` is the canonical existing administrative mutation route, `admin.sidebar-access.roles.update`. This Issue **extends** its validation, not the menu UI or route tree. It now refuses to persist unrecognized item keys, including group IDs, with `sidebar_admin.invalid_item` in the acting admin's locale, before any write or audit. No group may be submitted as a page permission. Valid page selections work exactly as before.

## Placement and scope

- Domain: Dashboard security/navigation. Entity: Sidebar role visibility, not RBAC role permissions. Actor: authenticated role administrator (`roles.manage`). Goal: safely configure already-authorized pages; this cannot mint a new permission.
- Existing parent: `/admin/sidebar-access` (`ADMIN_SETTINGS`); **Reuse → Extend** with no new screen, menu item, endpoint, permission or standalone group action.
- Workflow: occasional configuration, small array of registered page IDs (advanced internal form payload, not business user input). Route/permission: `admin.sidebar-access.roles.update` / `roles.manage`.
- Desktop/tablet/mobile/RTL/LTR: same existing responsive Sidebar administration; validation errors use already-authoritative bilingual `sidebar_admin.invalid_item`. No renderer/CSS changes or novel input.

## Acceptance and negative paths

Dedicated `backend/tests/Feature/V2SidebarGroupPermissionBoundaryTest.php` asserts: both AR/EN errors for group-only (plus mixed group + valid page) submissions, no partial role-visibility writes, no audit on failed request, unknown-item rejection, successful registered-item save with audit, role permissions unchanged, no effective group permission, and an HTTP 403 on unauthorized mutation.

Existing `V2SidebarDeadRouteIntegrityTest` covers a group with no authorized children disappearing in Arabic/English, route integrity and a denied page. Existing route middleware remains the access authority. No migrations, ledger/finance mutations, or rollback operations are introduced. Invalid writes are rejected before database mutations; valid writes remain guarded by transaction and audit.

## Mobile application impacts

| Surface | Impact | Rationale |
| --- | --- | --- |
| Laravel Backend/Dashboard | Yes (admin-only POST/PUT validation) | Existing role-management endpoint rejects group keys atomically |
| Customer | No | No mobile API route, payload, deep-link or auth behavior changed |
| Driver | No | No mobile API route, payload, dispatch or auth behavior changed |
| Van | No | No mobile API route, payload, dispatch or auth behavior changed |

## Completion evidence

Focus: `cd backend && php artisan test --filter=V2SidebarGroupPermissionBoundaryTest`. Also run repository-relevant Laravel suite, Pint, PHPStan, Localization Quality and exact-head Required CI with V2 isolation/Repository Policy. GitHub CI run URLs and merge SHA will be recorded on Issue. Do not claim runtime screenshots as tested when no rendered page has changed.

**Do not merge to main, tag, release or deploy to production.**
