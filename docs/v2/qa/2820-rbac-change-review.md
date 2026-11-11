# V2 #2820 / P084 — RBAC change review, SoD and audit

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P084. Parent #2503.

## Placement and authority

- Domain/entity: Administration → Security & Users → exact user → Individual permissions
- Role: an actor holding `users.roles.manage`, constrained by the existing grant-authority validator
- Goal/workflow: review before/after permission choices, state a business justification, submit a server-authorized scope-limited mutation, inspect persisted/audited result
- Placement: **Extend** the existing `/admin/security?section=users` Security Center workflow. No new Sidebar item, standalone page, permission engine or API route
- Pattern: existing Settings/Master Data modal/record editor
- Navigation: ADMIN_SETTINGS under the Administration Hub; existing users section and exact-user manage modal; no direct-URL-only capability
- Desktop/tablet/mobile: use the existing responsive permission picker, wrapped label and minimum 44px existing primary actions
- RTL/LTR: existing Security Center `dir` and localized AR/EN permission change review labels
- Permissions: `security.view` and `users.roles.manage` as existing Gate authorities; any selected access remains subject to role/channel/store/tenant/module/SoD checks in the canonical RbacManager and User authorization
- States: initial inherited/allow/deny/blocked, changed selection impact, server-side validation errors, successful audited mutation; a no-reason request must leave permissions unchanged

## Implemented in this PR

1. Canonical RBAC mutation review now requires an 8–500 character explanation for individual user permission overrides, user role assignments and custom role edits on both Dashboard and security API boundaries. No missing reason can silently change these live grants.
2. Both global and store-scoped permission forms present the same required bilingual reason and a clear **non-approval** SoD warning. The existing permission picker already identifies proposed added/removed permissions and marks privileged permissions before save.
3. The existing RbacManager maintains authority, scope checks and high-risk per-user escalation rejection; it records before/after snapshots plus the provided review reason in `user.permissions_changed`, `user.roles_changed` and `role.updated` AuditLog after-payloads. Enterprise security scope/approval/delegation changes continue through the existing independent maker/checker workflow and `enterprise.security.change_applied` audit, not an alternative UI approval.
4. Existing functional regression tests receive explicit legitimate reasons. Issue-specific tests prove no-reason validation/DB integrity in Dashboard and API, persisted deny and role changes with AuditLog before/after/reason, AR/EN/RTL, and a subsequent real HTTP Security Center request denied immediately after removing the actor's `security.view` role permission (no stale authority).
5. No new authorization grant, role, migration, public endpoint, or dashboard navigation route was created.

## Cross-application impact

- **Customer:** no Flutter or Customer API contract change. An already-assigned customer identity's effective permission checks continue using the canonical server authority; no new mobile grant.
- **Driver:** no Driver API/Flutter change, no dispatch/collection mutation, no B2B fallback.
- **Van:** no Van API/Flutter change; B2B VAN-only and B2C DRIVER-only rules are untouched.

## Remaining P084 scope to complete before closing the Issue

P084 Security Center now covers **individual-permission overrides, role definition updates and user role assignment updates**. Existing per-role/user before–after AuditLog snapshots carry the stated reason. The role-assignment selector already shows dynamic configuration-driven SoD risk-pair warnings, while operational maker/checker remains server-authoritative. User permission resolution queries live DB state; the added HTTP test verifies revoked privileges immediately fail closed on the next authenticated request instead of trusting any cosmetic Sidebar state. Existing Enterprise approval/delegation requests are handled through separately audited, independent maker/checker workflow (#2857 etc); this PR does not bypass or replace that workflow.

**Before declaring complete:** verify exact-head Pint/PHPStan, Laravel tests, AR/EN, V2 Isolation and MySQL/Redis install/upgrade/recovery; fix any test fixtures using obsolete update contract. Confirm no need to invalidate a materialized authorization cache or active session after role changes (or implement it with regression evidence). Do not state that all historic app/device UAT was run.

Do not close #2820 until those remaining source requirements, relevant permission/SoD regression, and all exact final-head Required CI checks are green. Owner-manual/browser/UAT acceptance remains deferred under #2503; **no main, release, tag, staging, production or cPanel deployment**.
