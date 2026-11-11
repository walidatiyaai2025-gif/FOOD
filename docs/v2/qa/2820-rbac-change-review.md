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

1. The canonical individual-permission mutation requires an 8–500 character explanation in the existing Dashboard POST/PATCH boundary; no absent reason can silently mutate effective access.
2. Both global and store-scoped permission forms present the same required bilingual reason and a clear **non-approval** SoD warning. The existing permission picker already identifies proposed added/removed permissions and marks privileged permissions before save.
3. The existing RbacManager maintains authority, scope checks and high-risk per-user escalation rejection; it records before/after snapshots and now additionally stores the provided review reason in the authorized `user.permissions_changed` AuditLog after-payload.
4. Existing functional regression tests receive explicit legitimate reasons; issue-specific tests prove rejection/DB integrity when reason is omitted, successful persisted deny + AuditLog before/after with reason, and AR/EN/RTL labeling.
5. No new authorization grant, role, migration, public endpoint, or dashboard navigation route was created.

## Cross-application impact

- **Customer:** no Flutter or Customer API contract change. An already-assigned customer identity's effective permission checks continue using the canonical server authority; no new mobile grant.
- **Driver:** no Driver API/Flutter change, no dispatch/collection mutation, no B2B fallback.
- **Van:** no Van API/Flutter change; B2B VAN-only and B2C DRIVER-only rules are untouched.

## Remaining P084 scope to complete before closing the Issue

This initial implementation hardens **individual-permission overrides** only. P084's holistic coverage must still explicitly verify or implement change reason and audited before/after review for **role definitions, user role assignments, additional Enterprise approval/delegation mutations**, and prove invalidation of any cached effective-permission snapshot / active privileged session without breaking current requests. Separate maker/checker workflows must remain authoritative. A static UI warning is not proof that every SoD workflow is certified.

Do not close #2820 until those remaining source requirements, relevant permission/SoD regression, and all exact final-head Required CI checks are green. Owner-manual/browser/UAT acceptance remains deferred under #2503; **no main, release, tag, staging, production or cPanel deployment**.
