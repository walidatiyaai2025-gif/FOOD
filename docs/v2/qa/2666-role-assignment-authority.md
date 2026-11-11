# V2 #2666 — P336 | تعيين الأدوار / Role assignment

## Source and canonical placement
- Authoritative source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / P336, Administration. Parent #2503. Merge target: `Foodex_V2` only.
- **Reuse**, not a new Sidebar node or parallel permission editor. Canonical workflow: Admin Hub → Security Center → **Users** tab → search for exact user → **Manage** modal → global/store Role selector → Save.
- Navigation: existing authorized Administration/Security entry and `admin.security.index?section=users` (SecurityController@index, `admin/security.blade.php`, `_security-role-assignment-picker.blade.php`); exact-record action is `admin.security.users.roles`, HTTP PUT, `SecurityController@updateUserRoles` → `RbacManager::replaceUserRoles`.
- Security Center read Gate: `security.view`. Role-picker rendering and direct HTTP mutation Gate: **`users.roles.manage`**. Role catalog editing uses separate `roles.manage` and cannot substitute for user assignment.
- Dashboard labels and direction use the actor's AR/EN locale, `dir=rtl/ltr`; existing modal provides role search, translated labels, globally scoped and per-active-Retail-store options, role availability, risk/impact messaging and exact-record form action. No new UI elements introduced.
- Role authorization: `RbacManager` enforces global vs store scope, active Retail store, active role, grantable permissions, Platform Owner immutability, last Super Admin invariants, job-role availability, and audit. Role grants are not implied by sidebar visibility.
- The application architecture intentionally uses no independently managed Sidebar visibility grant for this action.

## Acceptance and negative contracts
| Case | Expected authoritative behavior |
| --- | --- |
| Super Admin opens exact-user Security dialog | AR and EN render the real scoped form; PUT of a grantable global role persists to role_user and writes user.roles_changed audit |
| Security viewer with only security.view | Can read authorized page but does **not** render the role form; direct PUT returns HTTP 403 with no role mutation |
| Roleless actor | Security page and direct PUT both HTTP 403 |
| Wrong scope | Store-only role in global assignment and global-only role in a store: localized validation errors, no role or store assignment/audit |
| Active B2C store assignment | UserStoreRole saved for the selected Retail store only; no role grant in unrelated store and audit is present |
| Backend denied paths | Direct HTTP checks rather than solely checking hidden buttons; no additional permissions or automatic privileges granted |

## Evidence files
- New test: `backend/tests/Feature/V2UserRoleAssignmentNavigationAuthorityTest.php` (five focused integration/negative tests).
- Existing related authoritative tests: `V2UserRoleStoreHttpMatrixTest`, `V2RoleAssignmentSourceAuditTest`, `V2RolePermissionUiToHttpAuthorizationTest`, `V2RoleTreeNodeMetadataTest`.
- Architectural source: `docs/architecture/RBAC_ADMINISTRATION.md`, `AGENTS.md`, `docs/v2/V2_ISOLATION_GOVERNANCE.md`.
- Active peer #2814 / PR #3136 owns live role/scope page-tree **preview**; this PR deliberately does not modify its controller, Blade view, routes, AdminNavigation, SecurityController or permission mutations.
- Pending final exact-head CI and merge evidence are tracked in Issue #2666 and the one issue-scoped PR.

## API / Dashboard / Mobile impact
| Surface | Classification and contract evidence |
| --- | --- |
| Laravel / Dashboard | **Test and QA coverage only** for already implemented Security Center and server-side role assignment. No runtime PHP, routes, migrations, dependencies, UI or env changes. |
| Customer | No change: no Customer Flutter code, customer API, login, profile or customer role/permission runtime mutations. Existing role model unchanged. |
| Driver | No change: no Driver Flutter code, B2C dispatch, assignment, token, status or proof API changes. B2C DRIVER ONLY preserved. |
| Van | No change: no Van Flutter code, B2B routing, visit, inventory/consignment, token or assignment API changes. B2B VAN ONLY preserved. |

No new impacted mobile issue is justified for a test/documentation-only PR; mobile CI is not claimed as run when change detection legitimately skips it. Keep preview-runtime and operational UI evidence separate from this backend HTTP contract.

## Completion checklist
- [x] Read live ownership/worker leases, AGENTS.md, V2 governance, parent #2503, Security Center source and related tests
- [x] Issue Ownership comment and worker:active before creating one branch
- [x] Existing real route and UI reused, no conflicting changes to role preview worker files
- [x] Five targeted Laravel regressions for AR/EN, allow, 403, wrong-scope denial and Store A/B isolation
- [ ] Current issue-branch exact-HEAD successful Repository Policy, V2 Isolation and FOODEX Required CI (full Laravel/security/static/AR-EN/MySQL-Redis)
- [ ] Reviewed one PR, merged into Foodex_V2 only with verified SHA, and closed the completed leaf

Manual browser/staging acceptance is deferred to the final V2 owner gate #2503/#2750 and is **not** a claimed runtime test. No main/tag/release/deploy is permitted.
