# V2 #2761 — T015R03 permission and role-permission relationship inventory

## Source, placement and ownership

Historical source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, T015R03 — 146 permission records and 773 permission-to-role links. Parent #2503. Implementation target Foodex_V2 only. The source numbers are a dated baseline, not a release invariant, and no production dataset has been inspected.

Domain: Administration → Security & Users → Roles & Permissions; entity: Permission catalog and permission_role pivot. User goal: an authorized Super Admin audits the current catalog and saved role grants against a historical snapshot. Placement: EXTEND the existing admin.security.index?section=roles route and existing role grid; no new menu item, page, migration, endpoint or authorization engine. Navigation: ADMIN_SETTINGS through the existing Administration Hub; canonical route and controller remain SecurityController@index. Read-only reference table uses the existing responsive table container. AR/EN, RTL/LTR and native semantic headings/table headers retained.

## Real inventory and security semantics

- Permission total comes from the current registered permissions table. Role-link total is the current permission_role pivot row count, including historical retained grants on inactive/archived roles. Each row is one exact stored role/permission relation; duplicates are constrained by the composite primary key.
- Namespace table groups actual current permission codes by the prefix before the first period and counts related pivot rows for those codes. Existing role cards below enumerate the full role-to-permission matrix.
- Active-role link metric joins roles where is_active=true and archived_at is null. The number is not claimed to equal effective grants: platform owner, user ALLOW/DENY overrides, per-store role assignments, tenant/channel isolation, module entitlements and readiness, financial SoD/maker-checker and server-side route/action guards are independent authority.
- Unlinked permission count identifies catalog entries with no saved role link, without inferring that they are unsafe, unused at runtime or available to grant.
- 146 and 773 are compared numerically to current totals with differences shown. The audit writes no permissions, role grants, role statuses, user assignments, audit events, migrations or backfills to force a match.
- Full catalog totals are shown only when the authenticated actor has SUPER_ADMIN and reaches the actual security.view-protected route. Delegated security.view actors do not receive the new inventory data; they continue using their existing delegated permission picker. Direct unauthorized GET fails with 403.
- Source row-by-row comparison against the original historical export and the actual production database remains an authorized external-data audit. Current database figures do not prove identical membership to historical records; do not close the Issue if that source requirement remains open.

## Acceptance, fallback and other surfaces

- Laravel feature regression V2PermissionRoleCatalogInventoryTest uses seeded reference roles, injects a synthetic permission/link, asserts real current database counts, namespace and relationship count, persistence of link, no new audit writes, unauthorized 403, delegated non-disclosure and both AR RTL / EN LTR variants.
- No form or mutation was introduced, so there is no new rollback path; existing RbacManager validation, audit and transaction guards remain unchanged. If a DB read fails, existing global error handling applies instead of returning fabricated zero counts.
- Customer: no API/login/account/order/discount/pricing change, no app changes or new permission gate.
- Driver: no dispatch, proof, session, wallet or API change. B2C DRIVER ONLY unaffected.
- Van: no routing/visits/field sale/assignment/offline/API change. B2B VAN ONLY unaffected.
- No new app-specific Issue is required because no mobile runtime/API contract changed; existing cross-tenant/role/allow-deny tests remain required CI, not replaced by this reporting test.
- Mandatory exact-head CI: Repository Policy, V2 Isolation Gate, Backend Laravel/Pint/PHPStan/security/OpenAPI, AR/EN localization, MySQL/Redis fresh-install/upgrade/recovery and final required-ci-gate.
- Manual UAT/owner/browser/device acceptance is DEFERRED_TO_V2_FINAL under the latest owner comment on #2503, without claiming it occurred.
