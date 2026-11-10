# FOODEX V2 #2832 / P096 — shared role template vs one user's Override

**Integration:** `Foodex_V2` only. Parent: #2503. Source: Unified RBAC/ERP/Sidebar P096.

## Existing authoritative behavior

This is a regression and evidence change, **not** a second authorization service. The canonical implementation already uses:

- `SecurityController::updateRole` → `RbacManager::updateRole` → mutable **custom Role**'s `role_permission` template (not built-in or Platform Owner roles); updates every current assignee's inherited permissions by their live role and scope.
- `SecurityController::updateUserPermissions` → `RbacManager::replaceUserPermissions` → `user_permission_overrides` by exact `user_id` and `scope_key`. A selected permission omitted relative to the assigned role's baseline becomes **DENY** for that one user; it must never alter the shared role template or peers' effective permissions.
- `User::hasPermission`, `OperationalTenantScope`, role assignment, canonical permission domain, module readiness and runtime HTTP middleware remain authoritative. Sidebar trees only project those results and cannot grant access.
- `AuditLogger` records separately `role.updated` with Role auditable identity, and `user.permissions_changed` with User auditable identity. Exact actor, record and action matter for SoD audits.

## New Laravel acceptance regression

`backend/tests/Feature/V2RoleTemplateVsUserOverrideIsolationTest.php` uses **two separate freshly created roles and paired users**, for Arabic and English sessions, and exercises actual authenticated HTTP mutation routes:

1. A custom global template initially grants `orders.view` to *both* users but not `orders.manage`.
2. Authorized Super Admin PATCH adds `orders.manage` to the shared role; both users inherit it with **no personal Override row**.
3. Authorized PUT of the first user's personal permissions excludes `orders.manage`; only that user obtains a scoped DENY row. The peer retains inherited access; the shared template is unchanged.
4. A subsequent authorized PATCH removes `orders.view` from the shared template. Both assignees lose inherited View, the peer retains Manage, and the first user's Manage DENY remains intact.
5. `role.updated` and `user.permissions_changed` audit records have distinct auditable types and the actual operator. An ordinary B2B_ADMIN's forged direct role/user mutation requests both return **403** without changing role permissions, personal DENY or audit count.

No live identities, customer orders, permission changes or other production data are involved. The test uses `RefreshDatabase` with synthetic seeded identities. No backend production logic is changed.

## Scope and product impacts

- **Backend / Dashboard:** Read-only automated regression of real management HTTP authorization, separate global-role and per-user state, audit and denied mutations. No new Controller, Dashboard widget, permission, route, migration or product setting.
- **Customer:** no change to Customer Flutter, public API payload, identity, price, order or checkout behavior.
- **Driver:** no change to Driver Flutter, tracking, dispatch, delivery status or B2C DRIVER ONLY fulfillment.
- **Van:** no change to Van Flutter, procurement, collection, visits, routing or B2B VAN ONLY fulfillment.

The new focused test complements rather than duplicates `V2UserPermissionInheritModeTest`, which specifically covers per-scope removal of Overrides via INHERIT, and the existing built-in-role clone/protection, store-scoped ALLOW and tenant isolation tests.

## Required gates and outstanding acceptance

Before merging, independently confirm all **exact-head** required CI: Repository Policy, FOODEX V2 Isolation, AR/EN localization, PHP lint/Pint/PHPStan/security/OpenAPI, full Laravel tests including P096, real MySQL/Redis installation/upgrade/recovery, and final `required-ci-gate`. When red, fix only this branch and PR and rerun; do not bypass security.

**Staging still required:** independently test real role and single-user permissions forms, RTL/LTR at mobile/tablet/desktop widths, keyboard/screen-reader feedback, multiple simultaneous scoped roles, store/tenant/channel negative HTTP flows, unavailable modules and finance approval constraints. Those are not proven by this test-only PR; **keep issue #2832 open until acceptance evidence is attached**. No main branch modification, production deployment, release/tag, active branch deletion, or real-user grant mutation.
