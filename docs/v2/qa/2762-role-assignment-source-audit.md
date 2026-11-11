# V2 #2762 — T015R04 stored role assignment audit

## Source and placement
- Historical owner-supplied FOODEX unified RBAC/ERP/Sidebar discussion (9 Nov 2026), T015R04: **31 global role assignments and 3 store-level role assignments**.
- This is an historical database-export snapshot. No production database was accessed, and no 31/3 live-data assertion is made.
- Parent #2503 and exclusive implementation target `Foodex_V2`. No main branch, production, tags, releases or deployments.
- Domain: Administration → Users, Roles & Permissions → Roles & Permissions tab. Reuse → **Extend** existing `admin.security.index?section=roles`; parent `admin.administration.index`, permission `security.view`, no new route/menu/tab/API or independent access layer. One Super Admin-only read-only inventory widget in the current responsive Dashboard shell.
- Navigation owner: Administration Hub, navigation type ADMIN_SETTINGS; active context remains the existing Roles & Permissions tab. Desktop/tablet/phone share semantic headings/cards, RTL for Arabic and LTR for English. Empty-state and anomaly warning localized.
- No business user is asked to type a technical ID. No new actions are exposed.

## Current authoritative data
- Global assignments are rows in `role_user`; scoped store assignments are rows in `user_store_roles`; a store-scoped assignment does not confer a global grant.
- The report reads real counts, distinct users with either kind of assignment, number of stores, and per-role/per-store counts (without disclosing per-user identities). Differences are current minus historical 31/3.
- Read-only anomaly checks: store-only roles assigned globally, global-only roles attached to a store, links to inactive or archived roles, and assignments for inactive stores. An anomaly is not an automatic authorization grant, deletion, reconciliation or remediation; it requires a separate scoped, approved, audited action if correction is needed.
- Database foreign keys and immutable historical role codes remain intact. No migration, seeding of target 31/3, or automatic RBAC mutation.
- The full historical totals are only visible to Super Admin after the existing HTTP Gate for `security.view`. Scoped/delegated viewers get no new unrestricted data; unauthorized actors receive 403.
- Every saved assignment is a historical record, not proof of effective access. Actual authorization remains derived from active role, user status, permission ALLOW/DENY, tenant/store/channel, module readiness, financial maker/checker, feature gates and backend route/action checks.

## Coverage and acceptance
- `backend/tests/Feature/V2RoleAssignmentSourceAuditTest.php` checks correct live row counts, distinct store grouping, no data or audit writes on GET, source baseline, scope mismatch detection, inactive store visibility, AR/EN RTL/LTR, unauthorized 403 and delegated non-disclosure.
- Existing Laravel RBAC/tenant/store negative tests remain the source of truth for effective permission enforcement; this feature adds no new API or mutation route.
- Customer App: no change to customer sign-in, store selection, catalog, pricing, checkout, APIs or UI.
- Driver App: no change to B2C fulfillment, dispatch, wallet, job states, API or app code.
- Van App: no change to B2B van-only fulfillment, visits, stock, payment, offline, API or app code. No mobile-impact issue needed for a dashboard-only, Super Admin read-only report.
- CI on the exact final head: Repository Policy, FOODEX V2 Isolation Gate, Required CI (Pint/PHPStan/Laravel/security/AR-EN/MySQL-Redis installation, upgrade and recovery). Recheck PR's target branch and lack of active overlapping ownership before merge.
- Manual end-to-end acceptance is deferred to V2 final under #2503. The historical 34 source record identities cannot be declared individually reconciled without authorized source export: the UI is a current-to-source count audit with correctly scoped aggregation, not fabricated production certification.
