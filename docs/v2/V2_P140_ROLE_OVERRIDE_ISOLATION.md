# V2 P140 — Custom role templates vs individual user overrides

Source: FOODEX Unified RBAC ERP Sidebar discussion, paragraph P140. Issue #2862 (parent #2503). Integration is **Foodex_V2 ONLY**; no production or main promotion.

## Authority and behavior

Canonical Dashboard entry: Administration / Security Center -> Roles and User permissions, using the existing protected HTTP routes:

- PATCH /admin/security/roles/{role}: `admin.security.roles.update`; requires `roles.manage`.
- PUT /admin/security/users/{user}/permissions: `admin.security.users.permissions`; existing per-user override authority and validation.
- Scope: `global` Wholesale/B2B custom role. No new permission codes, route, backend service, data columns, UI navigation or mobile endpoints.

The role template changes `permission_role` through the existing `RbacManager::updateRole`. It must not mutate `user_permission_overrides`, rewrite specific user's deny/allow exceptions, or copy one user's override onto other role members. Effective capabilities still depend on the user's active role/scope and must be recalculated under existing server-side checks. Changing the base template may legitimately change inherited permissions for everyone with that role; it is NOT an update to personal override records.

## Automated acceptance

`backend/tests/Feature/V2CustomRoleOverrideIsolationTest.php` uses real Dashboard HTTP requests and a seeded database. It creates a custom global role and two users, places a `deny` override on only one, extends the shared role template, and asserts the denied permission stays denied for that user while the other member inherits role permissions. It verifies the role change audit trail and denies a B2B admin lacking role-template authority by HTTP 403, without changing the role or private override.

Cross-channel/store scope: this test does not change existing B2B/B2C/store authorization; existing `EnterpriseRbacTest` and data-scope suites remain required. No new entitlements, modules, financial operations or maker/checker transitions are introduced. Customer: no API/app change. Driver: no API/app change. Van: no API/app change.

All required exact-head CI must be green and the PR merged **only** into Foodex_V2. Full P140 acceptance additionally requires reviewing role/override projection and scope in the isolated integration branch with the existing security governance suite. Keep #2862 OPEN until integrated branch verification is evidenced, not merely because the PR was created.
