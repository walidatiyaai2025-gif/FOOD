# V2 P032 — Granular Action Permission HTTP Contract (#2783)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph `P032`. Parent: #2503. Integration target: **Foodex_V2 only**.

## Delivery and canonical authority

Reuse the existing `User::hasPermission`, Laravel Gate, security management API and permission registry. No new authorization layer, route, menu item or privilege is introduced. A role may have `*.view` without `*.create`, `*.edit`, `*.delete`, `*.approve`, `*.post` or `*.execute`. The security API currently authorizes read using `security.view`, role changes using `roles.manage`, user-role changes using `users.roles.manage`, and activation using `users.status.manage`. These remain separate canonical security actions.

Test `backend/tests/Feature/V2GranularActionPermissionHttpContractTest.php` checks:

- A security read-only actor can list roles and permissions but gets **403** on create/update/clone/delete roles, status changes, or role assignment; stored roles, account state, audit rows and role mappings remain unchanged.
- Role-management permission alone cannot read security records, activate users or assign user roles. Authorized but invalid role creation reaches **422** validation rather than the permission gate.
- A global explicit DENY overrides a role's otherwise-allowed `roles.manage` permission on the real HTTP action, without suppressing independent `security.view`.
- A B2C store-scoped role cannot transform scoped permissions into an unscoped system-security HTTP grant.
- View/Create/Edit/Delete/Approve/Post/Execute abilities are independently evaluated by Laravel Gate against the live seeded permission registry.
- English/LTR and Arabic/RTL Security Center rendering follow the existing authorized route.

All fixtures are synthetic; no production accounts, stores, permission grants or user records are modified by the issue implementation.

## Customer / Driver / Van impact assessment

| Application | Classification | Evidence |
| --- | --- | --- |
| Customer | Not affected | No mobile controller/API contract, login, retail order, cart, entitlement or notification logic changed; tests are limited to administrative security routes and Gate behavior |
| Driver | Not affected | No driver login/session, delivery API, status or dispatch behavior changed; the existing `deliveries.b2c.execute` action remains an independent permission |
| Van | Not affected | No Van API, visit, route, collection or dispatch behavior changed; the existing `deliveries.b2b.execute` action remains independent; B2B Van-only routing unchanged |

No separate mobile implementation issue is created because this PR changes **only an admin authorization test and this evidence document**, and introduces no app-facing contract change.

## Boundaries / remaining acceptance

This regression locks the demonstrated P032 action separation. It does **not** certify every ERP, finance, approval and execution route or their live tenant/module entitlement configurations. Separate finance maker/checker and operational workflow tests remain authoritative; this test does not bypass ERP readiness or impersonate a production account. An exhaustive route-by-route P032 certification, staging/UAT (deferred under #2503) and complete source-to-route mapping should be recorded before declaring the full P032 source requirement closed.

### Required verification

Run the focused Laravel feature test, PHP syntax, Pint, static analysis as applicable, and the exact-head required GitHub workflows: Repository Policy, FOODEX V2 Isolation Gate, Required CI Gate (including Laravel backend suite and MySQL/Redis fresh install/upgrade/recovery). Merge this PR only into `Foodex_V2` with all required jobs successful on the latest head. **No main, production, tags or release promotion.**
