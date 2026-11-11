# V2 #2662 — Sidebar P332: Users navigation authority

- **Parent / source:** #2503, P332, FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09).
- **Integration target:** `Foodex_V2` only. No `main`, tag, release, deployment, production account mutation or promotion.
- **Placement:** **Reuse** the canonical Administration Hub card and Security Center **Users** tab; no new screen, Sidebar destination, route, policy or permission namespace.
- **Domain / entity / role / goal:** Administration / user identities / authorized security operator / find and manage permitted users.
- **Workflow:** normal admin access review; high-density paginated list with existing search and role-assignment filter.
- **Navigation type:** `DOMAIN_HUB` → `PAGE_TAB`.
- **Canonical entry:** `AdminNavigation::groupsFor()` administration → `admin.administration.index` → Users & Permissions card → `admin.security.index?section=users` (defaults to Users).
- **Renderer / controller:** `administration-hub.blade.php` / `AdministrationHubController`, then `admin.security` / `SecurityController::index`.
- **Authorization:** `Gate::authorize('security.view')` in the destination controller; the hub card only exposes the link when `Gate::forUser($user)->allows('security.view')`. User modifications retain separate per-action permissions such as `users.roles.manage` / `users.status.manage`. Neither page visibility nor route query parameters grant permission.
- **Context:** authentication, effective RBAC and store/channel permissions remain authoritative, with the default Security Center filter/read scope unchanged. Financial, ERP module readiness, SoD, store assignment and approval rules are not altered by this navigation-only regression.
- **Fallback:** existing Security Center back-to-Dashboard navigation; denied route is HTTP 403, not a hidden-only security control.
- **Direction and responsive:** reused existing Hub/Security Center responsive UI, `ar`/RTL and `en`/LTR translations; no new copy or UI markup introduced.
- **Data classification:** existing user records, roles and scoped assignments; no raw internal IDs requested from business operators, no changes to database schema or data.

## Source contract and tested scenarios

`backend/tests/Feature/V2SidebarUsersNavigationAuthorityTest.php` covers the existing hub's discoverability and canonical landing for an authorized Super Admin in Arabic and English; users section server response and localized tab; roleless platform-customer direct GET denied; direct account-creation POST denied with no persisted user; Driver-role direct GET denied.

This scope deliberately does **not** grant `security.view` to additional roles, bypass the role/store/channel/tenant boundary, add a separate sidebar item or interfere with the shared `AdminNavigation.php` / `SecurityController.php` files owned by other lanes. Cross-store account grants, delegated management, explicit denies, inactive roles, maker-checker, audit history and disabled ERP modules retain their existing independent authoritative tests and implementation; this PR does not claim to implement or certify every adjacent P332/P331/RBAC/ERP feature. Existing tab behavior and guards remain source-of-truth.

## Customer / Driver / Van impact assessment

| Surface | Runtime/API change | Assessment |
| --- | --- | --- |
| Backend | None | Focused Laravel tests only; existing Security Center GET and create POST routes preserved |
| Dashboard | None | Existing domain hub, Users tab, translations and authorization exercised; no UI source changes |
| Customer | None | Customer authentication, catalog/ordering and API routes not touched; roleless customer cannot open admin Users |
| Driver | None | No Driver API/app/dispatch change; negative HTTP route test exercises B2C Driver boundary |
| Van | None | No Van API/app/dispatch change; admin Users endpoint remains permission-gated |

## Required verification and closure

Run focused PHPUnit feature test, PHP syntax/Pint/PHPStan/security, repository branch policy, AR/EN quality, V2 Isolation, applicable Laravel full suite and MySQL/Redis acceptance in the Required CI workflow. Record the exact PR head and all terminal required check URLs after they complete. Merge only if latest head is green, PR base remains `Foodex_V2`, no file-ownership collision exists, and no required review remains unsatisfied.

Manual UAT/staging/browser/device acceptance is **DEFERRED_TO_V2_FINAL** under the 2026-11-10 owner decision on #2503. Do not claim runtime UI verification from a source-only test. A signed-off functional end-to-end acceptance must still be recorded on the V2 master before promotion.
