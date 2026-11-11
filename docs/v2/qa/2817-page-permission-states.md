# V2 P081 — Effective page-permission states (Issue #2817)

- **Owner / branch:** `walidatiyaai2025-gif / ChatGPT`; `feat/2817-v2-effective-permission-state-badges`
- **Isolated target:** `Foodex_V2` only. Never promote to `main` or production.
- **Placement:** Reuse and extend the existing Security Center > Users > Manage > Individual user permissions panel. No new page, route, Sidebar entry, or privilege is created.
- **Discoverability:** Existing Security Center is reachable via the Administration Hub only to `security.view` users, while the user permission editor remains guarded by `users.roles.manage`.
- **Permission ownership:** `User::permissionOverrideEffects()`, `inheritedPermissionCodes()` and `effectivePermissionCodes()` remain the only RBAC truth. The page only projects the results for eligible entries.
- **Statuses:** `DENY` for an effective explicit deny (global deny outranks store allow); `ALLOW` for an explicit effective allow; `INHERITED` for an effective role grant without a resolving override; `BLOCKED` when no effective grant applies. Status labels never grant access.
- **Boundaries:** Separate global and per-store maps; changes to store A do not change the selected state in store B absent a global override. The existing server-side 403s, module/entitlement/readiness, SoD and tenant isolation remain authoritative and unchanged. The status note explicitly states RBAC badges are not module-readiness or workflow approval.
- **UI:** Existing form and badge styles, user permission groups and scoped editor. Labels from both `backend/lang/ar/security_permissions.php` and `backend/lang/en/security_permissions.php`; semantic text is visible without relying on color. No new mobile breakpoint.
- **Acceptance tests:** `backend/tests/Feature/V2PagePermissionStatesContractTest.php` covers inherited/explicit allow/explicit deny/blocked in AR/EN, permission state at distinct B2C stores, global-deny precedence and unauthorized Security Center 403; tests verify read-only rendering without changing overrides.
- **App impact:** Customer — none (Dashboard-only presentation, no mobile API/schema); Driver — none (no Flutter or delivery contract touched); Van — none (no Flutter, assignment or dispatch change).
- **Runtime/manual acceptance:** Per master #2503 owner decision, device/browser UAT is deferred to final V2 program gate. This does not waive automated CI and exact final PR SHA checks.

## PR / exact-head CI
Pending PR and latest-head checks; fill with verified GitHub run IDs after CI. Do not claim completion on build alone.
