# V2 #2766 / T015R08 — User Permission Overrides: zero-row snapshot RBAC audit

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, audited export table T015R08, parent #2503. **Integration:** `Foodex_V2` only.

## What the historical export does — and does not — establish

The source snapshot reported **no user-specific permission overrides** at the time of export. This is a historical observation, **not** a guarantee about the current database, an instruction to backfill overrides, a justification for granting privileges, or evidence that a roleless account is an administrative account. A user can have inherited permissions through active global roles or store-scoped roles when the overrides table is empty. An empty table never means every account has full rights, or no rights.

The canonical system already has `User::inheritedPermissionCodes()`, `User::effectivePermissionCodes()`, `User::hasPermission()`, `RbacManager::replaceUserPermissions()`, `AdminNavigation::groupsFor()` and server-side route policies. **Reuse** them; do not build another permission resolver, derive security from Sidebar settings, or mutate production role assignments.

## Acceptance evidence / permission-route matrix

| User context (synthetic fixtures only) | Global/store override rows | Canonical authority | Sidebar + direct HTTP |
| --- | --- | --- | --- |
| Active `B2B_ADMIN` | 0 | Inherited `catalog.view`; no `roles.manage` | Catalog link present and Catalog GET 200; Security Center GET 403 |
| Active roleless account | 0 | No inherited/effective permissions | Catalog link absent and direct GET 403 |
| Active Retail `RETAIL_INVENTORY` at Store A | 0 | `inventory.view` on Store A only | Retail inventory visible / Store A GET 200; Store B 403 or 404 |
| Same Retail actor asking for Wholesale inventory | 0 | Retail role cannot authorize B2B route | Wholesale inventory absent / direct GET 403 |
| Active B2B user after a controlled per-user permission change | 1+ for target | Explicit global `catalog.view` DENY overrides inherited role grant | Catalog link suppressed and direct GET 403 |
| Peer with the same B2B role, untouched by target override | 0 | Role baseline unchanged | Catalog link and GET remain permitted |

The three Laravel tests under `backend/tests/Feature/V2NoUserPermissionOverrideSnapshotTest.php` exercise AR/EN with RTL/LTR HTML for authorized routes, check `inheritedPermissionCodes() === effectivePermissionCodes()` when no overrides, and assert actual 403/404 for unauthorized direct paths. A **real managed RBAC mutation** of one target via `RbacManager` must persist a scoped DENY with `users.has_permission_overrides=true`, produce a `user.permissions_changed` audit record, and leave a peer and the shared role's `permission_role` grants unchanged. Tests use synthetic fixture identities; no live or imported historical user is looked up or modified.

## Product placement and isolation

- **Entry point:** Existing Dashboard Administration → Security → Users and Roles; authorized role/user permission editing remains at `admin.security.users.permissions`. No extra UI or route is introduced. Permission changes are server-authorized, not a Sidebar checkbox grant.
- **Backend:** Add regression coverage only; existing `User`, `RbacManager`, routes, store/role scopes, audit behavior and SQL schema are unchanged.
- **Dashboard:** No changed screen, menu, label, layout or route. Existing RBAC mutation is exercised in tests.
- **Customer:** NOT AFFECTED — no Customer Flutter file or API/auth/profile/checkout change. A roleless customer remains non-administrative without an inferred privilege.
- **Driver:** NOT AFFECTED — no Driver Flutter/API/GPS/status change. No B2B/retail driver permission alterations.
- **Van:** NOT AFFECTED — no Van Flutter/API/visits/collections/wholesale dispatch change.
- **Finance/SoD:** No new purchase, accounting, treasury, approval or settlement grants. Existing maker/checker and module/entitlement/readiness controls remain independent. B2B=VAN ONLY, B2C=DRIVER ONLY unchanged.

## Limitations and follow-up acceptance

1. **The historic zero-row assertion cannot be certified against today's live production database** from a source document or a synthetic test. A privacy-safe, authorized comparison of current role assignments, override rows and marker consistency is required in isolated staging; do not copy account identifiers or other PII into the issue/PR.
2. The `has_permission_overrides` marker is part of the current permission calculation and is reconciled by existing RBAC mutation machinery. Real system audit should confirm marker and row consistency; do not infer consistency from a single exported snapshot.
3. This audit covers the missing-overrides scenario, inherited role/store boundary, and one explicit DENY. Broader module readiness, entitlements, account classification, full live accessibility and financial SoD acceptance remain under other V2 issues and the parent #2503.
4. Required CI on exact PR head: Repository Policy, V2 Isolation, AR/EN Localization, PHP syntax/Pint/PHPStan, Composer security, OpenAPI, full Laravel Backend tests, real MySQL/Redis clean-install/upgrade/recovery and final `required-ci-gate`. Path-skipped Flutter jobs are not proof of mobile runtime testing.
5. **Keep #2766 OPEN** after CI and isolated merge until a reviewer supplies current-data safe audit evidence and real-browser AR/EN RTL/LTR responsive/keyboard/accessibility acceptance from V2 staging. Never promote to `main`, tag, publish APK/Setup/Update ZIP or deploy cPanel without explicit owner authorization.
