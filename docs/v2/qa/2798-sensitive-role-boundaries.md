# V2 #2798 — Sensitive SUPER_ADMIN / PLATFORM_OWNER boundaries

Source: P046 in \`FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx\`. Parent: #2503. Integration target: \`Foodex_V2\` only.

## Authoritative role and scope

- A registered \`PLATFORM_OWNER\` is established by the internal \`PlatformOwnerProvisioner\` and singleton \`platform_owner_registry\`; an ordinary \`PLATFORM_OWNER\` role assignment never confers that authority.
- \`SUPER_ADMIN\` has broad operational permissions but **cannot** assign the reserved owner role or replace/deactivate the registered owner.
- The owner account's credential recovery and identity binding are **internal-only**. Normal Dashboard RBAC administration, even by a Super Admin or the owner acting via those endpoints, must not reset its MFA, link/unlink its SSO identity or revoke its management session.
- Existing server-side gates remain authoritative. Hiding a button would not satisfy this issue. No role shortcut changes tenant/store/module/entitlement or finance maker/checker constraints.

## HTTP authorization matrix

| Action | Route | Registered owner as target | Ordinary target |
|---|---|---|---|
| Edit permission overrides | \`PUT admin.security.users.permissions\` | 403 | Existing RBAC rules |
| Reset MFA | \`DELETE admin.security.users.mfa.reset\` | 403 | Authoritative \`users.roles.manage\` and audit |
| Link SSO | \`POST admin.security.users.sso.store\` | 403 | Authoritative \`users.roles.manage\` and audit |
| Unlink SSO | \`DELETE admin.security.users.sso.destroy\` | 403 | Authoritative \`users.roles.manage\` and audit |
| Revoke management session | \`DELETE admin.security.sessions.revoke\` | 403 | Authoritative \`users.status.manage\` and audit |
| Change owner role | \`PUT admin.security.users.roles\` | Existing 403 | Existing scope/role gate |
| Change owner status | \`PATCH admin.security.users.status\` | Existing 403 | Existing scope/status gate |
| Grant reserved owner role | \`POST admin.security.users.store\` or \`PUT admin.security.users.roles\` | 403 | Never permitted |

Rejected requests are stopped before the target credential/session/SSO state changes, and before any corresponding success audit event. Genuine owner recovery continues to require a separately authorized internal procedure; this PR does not expose a new web recovery endpoint.

## Executable evidence

\`backend/tests/Feature/V2SensitivePlatformOwnerBoundaryTest.php\` checks:

1. Unregistered \`PLATFORM_OWNER\` role spoofing cannot access privileged Security or System Update routes.
2. A Super Admin cannot assign reserved owner authority, change the registered owner's status, roles or permission overrides; rejected changes are not persisted or recorded as successes.
3. MFA reset, SSO link/unlink and session revocation all reject owner-targeted requests with 403; originals and audit history are preserved.
4. Legitimate Super Admin MFA administration for normal users and legitimate registered-owner account management remain available and audited.

Focused acceptance: \`cd backend && php artisan test --filter=V2SensitivePlatformOwnerBoundaryTest\`. Required formatting and exact-head PR CI must also pass; an unexecuted test is not acceptance evidence.

## Cross-surface and localization impact

- **Dashboard:** existing Security Center endpoints remain discoverable; authorization is enforced server-side. Existing page placement, actions and navigation are unchanged. The new denials use a neutral HTTP 403 without adding hard-coded Arabic/English UI strings.
- **Customer App:** no changed customer API, token contract, permission model or account lifecycle; no Flutter code change.
- **Driver App:** no changed driver API or runtime permission/assignment; no Flutter code change.
- **Van App:** no changed van API or dispatch/assignment; no Flutter code change.
- **Tenant/channel/store:** all affected endpoints are platform management routes under existing authenticated Dashboard middleware; this change adds no B2B/B2C cross-channel authority, store access or entitlement bypass.
- **Data preservation:** no schema change or migration, no destructive update, and no release artifacts. Unauthorized changes return 403 before credential, registry, identity, session or audit mutation.
- **AR/EN and RTL/LTR:** no screen or translation key change; denial is HTTP status-based. Existing bilingual Dashboard views and responsive layouts remain untouched.

**Completion gate:** only after the single Issue #2798 PR passes all required checks at its last commit, is merged into \`Foodex_V2\`, and its merged state is verified. This record is not permission to modify \`main\` or deploy.
