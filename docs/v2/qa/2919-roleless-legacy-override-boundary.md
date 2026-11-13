# V2 #2919 — Historical Table 5 row 14: zero-role / legacy override boundary

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native Table 5, data row 14; parent #2503. The export reported no assigned Dashboard RBAC role and zero distinct inherited permissions. This is **historical**, not proof of the current person's account type, active status or entitlement. Never place the exported identifier or sensitive personal details in source code, test output or PR.

## Scope, canonical contract and placement

- Existing authorized **Administration Hub → Security Center → Users** already provides the `assignment=roleless` filter and the separate `is_platform_customer` classification. Reuse this screen, not a new menu, controller or user table. Route: `admin.security.index`; authorization remains the existing global management permission / HTTP guard. Direct unauthorized route is forbidden, not merely hidden.
- Existing `User::hasPermission`, `User::effectivePermissionCodes`, `OperationalTenantScope::allowedStoreIds` and `AdminNavigation::groupsFor` are the runtime sources. A stale explicit `allow` row in `user_permission_overrides` is **not** a role, a tenant assignment or independent proof of permission. Both global and store-specific overrides must be bounded by an active role/channel context; sensitive grants must not be promoted. No change to this canonical logic is needed unless a regression detects a real bypass.
- All tests use synthetic, transient Laravel database fixtures; no direct production account edits, no guessed identity reconciliation, no fake role grant, archive or deletion.

## Distinct #2919 test matrix

File `backend/tests/Feature/V2ExportedRolelessRow14OverrideBoundaryTest.php` intentionally differs from merged #2923 / row 18 ordinary roleless classification checks:

1. **Historic zero inherited role with persisted legacy global and store ALLOW rows:** seeded `users.view`, `inventory.view` ALLOW at global and specific Retail store boundaries. Assert no active global/store role, no inherited or effective ability, no authorized Sidebar, no Wholesale/Retail tenant scope, direct Security Center 403, B2B inventory 403 and B2C inventory 403/404 in both AR and EN. Preserve the account and stale override rows as read-only audit evidence.
2. **Identity classification is independent from unsafe user permission rows:** seed a distinct platform-customer synthetic account with `roles.manage` and `users.delete` legacy global ALLOW but no role. Verify authorized Super Admin sees it through the existing roleless Users filter in AR/EN RTL/LTR; the target still cannot manage roles, delete users, see admin navigation or access Sidebar role editing. No mutation to roles, stores or target active status.

These two tests protect a **separate residual risk** from #2923: a zero-role exported account can still carry historical direct override rows and must not be silently elevated. A raw list of inherited permissions, account type or historical identity is never sufficient to grant access.

## App impact and cross-surface classification

| Surface | Classification | Contract evidence |
| --- | --- | --- |
| Backend / API | TEST ONLY | No route/controller/model/policy/schema or public API change. Direct existing HTTP route denies are exercised. |
| Dashboard | TEST ONLY | Existing Security Center Users/roleless filter, canonical Sidebar projection and AR/EN direction are exercised; no new UI or navigation copy. |
| Customer | NOT AFFECTED | No customer API, authentication, profile, storefront, pricing, Flutter or token code changed. A synthetic `is_platform_customer` flag does not grant administrative RBAC. |
| Driver | NOT AFFECTED | No Driver API, role assignments, delivery/notification or Flutter changes. |
| Van | NOT AFFECTED | No Van routes, consignment/warehouse/dispatch/API or Flutter changes. |

B2B = VAN ONLY and B2C = DRIVER ONLY remain unchanged.

## Acceptance boundary and required evidence

The **current** exported account must still be reconciled by an authorized, least-privilege **read-only** source-of-truth check: identity category (Dashboard/Customer/Driver/Van), active state, current global/store roles, active store and channel, override allow/deny effects, current effective permissions, actual Sidebar and related business records. This evidence must remain access-controlled; a historical zero-role snapshot is not permission to delete/reassign the person or to infer an account defect.

Keep #2919 **OPEN** until that independent current-state check and any required approved remediation are evidenced. Synthetic tests and a passing CI certify only this issue-scoped code slice. Manual staging/device UAT may be deferred to final V2 acceptance per #2503; automated tests and source/permission evidence may **not** be waived.

One branch `test/2919-v2-roleless-legacy-override-boundary` and one PR into `Foodex_V2` only; require exact-head Repository Policy, FOODEX V2 Isolation Gate, full Required CI (Laravel/security/static/l10n and applicable MySQL/Redis acceptance) SUCCESS before integrating. Never merge into `main`, deploy, tag or publish production artifacts under this issue.
